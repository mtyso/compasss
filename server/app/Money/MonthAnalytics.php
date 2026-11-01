<?php

namespace App\Money;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The analytics on the «Деньги» screen (docs/PLAN.md, D41), one month in one request: spending day by day,
 * the previous month day by day to compare the running total with, the last months side by side, when in the
 * week and the day the money goes, the biggest operations and spending by subcategory. Sums in som (D8).
 *
 * The week and the day show habits, so one-offs are left out of them; everything else counts all spending,
 * as the month's total does. Days are counted from the first the app saw (a month it saw whole, from the 1st)
 * up to today, so an average is not pulled down by days before the app or days still ahead.
 */
final class MonthAnalytics
{
    /** Months in the side-by-side chart, this one included. */
    private const MONTHS = 6;

    private const TOP = 5;

    /** @return array<string, mixed> */
    public function for(User $user, string $month, Carbon $now): array
    {
        [$start, $end] = MonthSpending::range($month);
        $zone = config('app.timezone');
        $first = MonthReport::firstOperation($user);
        $over = $now->gte($end);
        $today = $over || $now->lt($start) ? null : $now->copy()->setTimezone($zone)->format('Y-m-d');

        $expenses = $user->transactions()->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->where('type', 'expense')->get(['id', 'occurred_at', 'amount_uzs', 'is_one_off']);
        $local = fn (Transaction $t) => $t->occurred_at->copy()->setTimezone($zone);

        // Every day of the month, zero where nothing was spent.
        $days = [];
        for ($day = $start->copy(); $day->lt($end); $day->addDay()) {
            $days[$day->format('Y-m-d')] = ['date' => $day->format('Y-m-d'), 'expense_uzs' => 0, 'one_off_uzs' => 0, 'count' => 0];
        }
        foreach ($expenses as $t) {
            $date = $local($t)->format('Y-m-d');
            $days[$date]['expense_uzs'] += $t->amount_uzs;
            $days[$date]['one_off_uzs'] += $t->is_one_off ? $t->amount_uzs : 0;
            $days[$date]['count']++;
        }

        // The days that count for an average: from the first the app saw up to today.
        $tracked = MonthReport::tracked($month, $first);
        $from = $tracked || $first === null ? $start->format('Y-m-d') : max($start->format('Y-m-d'), $first->format('Y-m-d'));
        $counted = array_filter(array_keys($days), fn (string $date) => $date >= $from && ($today === null || $date <= $today));

        $regular = $expenses->reject(fn (Transaction $t) => $t->is_one_off);

        $weekdays = [];
        foreach (range(1, 7) as $weekday) {
            $weekdays[$weekday] = ['weekday' => $weekday, 'total_uzs' => 0, 'count' => 0, 'days' => 0];
        }
        foreach ($counted as $date) {
            $weekdays[Carbon::parse($date, $zone)->dayOfWeekIso]['days']++;
        }
        $hours = [];
        foreach (range(0, 23) as $hour) {
            $hours[$hour] = ['hour' => $hour, 'total_uzs' => 0, 'count' => 0];
        }
        foreach ($regular as $t) {
            $at = $local($t);
            $weekdays[$at->dayOfWeekIso]['total_uzs'] += $t->amount_uzs;
            $weekdays[$at->dayOfWeekIso]['count']++;
            $hours[$at->hour]['total_uzs'] += $t->amount_uzs;
            $hours[$at->hour]['count']++;
        }

        $previous = $start->copy()->subMonthNoOverflow()->format('Y-m');
        $prev = $first !== null && $previous >= $first->format('Y-m') ? [
            'month' => $previous,
            'tracked' => MonthReport::tracked($previous, $first),
            'days' => array_map(fn (array $d) => ['date' => $d['date'], 'expense_uzs' => $d['expense_uzs']], MonthSpending::byDay($user, $previous)),
        ] : null;

        return [
            'month' => $month,
            'first_month' => $first?->format('Y-m'),
            'tracked' => $tracked,
            'over' => $over,
            'today' => $today,
            'counted_days' => count($counted),
            'days' => array_values($days),
            'prev' => $prev,
            'months' => $this->months($user, $start, $end, $first),
            'weekdays' => array_values($weekdays),
            'hours' => array_values($hours),
            'subcategories' => $this->subcategories($user, $start, $end),
            'top' => $user->transactions()->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
                ->where('type', 'expense')->orderByDesc('amount_uzs')->orderByDesc('occurred_at')->limit(self::TOP)->get()
                ->map->toApi()->values()->all(),
        ];
    }

    /**
     * This month and up to five before it, oldest first; months before the app's first operation are left out,
     * since an empty bar there would read as a month without spending.
     *
     * @return list<array{month: string, expense_uzs: int, one_off_uzs: int, income_uzs: int}>
     */
    private function months(User $user, Carbon $start, Carbon $end, ?Carbon $first): array
    {
        $months = [];
        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            $month = $start->copy()->subMonthsNoOverflow($i)->format('Y-m');
            if ($i === 0 || ($first !== null && $month >= $first->format('Y-m'))) {
                $months[$month] = ['month' => $month, 'expense_uzs' => 0, 'one_off_uzs' => 0, 'income_uzs' => 0];
            }
        }
        [$from] = MonthSpending::range(array_key_first($months));

        $rows = $user->transactions()->where('occurred_at', '>=', $from)->where('occurred_at', '<', $end)
            ->whereIn('type', ['income', 'expense'])->get(['type', 'occurred_at', 'amount_uzs', 'is_one_off']);
        foreach ($rows as $t) {
            $month = $t->occurred_at->copy()->setTimezone(config('app.timezone'))->format('Y-m');
            if (! isset($months[$month])) {
                continue;
            }
            if ($t->type === 'income') {
                $months[$month]['income_uzs'] += $t->amount_uzs;
            } else {
                $months[$month]['expense_uzs'] += $t->amount_uzs;
                $months[$month]['one_off_uzs'] += $t->is_one_off ? $t->amount_uzs : 0;
            }
        }

        return array_values($months);
    }

    /**
     * Spending by the category it was written under, with its main category, biggest first; an operation
     * written under a main category itself has that category as both.
     *
     * @return list<array{category_id: int, root_id: int, total_uzs: int, count: int}>
     */
    private function subcategories(User $user, Carbon $start, Carbon $end): array
    {
        return $user->transactions()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->where('transactions.type', 'expense')
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->selectRaw('
                transactions.category_id AS category_id,
                COALESCE(categories.parent_id, categories.id) AS root_id,
                SUM(transactions.amount_uzs) AS total,
                COUNT(*) AS count
            ')
            ->groupBy('transactions.category_id', 'root_id')
            ->toBase()
            ->get()
            ->map(fn ($row) => ['category_id' => (int) $row->category_id, 'root_id' => (int) $row->root_id, 'total_uzs' => (int) $row->total, 'count' => (int) $row->count])
            ->sortByDesc('total_uzs')
            ->values()
            ->all();
    }
}
