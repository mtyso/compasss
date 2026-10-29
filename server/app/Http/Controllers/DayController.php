<?php

namespace App\Http\Controllers;

use App\Streaks\MoneyStreaks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** A day as the money streaks see it (docs/PLAN.md, D38). */
class DayController extends Controller
{
    private const BACK_DAYS = 7;

    /** «Трат не было»: a day without spending still counts for the «записываю» streak. Today or a week back. */
    public function noSpending(Request $request, MoneyStreaks $streaks, string $date): JsonResponse
    {
        $value = (bool) $request->validate(['value' => ['required', 'boolean']])['value'];
        $today = now()->startOfDay();
        $day = Carbon::createFromFormat('!Y-m-d', $date);
        if ($day === false || $day->toDateString() !== $date || $day->gt($today) || $day->lt($today->copy()->subDays(self::BACK_DAYS))) {
            throw ValidationException::withMessages(['date' => __('streaks.no_spending_date', ['days' => self::BACK_DAYS])]);
        }

        $user = $request->user();
        $user->dayResults()->updateOrCreate(['date' => $date], ['no_spending' => $value]);

        return response()->json(['date' => $date, 'no_spending' => $value, 'records' => $streaks->records($user, now())]);
    }
}
