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

    /** @return array{0: Carbon, 1: Carbon} the first moment of the month and of the next one */
    public static function range(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month, config('app.timezone'))->startOfMonth();

        return [$start, $start->copy()->addMonth()];
    }
}
