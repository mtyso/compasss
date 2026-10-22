<?php

namespace App\Money;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Carbon;

/** A month's budget against what is spent: the pace marker and a forecast per category. */
final class BudgetStatus
{
    /**
     * @param  list<array{category_id: int, limit_uzs: int, spent_uzs: int, one_off_uzs: int, forecast_uzs: int|null}>  $items
     * @param  array<int, array{total: int, one_off: int, count: int}>  $spending  all spending of the month by main category
     */
    private function __construct(
        public readonly string $month,
        public readonly int $daysGone,
        public readonly int $daysInMonth,
        public readonly array $items,
        public readonly array $spending,
    ) {}

    public static function for(User $user, string $month, Carbon $now): self
    {
        self::copyPreviousIfEmpty($user, $month, $now);

        $spending = MonthSpending::byCategory($user, $month);
        [$daysGone, $daysInMonth] = self::daysGone($month, $now);

        $items = $user->budgets()->where('month', $month)->where('limit_uzs', '>', 0)->get()->map(function (Budget $budget) use ($spending, $daysGone, $daysInMonth) {
            $spent = $spending[$budget->category_id] ?? ['total' => 0, 'one_off' => 0];
            // One-off spending happened once; only the regular part is projected to the month end.
            $forecast = $daysGone > 0 && $daysGone < $daysInMonth
                ? $spent['one_off'] + (int) round(($spent['total'] - $spent['one_off']) * $daysInMonth / $daysGone)
                : null;

            return [
                'category_id' => $budget->category_id,
                'limit_uzs' => $budget->limit_uzs,
                'spent_uzs' => $spent['total'],
                'one_off_uzs' => $spent['one_off'],
                'forecast_uzs' => $forecast,
            ];
        })->values()->all();

        return new self($month, $daysGone, $daysInMonth, $items, $spending);
    }

    /** Share of the month gone by: where a category «should be» today on its bar. */
    public function pace(): float
    {
        return $this->daysGone / $this->daysInMonth;
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $items = collect($this->items);

        return [
            'month' => $this->month,
            'pace' => round($this->pace(), 4),
            'items' => $items,
            'limit_uzs' => $items->sum('limit_uzs'),
            'spent_uzs' => $items->sum('spent_uzs'),
            'unbudgeted_uzs' => collect($this->spending)->except($items->pluck('category_id'))->sum('total'),
        ];
    }

    /**
     * A month without a budget of its own starts from the latest earlier one (docs/PLAN.md, section 4).
     * Rows with a zero limit count as set: they mean «no limit» for that category.
     */
    private static function copyPreviousIfEmpty(User $user, string $month, Carbon $now): void
    {
        if ($month < $now->format('Y-m') || $user->budgets()->where('month', $month)->exists()) {
            return;
        }

        $source = $user->budgets()->where('month', '<', $month)->max('month');
        if (! $source) {
            return;
        }

        foreach ($user->budgets()->where('month', $source)->get() as $budget) {
            $user->budgets()->create(['month' => $month, 'category_id' => $budget->category_id, 'limit_uzs' => $budget->limit_uzs]);
        }
    }

    /** @return array{0: int, 1: int} days of the month gone by (today included) and its length */
    private static function daysGone(string $month, Carbon $now): array
    {
        [$start, $end] = MonthSpending::range($month);
        $length = $start->daysInMonth;

        return [match (true) {
            $now->lt($start) => 0,
            $now->gte($end) => $length,
            // Today counts as gone by: on the last day the marker reaches the end of the bar.
            default => $now->day,
        }, $length];
    }
}
