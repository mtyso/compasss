<?php

namespace App\Http\Controllers;

use App\Models\RecurringPayment;
use App\Money\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Everything the «Сегодня» screen needs in one request; tasks and habits join in step 1.6. */
class TodayController extends Controller
{
    public function __invoke(Request $request, Plan $plan): JsonResponse
    {
        $user = $request->user();
        $now = now();
        $today = $now->copy()->startOfDay();
        $limit = $plan->limit($user, $now);
        $income = $plan->nextIncome($user, $today);

        return response()->json([
            'date' => $today->toDateString(),
            'limit' => $limit->toApi(),
            'has_accounts' => $user->accounts()->whereNull('archived_at')->exists(),
            'next_income' => $income?->toApi($today),
            // Overdue ones and those due before the next income, i.e. the ones the limit sets aside.
            'payments' => $plan->unpaidPayments($user)
                ->filter(fn (RecurringPayment $p) => $p->dueDate()->lt($today->copy()->addDays($limit->daysLeft)))
                ->map(fn (RecurringPayment $p) => $p->toApi($today))
                ->values(),
        ]);
    }
}
