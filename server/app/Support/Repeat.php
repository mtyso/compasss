<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Repeat rules of tasks: {"unit": "day|week|month|year", "every": N, "days": [ISO weekdays], "day": D}.
 * `days` narrows a weekly rule to some weekdays; `day` is the day of month a monthly rule keeps to,
 * so «every month on the 31st» lands on the last day of shorter months and comes back to the 31st.
 */
final class Repeat
{
    public const UNITS = ['day', 'week', 'month', 'year'];

    /**
     * The next due date after completing a task due on $due: the first occurrence after it, and
     * after today as well, so finishing an overdue daily task does not leave it overdue again.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function nextAfter(array $rule, Carbon $due, Carbon $today): Carbon
    {
        $next = self::step($rule, $due);
        for ($guard = 0; $next->lte($today) && $guard < 1000; $guard++) {
            $next = self::step($rule, $next);
        }

        return $next;
    }

    /** @param  array<string, mixed>  $rule */
    public static function step(array $rule, Carbon $date): Carbon
    {
        $date = $date->copy()->startOfDay();
        $every = max(1, (int) ($rule['every'] ?? 1));

        return match ($rule['unit'] ?? 'day') {
            'week' => self::nextWeekly($date, $every, array_map('intval', $rule['days'] ?? [])),
            'month' => self::addMonths($date, $every, (int) ($rule['day'] ?? $date->day)),
            'year' => $date->addYearsNoOverflow($every),
            default => $date->addDays($every),
        };
    }

    /** @param  list<int>  $days */
    private static function nextWeekly(Carbon $date, int $every, array $days): Carbon
    {
        sort($days);
        if ($days === []) {
            return $date->addWeeks($every);
        }
        foreach ($days as $day) {
            if ($day > $date->dayOfWeekIso) {
                return $date->addDays($day - $date->dayOfWeekIso);
            }
        }
        $week = $date->startOfWeek(Carbon::MONDAY)->addWeeks($every);

        return $week->addDays($days[0] - 1);
    }

    private static function addMonths(Carbon $date, int $every, int $day): Carbon
    {
        $month = $date->copy()->startOfMonth()->addMonths($every);

        return MonthDay::in($month->year, $month->month, $day);
    }
}
