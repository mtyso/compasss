<?php

namespace App\Http\Controllers;

use App\Money\MonthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** «Итоги месяца» (docs/PLAN.md, D30): last month by default, or any month up to this one. */
class ReportController extends Controller
{
    public function month(Request $request, MonthReport $report): JsonResponse
    {
        $now = now();
        $data = $request->validate(['month' => ['sometimes', 'date_format:Y-m', 'before_or_equal:'.$now->format('Y-m')]]);
        $month = $data['month'] ?? $now->copy()->subMonthNoOverflow()->format('Y-m');

        return response()->json($report->for($request->user(), $month, $now));
    }
}
