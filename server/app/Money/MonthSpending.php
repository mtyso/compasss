<?php

namespace App\Money;

use App\Models\User;
use Illuminate\Support\Carbon;

final class MonthSpending
{
    /**
     * Spending of a month by main category, subcategories rolled up.
     *
     * @return array<int, array{total: int, one_off: int, count: int}> main category id => sums in som
     */
    public static function byCategory(User $user, string $month, string $type = 'expense'): array
    {
        [$start, $end] = self::range($month);

        return $user->transactions()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->where('transactions.type', $type)
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->selectRaw('
                COALESCE(categories.parent_id, categories.id) AS root_id,
                SUM(transactions.amount_uzs) AS total,
                SUM(CASE WHEN transactions.is_one_off = 1 THEN transactions.amount_uzs ELSE 0 END) AS one_off,
                COUNT(*) AS count
            ')
            ->groupBy('root_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->root_id => [
                'total' => (int) $row->total,
                'one_off' => (int) $row->one_off,
                'count' => (int) $row->count,
            ]])
            ->all();
    }

    /**
     * Spending of a month day by day, every day of the month listed (zero where nothing was spent).
     * Grouped in PHP rather than with SQL date functions, which SQLite and MariaDB spell differently.
     *
     * @return list<array{date: string, expense_uzs: int, one_off_uzs: int}>
     */
    public static function byDay(User $user, string $month): array
    {
        [$start, $end] = self::range($month);
        $days = [];
        for ($day = $start->copy(); $day->lt($end); $day->addDay()) {
            $days[$day->format('Y-m-d')] = ['date' => $day->format('Y-m-d'), 'expense_uzs' => 0, 'one_off_uzs' => 0];
        }

        $rows = $user->transactions()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->where('type', 'expense')
            ->get(['occurred_at', 'amount_uzs', 'is_one_off']);
        foreach ($rows as $row) {
            $date = $row->occurred_at->copy()->setTimezone(config('app.timezone'))->format('Y-m-d');
            if (! isset($days[$date])) {
                continue;
            }
            $days[$date]['expense_uzs'] += (int) $row->amount_uzs;
            if ($row->is_one_off) {
                $days[$date]['one_off_uzs'] += (int) $row->amount_uzs;
            }
        }

        return array_values($days);
    }

    /** @return array{0: Carbon, 1: Carbon} the first moment of the month and of the next one */
    public static function range(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month, config('app.timezone'))->startOfMonth();

        return [$start, $start->copy()->addMonth()];
    }
}
