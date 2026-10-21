<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Totals of a month in som (D8): income, spending and spending by main category. */
class SummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $start = Carbon::createFromFormat('Y-m', $data['month'], config('app.timezone'))->startOfMonth();

        $month = $request->user()->transactions()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $start->copy()->addMonth())
            ->where('type', '!=', 'transfer');

        $totals = (clone $month)
            ->selectRaw("
                SUM(CASE WHEN type = 'income' THEN amount_uzs ELSE 0 END) AS income,
                SUM(CASE WHEN type = 'expense' THEN amount_uzs ELSE 0 END) AS expense,
                SUM(CASE WHEN type = 'expense' AND is_one_off = 1 THEN amount_uzs ELSE 0 END) AS one_off
            ")
            ->toBase()
            ->first();

        $byCategory = (clone $month)
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->selectRaw('transactions.type, COALESCE(categories.parent_id, categories.id) AS category_id, SUM(transactions.amount_uzs) AS total, COUNT(*) AS count')
            // The whole expression, not its alias: MariaDB's ONLY_FULL_GROUP_BY rejects the alias.
            ->groupByRaw('transactions.type, COALESCE(categories.parent_id, categories.id)')
            ->orderByDesc('total')
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type,
                'category_id' => (int) $row->category_id,
                'total_uzs' => (int) $row->total,
                'count' => (int) $row->count,
            ]);

        return response()->json([
            'month' => $data['month'],
            'income_uzs' => (int) $totals->income,
            'expense_uzs' => (int) $totals->expense,
            'one_off_expense_uzs' => (int) $totals->one_off,
            'by_category' => $byCategory,
        ]);
    }
}
