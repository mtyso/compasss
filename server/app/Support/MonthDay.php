<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Dates given as «day N of every month», where 31 means the last day of a shorter month. */
final class MonthDay
{
    public static function in(int $year, int $month, int $day): Carbon
    {
        $first = Carbon::create($year, $month, 1, 0, 0, 0, config('app.timezone'));

        return $first->day(min($day, $first->daysInMonth));
    }

    /** The first occurrence of $day on or after $from (or strictly after it). */
    public static function next(int $day, Carbon $from, bool $strictlyAfter = false): Carbon
    {
        $from = $from->copy()->startOfDay();
        $candidate = self::in($from->year, $from->month, $day);

        if ($candidate->lt($from) || ($strictlyAfter && $candidate->eq($from))) {
            $next = $from->copy()->startOfMonth()->addMonth();
            $candidate = self::in($next->year, $next->month, $day);
        }

        return $candidate;
    }

    /** The same day of the following month, e.g. the due date after paying. */
    public static function nextMonth(Carbon $date, int $day): Carbon
    {
        $next = $date->copy()->startOfMonth()->addMonth();

        return self::in($next->year, $next->month, $day);
    }
}
