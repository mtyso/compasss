<?php

namespace App\Money;

use App\Advisor\AdvisorContext;
use App\Goals\GoalPlanner;
use App\Models\Account;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * «Итоги месяца» (docs/PLAN.md, D30): income and spending, the share of income kept, spending against the
 * usual level and the budget, and how the money goals moved. All sums in som (D8) except a goal's own.
 *
 * «Обычно» is the median over up to three earlier months the app saw from the start, as the advisor counts it
 * (AdvisorContext::historyMonths), one-off spending left out. When those months come to nothing spent, there is
 * no usual level at all (null), as before the first whole month.
 */
final class MonthReport
{
    private const HISTORY_MONTHS = 3;

    // A change against the usual level smaller than this is noise, not news (the app's lib/report.ts too).
    private const MIN_DIFF = 50_000;

    private const MIN_SHARE = 0.15;

    public function __construct(private GoalPlanner $planner, private ExchangeRates $rates) {}

    /** @return array<string, mixed> */
    public function for(User $user, string $month, Carbon $now): array
    {
        [$start, $end] = MonthSpending::range($month);
        $first = $this->firstTracked($user);
        $expense = MonthSpending::byCategory($user, $month);
        $income = MonthSpending::byCategory($user, $month, 'income');
        $incomeUzs = array_sum(array_column($income, 'total'));
        $expenseUzs = array_sum(array_column($expense, 'total'));
        $oneOffUzs = array_sum(array_column($expense, 'one_off'));
        $history = $this->history($month, $first);

        // What each main category and all spending usually come to, without one-offs.
        $usualBy = [];
        $usual = null;
        if ($history !== []) {
            $byMonth = array_map(fn (string $m) => MonthSpending::byCategory($user, $m), $history);
            $categoryIds = array_unique(array_merge(...array_map('array_keys', $byMonth)));
            foreach ($categoryIds as $id) {
                $usualBy[$id] = AdvisorContext::median(array_map(fn (array $rows) => isset($rows[$id]) ? $rows[$id]['total'] - $rows[$id]['one_off'] : 0, $byMonth));
            }
            $usual = AdvisorContext::median(array_map(fn (array $rows) => array_sum(array_column($rows, 'total')) - array_sum(array_column($rows, 'one_off')), $byMonth));
            // Months with nothing spent are no level to measure against: «0 against 2.5 million» says nothing.
            if ($usual === 0) {
                [$usual, $usualBy] = [null, []];
            }
        }

        $limits = collect(BudgetStatus::for($user, $month, $now)->items)->pluck('limit_uzs', 'category_id')->all();
        $categories = [];
        foreach (array_unique([...array_keys($expense), ...array_keys(array_filter($usualBy)), ...array_keys($limits)]) as $id) {
            $categories[] = [
                'category_id' => $id,
                'total_uzs' => $expense[$id]['total'] ?? 0,
                'one_off_uzs' => $expense[$id]['one_off'] ?? 0,
                'usual_uzs' => $usual === null ? null : ($usualBy[$id] ?? 0),
                'limit_uzs' => $limits[$id] ?? null,
            ];
        }
        usort($categories, fn ($a, $b) => [$b['total_uzs'], $b['usual_uzs'] ?? 0, $b['limit_uzs'] ?? 0] <=> [$a['total_uzs'], $a['usual_uzs'] ?? 0, $a['limit_uzs'] ?? 0]);
        $budgeted = array_filter($categories, fn (array $c) => $c['limit_uzs'] !== null);

        $previous = $start->copy()->subMonthNoOverflow()->format('Y-m');
        $prev = $first !== null && $previous >= $first->format('Y-m') ? $this->totals($user, $previous) : null;
        [$goals, $debtsPaid] = $this->goals($user, $start, $end, $now);

        return [
            'month' => $month,
            'first_month' => $first?->format('Y-m'),
            // Seen whole: the app's records start on or before the 5th of it (D30).
            'tracked' => $first !== null && $month >= $this->trackedFrom($first),
            'over' => $now->gte($end),
            // Share of the month gone by: a month still going is compared with the usual level up to today.
            'pace' => $now->gte($end) ? 1 : ($now->lt($start) ? 0 : round($now->day / $start->daysInMonth, 4)),
            'income_uzs' => $incomeUzs,
            'expense_uzs' => $expenseUzs,
            'one_off_uzs' => $oneOffUzs,
            'saved_uzs' => $incomeUzs - $expenseUzs,
            'rate' => self::rate($incomeUzs, $expenseUzs),
            'usual_uzs' => $usual,
            'history' => $history,
            'prev' => $prev === null ? null : ['month' => $previous, ...$prev, 'rate' => self::rate($prev['income_uzs'], $prev['expense_uzs'])],
            'categories' => $categories,
            'budget' => $budgeted === [] ? null : [
                'limit_uzs' => array_sum(array_column($budgeted, 'limit_uzs')),
                'spent_uzs' => array_sum(array_column($budgeted, 'total_uzs')),
                'kept' => count(array_filter($budgeted, fn (array $c) => $c['total_uzs'] <= $c['limit_uzs'])),
                'count' => count($budgeted),
            ],
            'goals' => $goals,
            'goals_added_uzs' => array_sum(array_map(fn (array $g) => $g['type'] === 'debt' ? 0 : $g['added_uzs'], $goals)),
            'debts_paid_uzs' => $debtsPaid,
        ];
    }

    /**
     * Categories clearly above or below their usual level, the biggest change first. A month still going is
     * compared with the usual level up to today (`pace`). One-offs are left out.
     *
     * @param  list<array<string, mixed>>  $categories  the report's categories
     * @return array{up: list<array{category_id: int, regular: int, usual: int, diff: int}>, down: list<array{category_id: int, regular: int, usual: int, diff: int}>}
     */
    public static function movers(array $categories, float $pace = 1, int $limit = 3): array
    {
        $all = collect($categories)
            ->filter(fn (array $c) => $c['usual_uzs'] !== null)
            ->map(function (array $c) use ($pace) {
                $regular = $c['total_uzs'] - $c['one_off_uzs'];
                $usual = $pace >= 1 ? $c['usual_uzs'] : (int) round($c['usual_uzs'] * $pace / 1000) * 1000;

                return ['category_id' => $c['category_id'], 'regular' => $regular, 'usual' => $usual, 'diff' => $regular - $usual];
            })
            ->filter(fn (array $m) => abs($m['diff']) >= max(self::MIN_DIFF, $m['usual'] * self::MIN_SHARE));

        return [
            'up' => $all->where('diff', '>', 0)->sortByDesc('diff')->take($limit)->values()->all(),
            'down' => $all->where('diff', '<', 0)->sortBy('diff')->take($limit)->values()->all(),
        ];
    }

    /** Share of income not spent, in whole percent; null without income. */
    public static function rate(int $income, int $expense): ?int
    {
        return $income > 0 ? (int) round(($income - $expense) * 100 / $income) : null;
    }

    /**
     * Every money goal that was on during the month: what went in (paid, for a debt) and the progress before
     * and after. A debt counts only what was paid into it, not money borrowed on top.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} the goals and all paid on debts in som
     */
    private function goals(User $user, Carbon $start, Carbon $end, Carbon $now): array
    {
        $until = $end->lt($now) ? $end : $now->copy()->addSecond();
        $at = $end->lt($now) ? $end->copy()->subSecond() : $now;
        $balances = Balances::forUser($user);
        $debtsPaid = $user->accounts()->where('type', 'debt')->get()
            ->sum(fn (Account $a) => $this->inUzs($this->paidInto($a->id, $start, $until), $a->currency, $at));

        $goals = $user->goals()->whereNull('archived_at')->whereIn('type', Goal::MONEY_TYPES)->whereNotNull('account_id')
            ->whereDate('started_on', '<', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('done_at')->orWhere('done_at', '>=', $start))
            ->orderBy('sort')->orderBy('id')->get()
            ->map(function (Goal $goal) use ($balances, $start, $end, $until, $at) {
                $target = (int) $goal->target_amount;
                $balance = ($balances[$goal->account_id] ?? 0) - $this->planner->netChange($goal->account_id, $until, Carbon::create(9999));
                $debt = $goal->type === 'debt';
                $savedEnd = $debt ? $target + min(0, $balance) : $balance;
                $added = $debt ? $this->paidInto($goal->account_id, $start, $until) : $this->planner->netChange($goal->account_id, $start, $until);
                $progress = fn (int $saved) => $target > 0 ? round(min(1, max(0, $saved / $target)), 4) : 0;
                $currency = $goal->currency ?? 'UZS';

                return [
                    'id' => $goal->id,
                    'title' => $goal->title,
                    'type' => $goal->type,
                    'icon' => $goal->icon,
                    'currency' => $currency,
                    'target' => $target,
                    'added' => $added,
                    'added_uzs' => $this->inUzs($added, $currency, $at),
                    'saved' => $savedEnd,
                    'progress_from' => $progress($savedEnd - $added),
                    'progress_to' => $progress($savedEnd),
                    'done' => $goal->done_at !== null && $goal->done_at->lt($end) || ($target > 0 && $savedEnd >= $target),
                ];
            })
            ->values()->all();

        return [$goals, (int) $debtsPaid];
    }

    /** Money paid into a debt: transfers to it and income on it, not what was borrowed out of it. */
    private function paidInto(int $accountId, Carbon $from, Carbon $to): int
    {
        $base = Transaction::query()->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to);

        return (int) (clone $base)->where('type', 'transfer')->where('to_account_id', $accountId)->sum('to_amount')
            + (int) (clone $base)->where('type', 'income')->where('account_id', $accountId)->sum('amount');
    }

    /** @return array{income_uzs: int, expense_uzs: int} */
    private function totals(User $user, string $month): array
    {
        [$start, $end] = MonthSpending::range($month);
        $rows = $user->transactions()->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->whereIn('type', ['income', 'expense'])->selectRaw('type, SUM(amount_uzs) AS total')->groupBy('type')->pluck('total', 'type');

        return ['income_uzs' => (int) ($rows['income'] ?? 0), 'expense_uzs' => (int) ($rows['expense'] ?? 0)];
    }

    /** The first operation, or null when there is none yet. */
    private function firstTracked(User $user): ?Carbon
    {
        $first = $user->transactions()->min('occurred_at');

        return $first ? Carbon::parse($first, config('app.timezone')) : null;
    }

    /** The first month the app saw from the start: that of the first operation if it came by the 5th. */
    private function trackedFrom(Carbon $first): string
    {
        return ($first->day <= 5 ? $first->copy()->startOfMonth() : $first->copy()->startOfMonth()->addMonth())->format('Y-m');
    }

    /** @return list<string> up to three months before this one that the app saw whole, newest first */
    private function history(string $month, ?Carbon $first): array
    {
        if ($first === null) {
            return [];
        }
        $from = $this->trackedFrom($first);
        $months = [];
        $cursor = Carbon::createFromFormat('Y-m', $month, config('app.timezone'))->startOfMonth();
        for ($i = 1; $i <= self::HISTORY_MONTHS; $i++) {
            $candidate = $cursor->copy()->subMonthsNoOverflow($i)->format('Y-m');
            if ($candidate < $from) {
                break;
            }
            $months[] = $candidate;
        }

        return $months;
    }

    private function inUzs(int $amount, string $currency, Carbon $at): int
    {
        $rate = $this->rates->rateFor($currency, $at);

        return $rate === null ? 0 : Money::toUzs($amount, $currency, $rate);
    }
}
