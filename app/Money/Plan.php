<?php

namespace App\Money;

use App\Advisor\DailyLimit;
use App\Goals\GoalPlanner;
use App\Goals\GoalsPlan;
use App\Models\Account;
use App\Models\IncomeSchedule;
use App\Models\RecurringPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Gathers what the daily limit needs from the database and hands it to App\Advisor\DailyLimit. */
class Plan
{
    public function __construct(private ExchangeRates $rates, private GoalPlanner $goals) {}

    /** @param  GoalsPlan|null  $goals  the goals' plan when the caller already has it */
    public function limit(User $user, Carbon $now, ?GoalsPlan $goals = null): DailyLimit
    {
        $today = $now->copy()->startOfDay();
        $spendable = $this->spendableAccounts($user);

        return new DailyLimit(
            today: $today,
            spendableUzs: $this->spendableUzs($user, $spendable, $now),
            // Only everyday spending eats into today's allowance. A paid recurring payment was already set
            // aside, and a one-off purchase comes out of the free money, lowering the limit from now on.
            spentTodayUzs: (int) $user->transactions()
                ->where('type', 'expense')
                ->where('is_one_off', false)
                ->whereNull('recurring_payment_id')
                ->whereIn('account_id', $spendable->pluck('id'))
                ->where('occurred_at', '>=', $today)
                ->where('occurred_at', '<', $today->copy()->addDay())
                ->sum('amount_uzs'),
            payments: $this->unpaidPayments($user)
                ->map(fn (RecurringPayment $p) => ['due' => $p->dueDate(), 'amount_uzs' => $this->inUzs($p->amount, $p->currency, $now)])
                ->all(),
            nextIncome: $this->nextIncome($user, $today)?->nextDate($today),
            minBalanceUzs: (int) $user->setting('min_balance_uzs', 0),
            // This period's contributions to goals come first (docs/PLAN.md, D25).
            plannedSavingsUzs: ($goals ?? $this->goals->plan($user, $now))->reservedUzs(),
        );
    }

    public function nextIncome(User $user, Carbon $today): ?IncomeSchedule
    {
        return $user->incomeSchedules()->where('active', true)->get()
            ->sortBy(fn (IncomeSchedule $s) => $s->nextDate($today)->timestamp)
            ->first();
    }

    /** @return Collection<int, RecurringPayment> active payments by due date, overdue first */
    public function unpaidPayments(User $user): Collection
    {
        return $user->recurringPayments()->where('active', true)->orderBy('next_due_on')->get();
    }

    /** @return Collection<int, Account> */
    private function spendableAccounts(User $user): Collection
    {
        return $user->accounts()->whereNull('archived_at')->where('spendable', true)->where('is_cushion', false)->get();
    }

    /** @param  Collection<int, Account>  $accounts */
    private function spendableUzs(User $user, Collection $accounts, Carbon $now): int
    {
        $balances = Balances::forUser($user);

        return $accounts->sum(fn (Account $a) => $this->inUzs($balances[$a->id] ?? 0, $a->currency, $now));
    }

    private function inUzs(int $amount, string $currency, Carbon $now): int
    {
        // Left out only before the very first rate of the currency is stored (see ExchangeRates::rateFor).
        $rate = $this->rates->rateFor($currency, $now);

        return $rate === null ? 0 : Money::toUzs($amount, $currency, $rate);
    }
}
