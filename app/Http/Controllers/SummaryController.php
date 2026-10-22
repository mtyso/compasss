<?php

namespace App\Http\Controllers;

use App\Money\MonthSpending;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Totals of a month in som (D8): income, spending and spending by main category. */
class SummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $user = $request->user();

        $rows = [];
        foreach (['expense', 'income'] as $type) {
            foreach (MonthSpending::byCategory($user, $data['month'], $type) as $categoryId => $sums) {
                $rows[] = ['type' => $type, 'category_id' => $categoryId, 'total_uzs' => $sums['total'], 'one_off_uzs' => $sums['one_off'], 'count' => $sums['count']];
            }
        }
        usort($rows, fn ($a, $b) => $b['total_uzs'] <=> $a['total_uzs']);

        $sum = fn (string $type, string $field) => array_sum(array_column(array_filter($rows, fn ($r) => $r['type'] === $type), $field));

        return response()->json([
            'month' => $data['month'],
            'income_uzs' => $sum('income', 'total_uzs'),
            'expense_uzs' => $sum('expense', 'total_uzs'),
            'one_off_expense_uzs' => $sum('expense', 'one_off_uzs'),
            'by_category' => array_map(fn ($r) => array_diff_key($r, ['one_off_uzs' => 0]), $rows),
        ]);
    }
}
