<?php

namespace App\Calendar;

use App\Models\Event;
use App\Support\MonthDay;
use App\Support\Repeat;
use Illuminate\Support\Carbon;

/**
 * The days an event happens on within a range. A repeating event steps by its rule (App\Support\Repeat)
 * from its first day, but jumps close to the range first, so a daily event started years ago costs the
 * same as a new one; the jump lands exactly where plain stepping would.
 */
final class Occurrences
{
    /** Steps taken inside a range at most, after the jump. */
    private const GUARD = 1000;

    /**
     * Occurrences that touch [$from, $to]: the start date of each and its last visible day.
     *
     * @return list<array{date: string, end_date: string}>
     */
    public static function of(Event $event, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $span = self::visibleSpan($event);
        $first = $event->startDate();

        if (! $event->repeating()) {
            $end = $first->copy()->addDays($span);

            return $first->lte($to) && $end->gte($from) ? [['date' => $first->toDateString(), 'end_date' => $end->toDateString()]] : [];
        }

        $skip = array_flip($event->skip_dates ?? []);
        $occurrences = [];
        // One starting $span days before the range still reaches into it.
        foreach (self::dates($event->repeat, $first, $from->copy()->subDays($span), $to, $event->untilDate()) as $date) {
            if (! isset($skip[$date->toDateString()])) {
                $occurrences[] = ['date' => $date->toDateString(), 'end_date' => $date->copy()->addDays($span)->toDateString()];
            }
        }

        return $occurrences;
    }

    /** Whether an occurrence of the event starts on $date (Y-m-d); a skipped one does not count. */
    public static function isOccurrence(Event $event, string $date): bool
    {
        $day = Event::day($date);

        foreach (self::of($event, $day, $day) as $occurrence) {
            if ($occurrence['date'] === $date) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dates in [$from, $to] (and not after $until) of the sequence that starts on $first and goes on by
     * $rule, exactly as Repeat::step would walk it from $first.
     *
     * @param  array<string, mixed>  $rule
     * @return list<Carbon>
     */
    public static function dates(array $rule, Carbon $first, Carbon $from, Carbon $to, ?Carbon $until = null): array
    {
        $first = $first->copy()->startOfDay();
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        // Monthly and yearly rules always carry their day when written; one without it keeps the first date's.
        if (in_array($rule['unit'] ?? 'day', ['month', 'year'], true) && ! isset($rule['day'])) {
            $rule['day'] = $first->day;
        }
        $date = self::fastForward($rule, $first, $from->copy()->subDay());

        $dates = [];
        for ($guard = 0; $guard < self::GUARD; $guard++) {
            if ($date->gt($to) || ($until !== null && $date->gt($until))) {
                break;
            }
            if ($date->gte($from)) {
                $dates[] = $date;
            }
            $date = Repeat::step($rule, $date);
        }

        return $dates;
    }

    /**
     * How many days after its start an occurrence is still visible. A timed event ending at 00:00 belongs to
     * the day before (22:00–00:00 is one evening), so its last day is one earlier.
     */
    private static function visibleSpan(Event $event): int
    {
        $span = $event->spanDays();

        return ! $event->allDay() && $event->end_time === '00:00' && $span >= 1 ? $span - 1 : $span;
    }

    /**
     * The last date of the sequence on or before $limit, or $first when the sequence starts after it. Whole
     * cycles of the rule are skipped arithmetically to a date known to be in the sequence a cycle or so
     * before $limit, and a few plain steps finish the way.
     *
     * @param  array<string, mixed>  $rule
     */
    private static function fastForward(array $rule, Carbon $first, Carbon $limit): Carbon
    {
        if ($first->gte($limit)) {
            return $first->copy();
        }
        $every = max(1, (int) ($rule['every'] ?? 1));
        $days = array_values(array_unique(array_map('intval', $rule['days'] ?? [])));
        sort($days);

        $near = match ($rule['unit'] ?? 'day') {
            'week' => $days === []
                ? self::everyNDays($first, $limit, 7 * $every)
                : self::nearWeekly($first, $limit, $every, $days[0]),
            'month' => self::nearMonthly($first, $limit, $every, (int) $rule['day']),
            'year' => self::nearYearly($first, $limit, $every, (int) $rule['day']),
            default => self::everyNDays($first, $limit, $every),
        };
        for ($guard = 0; $guard < 100 && ($next = Repeat::step($rule, $near))->lte($limit); $guard++) {
            $near = $next;
        }

        return $near;
    }

    /** $first plus whole periods of $n days, the last such date on or before $limit. */
    private static function everyNDays(Carbon $first, Carbon $limit, int $n): Carbon
    {
        return $first->copy()->addDays(intdiv(Event::daysBetween($first, $limit), $n) * $n);
    }

    /**
     * A weekly rule with weekdays runs in weeks $every apart counted from the Monday of $first; in every such
     * week after the first, each listed day is an occurrence. Its first listed day, in the week one cycle before
     * the one $limit falls in, is in the sequence and before $limit.
     */
    private static function nearWeekly(Carbon $first, Carbon $limit, int $every, int $firstDay): Carbon
    {
        $monday = $first->copy()->startOfWeek(Carbon::MONDAY);
        $cycles = intdiv(intdiv(Event::daysBetween($monday, $limit), 7), $every) - 1;

        return $cycles >= 1 ? $monday->addWeeks($cycles * $every)->addDays($firstDay - 1) : $first->copy();
    }

    /** The occurrence of a monthly rule one cycle before the month $limit falls in. */
    private static function nearMonthly(Carbon $first, Carbon $limit, int $every, int $day): Carbon
    {
        $months = ($limit->year - $first->year) * 12 + $limit->month - $first->month;
        $cycles = intdiv($months, $every) - 1;
        if ($cycles < 1) {
            return $first->copy();
        }
        $month = $first->copy()->startOfMonth()->addMonths($cycles * $every);

        return MonthDay::in($month->year, $month->month, $day);
    }

    /** The occurrence of a yearly rule one cycle before the year $limit falls in. */
    private static function nearYearly(Carbon $first, Carbon $limit, int $every, int $day): Carbon
    {
        $cycles = intdiv($limit->year - $first->year, $every) - 1;

        return $cycles >= 1 ? MonthDay::in($first->year + $cycles * $every, $first->month, $day) : $first->copy();
    }
}
