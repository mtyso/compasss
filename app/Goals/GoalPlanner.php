<?php

namespace App\Goals;

use App\Models\Goal;
use App\Models\GoalCheckin;
use App\Models\IncomeSchedule;
use App\Models\Transaction;
use App\Models\User;
use App\Money\Balances;
use App\Money\ExchangeRates;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out every goal's plan for the current pay period (docs/PLAN.md, 2.6):
 *
 *   monthly  = what the deadline needs (the rest over the months left), or the chosen contribution if it is more
 *   planned  = monthly × the payday's share of the month, only the rest of the period for a goal set mid-period
 *   made     = what went into the goal this period from money for spending (or straight from income)
 *   due      = planned − made: set aside before the daily limit is counted (App\Money\Plan)
 *
 * A contribution made this period does not change what the period asks for: «required» is counted from what
 * the goal had when the period began, so «Отложить» moves money without moving the daily limit.
 */
class GoalPlanner
{
    // The fact forecast needs at least this many days of history.
    private const FACT_MIN_DAYS = 28;

    private const FACT_WINDOW_DAYS = 91;

    public function __construct(private ExchangeRates $rates) {}

    public function plan(User $user, Carbon $now): GoalsPlan
    {
        $today = $now->copy()->startOfDay();
        $schedules = $user->incomeSchedules()->where('active', true)->get();
        $weights = $schedules->mapWithKeys(fn (IncomeSchedule $s) => [$s->id => $this->inUzs($s->expected_amount, $s->currency, $now)])->all();
        $period = PayPeriod::at($today, $schedules, $weights);

        // Finished goals go after the ones still being worked on.
        $goals = $user->goals()->whereNull('archived_at')->orderByRaw('done_at is not null')->orderBy('sort')->orderBy('id')->get();
        $balances = $goals->contains(fn (Goal $g) => $g->hasMoney()) ? Balances::forUser($user) : [];
        $spendable = $user->accounts()->where('spendable', true)->where('is_cushion', false)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $states = $goals->map(fn (Goal $goal) => $goal->hasMoney()
            ? $this->moneyState($goal, $balances, $spendable, $period, $now)
            : $this->otherState($goal, $today));

        $mode = $user->setting('goal_mode', 'first') === 'leftover' ? 'leftover' : 'first';

        return new GoalsPlan($period, $states->values(), $mode);
    }

    /** One goal's state, for a screen that shows a single goal. */
    public function state(Goal $goal, Carbon $now): GoalState
    {
        return $this->plan($goal->user, $now)->goal($goal->id)
            ?? ($goal->hasMoney() ? new GoalState($goal) : $this->otherState($goal, $now->copy()->startOfDay()));
    }

    /**
     * @param  array<int, int>  $balances
     * @param  list<int>  $spendable
     */
    private function moneyState(Goal $goal, array $balances, array $spendable, PayPeriod $period, Carbon $now): GoalState
    {
        $today = $now->copy()->startOfDay();
        $currency = $goal->currency ?? 'UZS';
        $rate = $this->rates->rateFor($currency, $now);
        $balance = $goal->account_id ? ($balances[$goal->account_id] ?? 0) : 0;
        $target = (int) $goal->target_amount;
        $saved = $goal->type === 'debt' ? $target + min(0, $balance) : $balance;
        $remaining = max(0, $target - $saved);
        $progress = $target > 0 ? min(1, max(0, $saved / $target)) : 0;
        $ratePercent = $goal->type === 'debt' && $goal->rate !== null ? (float) $goal->rate : null;

        $steps = $this->steps($goal);
        if (! $goal->isActive()) {
            return new GoalState($goal, saved: $saved, remaining: $remaining, rate: $rate, status: 'done', progress: $progress, steps: $steps);
        }

        // What the goal had when the period began: this period's contributions do not lower what it asks for.
        $changeInPeriod = $goal->account_id ? $this->netChange($goal->account_id, $period->start, $now->copy()->addSecond()) : 0;
        $remainingAtStart = max(0, $remaining + $changeInPeriod);

        $deadline = $goal->deadlineDate();
        $required = 0;
        if ($deadline !== null && $remainingAtStart > 0) {
            $required = GoalMath::roundUp(GoalMath::monthlyFor($remainingAtStart, GoalMath::monthsUntil($period->start, $deadline), $ratePercent), $currency);
        }
        $monthly = max($required, (int) $goal->monthly_amount);

        $planned = min($remainingAtStart, GoalMath::roundUp((int) round($monthly * $period->share * $period->restFrom($goal->startDate())), $currency));
        $made = $goal->account_id ? max(0, $this->madeFromSpending($goal->account_id, $spendable, $period->start, $now)) : 0;
        $due = $remaining > 0 ? max(0, min($planned - $made, $remaining)) : 0;

        $factMonthly = $goal->account_id ? $this->factMonthly($goal, $today) : null;
        $planMonths = GoalMath::monthsToReach($remaining, $monthly, $ratePercent);
        $factMonths = $factMonthly !== null ? GoalMath::monthsToReach($remaining, $factMonthly, $ratePercent) : null;

        [$status, $behind] = $this->status($goal, $remaining, $saved, $remainingAtStart, $required, $monthly, $period, $today);

        // What the deadline asks for is counted in pay months, and a month's contribution goes in at its start,
        // so keeping to the plan is on time even when average months from today run a few days past the deadline.
        $forecastPlan = $remaining > 0 && $planMonths !== null ? GoalMath::dateAfter($today, $planMonths) : null;
        if ($forecastPlan !== null && $required > 0 && $deadline !== null && $forecastPlan->gt($deadline)) {
            $forecastPlan = $deadline->copy();
        }

        return new GoalState(
            goal: $goal,
            saved: $saved,
            remaining: $remaining,
            monthlyRequired: $required,
            monthly: $monthly,
            planned: $planned,
            made: $made,
            due: $due,
            dueUzs: $rate === null ? 0 : Money::toUzs($due, $currency, $rate),
            monthlyUzs: $rate === null ? 0 : Money::toUzs($monthly, $currency, $rate),
            rate: $rate,
            forecastPlan: $forecastPlan,
            forecastFact: $remaining > 0 && $factMonths !== null ? GoalMath::dateAfter($today, $factMonths) : null,
            factMonthly: $factMonthly,
            status: $status,
            behind: $behind,
            progress: $progress,
            steps: $steps,
        );
    }

    /** @return array{done: int, total: int} the goal's tasks, done and all */
    private function steps(Goal $goal): array
    {
        $tasks = $goal->tasks()->get(['id', 'done_at']);

        return ['done' => $tasks->whereNotNull('done_at')->count(), 'total' => $tasks->count()];
    }

    /** @return array{0: string, 1: int} the status and how much the goal is behind its line */
    private function status(Goal $goal, int $remaining, int $saved, int $remainingAtStart, int $required, int $monthly, PayPeriod $period, Carbon $today): array
    {
        if ($remaining <= 0) {
            return ['reached', 0];
        }
        $deadline = $goal->deadlineDate();
        if ($deadline === null) {
            return [$monthly > 0 ? 'open' : 'no_plan', 0];
        }
        if ($deadline->lt($today)) {
            return ['late', $remaining];
        }

        // A straight line from what the goal had when it was set to the target on the deadline,
        // measured where the period began: missed contributions show here, this period's are still to come.
        $target = (int) $goal->target_amount;
        $start = $goal->startDate();
        $span = max(1, $start->diffInDays($deadline));
        $elapsed = min($span, max(0, $start->diffInDays($period->start, false)));
        $expected = (int) round($goal->start_amount + ($target - $goal->start_amount) * $elapsed / $span);
        $gap = $expected - ($target - $remainingAtStart);
        $tolerance = max(GoalMath::roundUp(1, $goal->currency ?? 'UZS'), intdiv($target, 100));

        if ($gap > $tolerance) {
            return ['behind', GoalMath::roundUp($gap, $goal->currency ?? 'UZS')];
        }
        if (-$gap >= max($tolerance, intdiv($required, 2)) && $saved > $goal->start_amount) {
            return ['ahead', 0];
        }

        return ['on_track', 0];
    }

    private function otherState(Goal $goal, Carbon $today): GoalState
    {
        $steps = $this->steps($goal);

        $checkins = $goal->checkins()->orderBy('date')->get(['date', 'value']);
        $current = $checkins->last()?->value !== null ? (float) $checkins->last()->value : ($goal->start_value !== null ? (float) $goal->start_value : null);

        $progress = 0.0;
        $forecast = null;
        if ($goal->target_value !== null && $goal->start_value !== null && $current !== null) {
            $span = (float) $goal->target_value - (float) $goal->start_value;
            $progress = $span == 0 ? 1.0 : min(1, max(0, ($current - (float) $goal->start_value) / $span));
            $forecast = $this->trend($goal, $checkins, $current);
        } elseif ($steps['total'] > 0) {
            $progress = $steps['done'] / $steps['total'];
        }

        $status = match (true) {
            ! $goal->isActive() => 'done',
            $goal->deadlineDate()?->lt($today) ?? false => 'late',
            default => 'open',
        };

        return new GoalState($goal, status: $status, progress: $progress, steps: $steps, current: $current, forecastFact: $forecast);
    }

    /**
     * When a measured goal gets to its number at the pace from the first measurement to the last one.
     *
     * @param  Collection<int, GoalCheckin>  $checkins
     */
    private function trend(Goal $goal, Collection $checkins, float $current): ?Carbon
    {
        $first = $checkins->first();
        if ($checkins->count() < 2 && $first?->date === null) {
            return null;
        }
        [$fromDate, $fromValue] = $goal->start_value !== null && $checkins->count() < 2
            ? [$goal->startDate(), (float) $goal->start_value]
            : [Carbon::parse($first->date, config('app.timezone')), (float) $first->value];
        $lastDate = Carbon::parse($checkins->last()->date, config('app.timezone'));
        $days = $fromDate->diffInDays($lastDate);
        $left = (float) $goal->target_value - $current;
        $perDay = $days > 0 ? ($current - $fromValue) / $days : 0;
        if ($left == 0.0 || $perDay == 0.0 || ($left > 0) !== ($perDay > 0)) {
            return null;
        }

        return $lastDate->copy()->addDays((int) ceil($left / $perDay));
    }

    /** Average put into the goal a month, from its account's history; null without four weeks of it. */
    private function factMonthly(Goal $goal, Carbon $today): ?int
    {
        $from = $goal->startDate()->max($today->copy()->subDays(self::FACT_WINDOW_DAYS));
        $days = $from->diffInDays($today->copy()->addDay());
        if ($days < self::FACT_MIN_DAYS) {
            return null;
        }
        $change = $this->netChange($goal->account_id, $from, $today->copy()->addDay());

        return $change > 0 ? (int) round($change / $days * GoalMath::MONTH_DAYS) : 0;
    }

    /** How much an account's balance moved in [from, to), in its own minor units. */
    public function netChange(int $accountId, Carbon $from, Carbon $to): int
    {
        return (int) $this->flows($accountId, $from, $to, ['transfer', 'income', 'expense'])
            ->sum(fn (Transaction $t) => match (true) {
                $t->type === 'transfer' && (int) $t->to_account_id === $accountId => (int) $t->to_amount,
                $t->type === 'income' => (int) $t->amount,
                default => -(int) $t->amount,
            });
    }

    /**
     * Contributions that count against this period's plan: transfers from money for spending into the
     * goal (less any taken back to it) and income paid straight into it.
     *
     * @param  list<int>  $spendable
     */
    private function madeFromSpending(int $accountId, array $spendable, Carbon $from, Carbon $now): int
    {
        return (int) $this->flows($accountId, $from, $now->copy()->addSecond(), ['transfer', 'income'])
            ->sum(fn (Transaction $t) => match (true) {
                $t->type === 'income' => (int) $t->amount,
                (int) $t->to_account_id === $accountId => in_array((int) $t->account_id, $spendable, true) ? (int) $t->to_amount : 0,
                default => in_array((int) $t->to_account_id, $spendable, true) ? -(int) $t->amount : 0,
            });
    }

    /**
     * Operations of an account in [from, to): from it, or transferred into it.
     *
     * @param  list<string>  $types
     * @return Collection<int, Transaction>
     */
    private function flows(int $accountId, Carbon $from, Carbon $to, array $types): Collection
    {
        return Transaction::query()
            ->where(fn ($q) => $q->where('account_id', $accountId)->orWhere(fn ($q) => $q->where('type', 'transfer')->where('to_account_id', $accountId)))
            ->whereIn('type', $types)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->get(['type', 'account_id', 'to_account_id', 'amount', 'to_amount']);
    }

    private function inUzs(int $amount, string $currency, Carbon $now): int
    {
        $rate = $this->rates->rateFor($currency, $now);

        return $rate === null ? 0 : Money::toUzs($amount, $currency, $rate);
    }
}
