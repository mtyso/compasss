<?php

namespace App\Advisor;

use Illuminate\Support\Carbon;

/**
 * How much can be spent today (docs/PLAN.md, 2.1):
 *
 *   free        = money for spending − payments due before the next income − planned savings − minimum balance
 *   daily limit = free at the start of today / days until the next income
 *
 * Today's everyday spending is added back to get «free at the start of today», so the
 * limit stays the same all day and what is left of it goes down with each expense.
 * Paid recurring payments and one-off purchases are not part of it (see App\Money\Plan).
 * Pure: everything comes in through the constructor, nothing is read from the database.
 */
final class DailyLimit
{
    public readonly int $freeUzs;

    public readonly int $daysLeft;

    public readonly int $limitUzs;

    public readonly int $leftTodayUzs;

    public readonly int $upcomingUzs;

    public readonly bool $estimatedEnd;

    /**
     * @param  list<array{due: Carbon, amount_uzs: int}>  $payments  unpaid recurring payments, overdue ones included
     * @param  Carbon|null  $nextIncome  null when no paydays are set: the month end is used instead
     */
    public function __construct(
        public readonly Carbon $today,
        public readonly int $spendableUzs,
        public readonly int $spentTodayUzs,
        array $payments,
        public readonly ?Carbon $nextIncome,
        public readonly int $minBalanceUzs = 0,
        public readonly int $plannedSavingsUzs = 0,
    ) {
        $today = $today->copy()->startOfDay();
        $end = $nextIncome?->copy()->startOfDay() ?? $today->copy()->endOfMonth()->startOfDay()->addDay();
        $this->estimatedEnd = $nextIncome === null;

        // Days to live through, today included and payday excluded.
        $this->daysLeft = max(1, (int) $today->diffInDays($end));

        $this->upcomingUzs = array_sum(array_map(
            fn (array $p) => $p['due']->lt($end) ? $p['amount_uzs'] : 0,
            $payments,
        ));

        $this->freeUzs = $spendableUzs - $this->upcomingUzs - $plannedSavingsUzs - $minBalanceUzs;

        $freeAtDayStart = $this->freeUzs + $spentTodayUzs;
        $this->limitUzs = intdiv(max(0, $freeAtDayStart), $this->daysLeft);
        $this->leftTodayUzs = $this->limitUzs - $spentTodayUzs;
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'limit_uzs' => $this->limitUzs,
            'spent_today_uzs' => $this->spentTodayUzs,
            'left_today_uzs' => $this->leftTodayUzs,
            'free_uzs' => $this->freeUzs,
            'spendable_uzs' => $this->spendableUzs,
            'upcoming_payments_uzs' => $this->upcomingUzs,
            'min_balance_uzs' => $this->minBalanceUzs,
            'planned_savings_uzs' => $this->plannedSavingsUzs,
            'days_left' => $this->daysLeft,
            'until' => ($this->nextIncome ?? $this->today->copy()->endOfMonth()->addDay())->toDateString(),
            'estimated_end' => $this->estimatedEnd,
        ];
    }
}
