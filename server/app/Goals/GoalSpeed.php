<?php

namespace App\Goals;

use App\Advisor\AdvisorContext;
use App\Models\Category;
use App\Models\Habit;
use App\Models\RecurringPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * «Как быстрее» on a goal's screen (docs/PLAN.md, 2.6): the numbers the contribution slider needs to show the
 * goal's date and what is left to live on a day, and the levers that could free money for it. The app turns
 * each lever's extra money a month into «на N дней раньше» with the same arithmetic (app/src/lib/goals.ts).
 */
class GoalSpeed
{
    // A flexible category cut that is worth suggesting
    private const CUT_SHARE = 0.3;

    private const MIN_EXTRA_UZS = 50_000;

    /** @return array<string, mixed> */
    public function for(User $user, GoalsPlan $plan, GoalState $state, Carbon $now): array
    {
        $context = new AdvisorContext($user, $now, 'check');
        $goal = $state->goal;
        $currency = $goal->currency ?? 'UZS';
        $rate = $state->rate ?? Money::rate(1);

        $income = $context->expectedIncomeUzs() ?: $this->typicalIncome($context);
        $payments = (int) $user->recurringPayments()->where('active', true)->get()
            ->sum(fn (RecurringPayment $p) => $context->inUzs($p->amount, $p->currency));
        $others = $plan->monthlyUzs() - ($state->collecting() ? $state->monthlyUzs : 0);
        $essential = $context->typicalMonthly(essentialOnly: true);

        $levers = [];
        if ($state->collecting()) {
            $levers = [
                ...$this->categoryCuts($context, $currency, $rate),
                ...$this->leftover($context, $plan, $currency, $rate),
                ...$this->habits($user, $goal->id, $currency, $rate),
                ...$this->debtExtra($state),
            ];
        }

        return [
            'monthly_income_uzs' => $income,
            'monthly_payments_uzs' => $payments,
            'other_goals_uzs' => max(0, $others),
            'essential_uzs' => $essential,
            'levers' => $levers,
        ];
    }

    /** Median income a month over the months the app saw in full, when no paydays are set. */
    private function typicalIncome(AdvisorContext $context): int
    {
        $incomes = array_map(fn (string $m) => $context->monthTotals($m)['income'], $context->historyMonths());

        return $incomes === [] ? 0 : AdvisorContext::median($incomes);
    }

    /** @return list<array<string, mixed>> the three biggest flexible categories, a third less on each */
    private function categoryCuts(AdvisorContext $context, string $currency, string $rate): array
    {
        $months = $context->historyMonths();
        if ($months === []) {
            return [];
        }
        $byRoot = [];
        foreach ($months as $i => $month) {
            $start = Carbon::createFromFormat('Y-m', $month, config('app.timezone'))->startOfMonth();
            foreach ($context->expensesBetween($start, $start->copy()->addMonth()) as $t) {
                $root = $context->rootOf($t->category_id);
                if ($root === null || $root->necessity !== 'flexible' || ! AdvisorContext::isEveryday($t)) {
                    continue;
                }
                $byRoot[$root->id] ??= array_fill(0, count($months), 0);
                $byRoot[$root->id][$i] += $t->amount_uzs;
            }
        }
        $typical = array_map(fn (array $totals) => AdvisorContext::median($totals), $byRoot);
        arsort($typical);

        $levers = [];
        foreach (array_slice($typical, 0, 3, true) as $categoryId => $monthly) {
            $extra = (int) (floor($monthly * self::CUT_SHARE / 10_000) * 10_000);
            if ($extra >= self::MIN_EXTRA_UZS) {
                $levers[] = $this->lever('category_cut', $extra, $currency, $rate, [
                    'category_id' => $categoryId,
                    'typical_uzs' => $monthly,
                    'percent' => (int) (self::CUT_SHARE * 100),
                ]);
            }
        }

        return $levers;
    }

    /** @return list<array<string, mixed>> what is usually left of a month's income after spending and the goals' plan */
    private function leftover(AdvisorContext $context, GoalsPlan $plan, string $currency, string $rate): array
    {
        $months = $context->historyMonths();
        if ($months === []) {
            return [];
        }
        $surplus = AdvisorContext::median(array_map(function (string $month) use ($context) {
            $totals = $context->monthTotals($month);

            return $totals['income'] - $totals['expense'];
        }, $months)) - $plan->monthlyUzs();
        $extra = (int) (floor($surplus / 10_000) * 10_000);

        return $extra >= self::MIN_EXTRA_UZS * 2 ? [$this->lever('leftover', $extra, $currency, $rate)] : [];
    }

    /** @return list<array<string, mixed>> money habits not working for another goal: what their kept days save a month */
    private function habits(User $user, int $goalId, string $currency, string $rate): array
    {
        return $user->habits()->whereNull('archived_at')->where('saves_uzs', '>', 0)
            ->where(fn ($q) => $q->whereNull('goal_id')->orWhere('goal_id', $goalId))
            ->orderBy('sort')->get()
            ->map(function (Habit $habit) use ($currency, $rate) {
                $perWeek = $habit->perWeek() ?? count($habit->schedule['days'] ?? []);
                $extra = (int) (floor($habit->saves_uzs * $perWeek * 52 / 12 / 10_000) * 10_000);

                return $extra >= self::MIN_EXTRA_UZS
                    ? $this->lever('habit', $extra, $currency, $rate, ['habit_id' => $habit->id, 'name' => $habit->name, 'per_week' => $perWeek, 'saves_uzs' => $habit->saves_uzs])
                    : null;
            })
            ->filter()->values()->all();
    }

    /** @return list<array<string, mixed>> a quarter more on a debt's payment, and the interest it saves */
    private function debtExtra(GoalState $state): array
    {
        $goal = $state->goal;
        if ($goal->type !== 'debt' || $state->monthly <= 0) {
            return [];
        }
        $extra = GoalMath::roundUp((int) ($state->monthly * 0.25), $goal->currency);
        $saves = null;
        if ($goal->rate !== null && (float) $goal->rate > 0) {
            $before = GoalMath::interest($state->remaining, $state->monthly, (float) $goal->rate);
            $after = GoalMath::interest($state->remaining, $state->monthly + $extra, (float) $goal->rate);
            $saves = $before !== null && $after !== null ? $before - $after : null;
        }

        return [[
            'key' => 'debt_extra',
            'extra' => $extra,
            'extra_uzs' => $state->rate === null ? 0 : Money::toUzs($extra, $goal->currency, $state->rate),
            'interest_saved' => $saves,
        ]];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function lever(string $key, int $extraUzs, string $currency, string $rate, array $params = []): array
    {
        return ['key' => $key, 'extra' => GoalMath::roundUp(Money::fromUzs($extraUzs, $currency, $rate), $currency), 'extra_uzs' => $extraUzs, ...$params];
    }
}
