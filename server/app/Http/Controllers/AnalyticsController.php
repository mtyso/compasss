<?php

namespace App\Http\Controllers;

use App\Money\MonthAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The «Деньги» screen's charts for a month up to this one (docs/PLAN.md, D41). */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request, MonthAnalytics $analytics): JsonResponse
    {
        $now = now();
        $data = $request->validate(['month' => ['required', 'date_format:Y-m', 'before_or_equal:'.$now->format('Y-m')]]);

        return response()->json($analytics->for($request->user(), $data['month'], $now));
    }
}
