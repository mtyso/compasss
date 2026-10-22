<?php

namespace App\Goals;

use App\Models\IncomeSchedule;
use App\Support\MonthDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The stretch of days a contribution to goals belongs to (docs/PLAN.md, D25): from the last payday to the
 * next one, or the calendar month without paydays. Each payday carries a share of the monthly contributions,
 * as big as its share of the expected income: a salary of 12 mln and an advance of 4 mln take 75% and 25%.
 */
final class PayPeriod
{
    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly float $share,
        public readonly bool $byIncome,
    ) {}

    /** @param  Collection<int, IncomeSchedule>  $schedules  active ones; $weights  their expected income in som by id */
    public static function at(Carbon $today, Collection $schedules, array $weights = []): self
    {
        $today = $today->copy()->startOfDay();
        if ($schedules->isEmpty()) {
            return new self($today->copy()->startOfMonth(), $today->copy()->startOfMonth()->addMonth(), 1.0, false);
        }

        $starts = $schedules->mapWithKeys(fn (IncomeSchedule $s) => [$s->id => MonthDay::previous($s->day_of_month, $today)]);
        $start = $starts->max();
        $end = $schedules->map(fn (IncomeSchedule $s) => $s->nextDate($today))->min();

        $total = array_sum(array_map(fn ($w) => max(0, $w), $weights));
        $opening = $starts->filter(fn (Carbon $d) => $d->eq($start))->keys();
        $share = $total > 0
            ? $opening->sum(fn ($id) => max(0, $weights[$id] ?? 0)) / $total
            : $opening->count() / $schedules->count();

        return new self($start->copy(), $end->copy(), $share, true);
    }

    public function days(): int
    {
        return max(1, (int) $this->start->diffInDays($this->end));
    }

    /** Part of the period still ahead from $day (that day included), for a goal set in the middle of it. */
    public function restFrom(Carbon $day): float
    {
        $day = $day->copy()->startOfDay();
        if ($day->lte($this->start)) {
            return 1.0;
        }
        if ($day->gte($this->end)) {
            return 0.0;
        }

        return $day->diffInDays($this->end) / $this->days();
    }
}
