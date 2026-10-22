<?php

namespace App\Goals;

use Illuminate\Support\Carbon;

/**
 * The arithmetic of goals, shared with app/src/lib/goals.ts: how long a sum takes at a monthly
 * contribution, what a deadline asks for a month, and a debt with interest paid month by month.
 * Months are average ones (30.4375 days), so dates come out the same in the app and here.
 */
final class GoalMath
{
    public const MONTH_DAYS = 30.4375;

    /**
     * Months until $remaining is collected at $monthly a month. With a yearly interest rate the remaining is a
     * debt that keeps growing: null when the payment does not even cover the interest, or when nothing is put in.
     */
    public static function monthsToReach(int $remaining, int $monthly, ?float $ratePercent = null): ?float
    {
        if ($remaining <= 0) {
            return 0.0;
        }
        if ($monthly <= 0) {
            return null;
        }
        $r = ($ratePercent ?? 0) / 1200;
        if ($r <= 0) {
            return $remaining / $monthly;
        }
        if ($r * $remaining >= $monthly) {
            return null;
        }

        return -log(1 - $r * $remaining / $monthly) / log(1 + $r);
    }

    /** What has to go in a month to collect $remaining in $months (an annuity payment for a debt with interest). */
    public static function monthlyFor(int $remaining, int $months, ?float $ratePercent = null): int
    {
        if ($remaining <= 0) {
            return 0;
        }
        $months = max(1, $months);
        $r = ($ratePercent ?? 0) / 1200;
        if ($r <= 0) {
            return (int) ceil($remaining / $months);
        }

        return (int) ceil($r * $remaining / (1 - (1 + $r) ** -$months));
    }

    /** Interest paid on a debt until it is gone, at $monthly a month; null when it never is. */
    public static function interest(int $remaining, int $monthly, float $ratePercent): ?int
    {
        $months = self::monthsToReach($remaining, $monthly, $ratePercent);
        if ($months === null) {
            return null;
        }
        $r = $ratePercent / 1200;
        $paid = 0;
        $left = $remaining;
        for ($i = 0; $i < 600 && $left > 0; $i++) {
            $left += (int) round($left * $r);
            $pay = min($monthly, $left);
            $paid += $pay;
            $left -= $pay;
        }

        return max(0, $paid - $remaining);
    }

    /** Whole months from one day to a deadline, the month the deadline falls in not counted: at least 1. */
    public static function monthsUntil(Carbon $from, Carbon $deadline): int
    {
        return max(1, (int) ceil($from->copy()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false) / self::MONTH_DAYS));
    }

    /** The day a goal is reached $months from $from. */
    public static function dateAfter(Carbon $from, float $months): Carbon
    {
        return $from->copy()->startOfDay()->addDays((int) ceil($months * self::MONTH_DAYS));
    }

    /** Rounds a contribution up to a step that reads well: 1 000 som, or a whole dollar. */
    public static function roundUp(int $amount, string $currency): int
    {
        $step = $currency === 'UZS' ? 1_000 : 100;

        return (int) (ceil($amount / $step) * $step);
    }
}
