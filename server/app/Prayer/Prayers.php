<?php

namespace App\Prayer;

use App\Models\HabitPause;
use App\Models\PrayerLog;
use App\Models\User;
use App\Streaks\StreakCounter;
use Illuminate\Support\Carbon;

/**
 * A user's five daily prayers (docs/PLAN.md, D40): each one's time, from its start to the next one's (Fajr
 * until sunrise, Isha until the next Fajr), what was marked, the streak and the prayers missed.
 *
 * Marking starts the count: the first day with a mark is not judged, every day after it is. A day keeps the
 * streak when all five were prayed in their time; a prayer made up later (qaza) no longer counts as missed,
 * but does not mend the streak. There are no spare days here, and days in a habit pause count neither way.
 */
final class Prayers
{
    /** The reminder «до конца времени» comes this long before the end. */
    public const LAST_CALL_MINUTES = 20;

    /** How the bot reminds (setting `prayer_remind`): at the start, also before the end if not marked, only then, or not. */
    public const REMIND = ['start', 'start_end', 'end', 'off'];

    public const REMIND_DEFAULT = 'start_end';

    /** Prayers missed this far back are listed to make up. */
    public const MISSED_DAYS = 30;

    /** The year map: 53 weeks. */
    private const HISTORY_DAYS = 371;

    /** Whether the user keeps prayers in Компас (setting `prayer_on`, off until turned on). */
    public static function on(User $user): bool
    {
        return filter_var($user->setting('prayer_on'), FILTER_VALIDATE_BOOL);
    }

    /** @return array{slug: string, name: string, offset: int, lat: float, lon: float} */
    public static function city(User $user, ?string $locale = null): array
    {
        return Cities::get($user->setting('prayer_city'), $locale ?? $user->locale ?? 'ru');
    }

    public static function remindMode(User $user): string
    {
        $mode = $user->setting('prayer_remind');

        return in_array($mode, self::REMIND, true) ? $mode : self::REMIND_DEFAULT;
    }

    /**
     * Each prayer's time on a date: when it starts and ends, the end to aim for (`until`: for Isha the middle of
     * the night, where its preferred time ends, though its time lasts until Fajr) and the last call before it.
     *
     * @return array<string, array{start: Carbon, end: Carbon, until: Carbon, last_call: Carbon}>
     */
    public static function windows(User $user, string $date): array
    {
        $offset = self::city($user)['offset'];
        $times = PrayerTimes::for($date, $offset);
        $next = PrayerTimes::for(self::addDays($date, 1), $offset);
        $midnight = Carbon::parse($date, config('app.timezone'))->startOfDay();
        $at = fn (int $minutes) => $midnight->copy()->addMinutes($minutes);
        $ends = ['fajr' => $times['sunrise'], 'dhuhr' => $times['asr'], 'asr' => $times['maghrib'], 'maghrib' => $times['isha'], 'isha' => 1440 + $next['fajr']];

        $windows = [];
        foreach (PrayerTimes::NAMES as $name) {
            $until = $at($name === 'isha' ? $times['midnight'] : $ends[$name]);
            $windows[$name] = [
                'start' => $at($times[$name]),
                'end' => $at($ends[$name]),
                'until' => $until,
                'last_call' => $until->copy()->subMinutes(self::LAST_CALL_MINUTES),
            ];
        }

        return $windows;
    }

    /** The day's times for the screen, including sunrise and the middle of the night: name => «05:32». @return array<string, string> */
    public static function clocks(User $user, string $date): array
    {
        return array_map(fn (int $m) => PrayerTimes::clock($m), PrayerTimes::for($date, self::city($user)['offset']));
    }

    /** on_time or qaza by prayer. @return array<string, string> */
    public function marks(User $user, string $date): array
    {
        return PrayerLog::where('user_id', $user->id)->where('date', $date)->pluck('status', 'prayer')->all();
    }

    public function mark(User $user, string $date, string $prayer, string $status): void
    {
        PrayerLog::updateOrCreate(['user_id' => $user->id, 'date' => $date, 'prayer' => $prayer], ['status' => $status]);
    }

    public function unmark(User $user, string $date, string $prayer): void
    {
        PrayerLog::where('user_id', $user->id)->where('date', $date)->where('prayer', $prayer)->delete();
    }

    /** A mark made while the prayer's time lasts is in time, one made later is qaza unless the user says otherwise. */
    public static function statusAt(User $user, string $date, string $prayer, Carbon $now): string
    {
        return $now->lt(self::windows($user, $date)[$prayer]['end']) ? 'on_time' : 'qaza';
    }

    /** The first day with a mark: it is not judged, the days after it are. Null before any mark. */
    public function since(User $user): ?string
    {
        $first = PrayerLog::where('user_id', $user->id)->min('date');

        return $first === null ? null : substr((string) $first, 0, 10);
    }

    /**
     * One day's prayers for the screen. `state`: done, qaza, now (its time is on and it is not marked), later,
     * missed (its time is over, not marked) or skipped (over, before marking started).
     *
     * @return list<array{name: string, start: string, end: string, state: string}>
     */
    public function day(User $user, string $date, Carbon $now): array
    {
        $marks = $this->marks($user, $date);
        $since = $this->since($user);
        $judged = $since !== null && $date > $since;

        $prayers = [];
        foreach (self::windows($user, $date) as $name => $window) {
            $prayers[] = [
                'name' => $name,
                'start' => $window['start']->format('H:i'),
                'end' => $window['end']->format('H:i'),
                'state' => match (true) {
                    isset($marks[$name]) => $marks[$name] === 'qaza' ? 'qaza' : 'done',
                    $now->lt($window['start']) => 'later',
                    $now->lt($window['end']) => 'now',
                    $judged => 'missed',
                    default => 'skipped',
                },
            ];
        }

        return $prayers;
    }

    /**
     * The prayer whose time is on (yesterday's Isha until Fajr) and the next one to start.
     *
     * @return array{current: array{name: string, date: string, end: string, marked: bool}|null, next: array{name: string, date: string, start: string, in_minutes: int}}
     */
    public function moment(User $user, Carbon $now): array
    {
        $today = $now->copy()->timezone(config('app.timezone'))->toDateString();
        $current = $next = null;
        foreach ([self::addDays($today, -1), $today, self::addDays($today, 1)] as $date) {
            foreach (self::windows($user, $date) as $name => $window) {
                if ($current === null && $now->gte($window['start']) && $now->lt($window['end'])) {
                    $current = ['name' => $name, 'date' => $date, 'end' => $window['end']->format('H:i'), 'marked' => isset($this->marks($user, $date)[$name])];
                }
                if ($next === null && $window['start']->gt($now)) {
                    $next = ['name' => $name, 'date' => $date, 'start' => $window['start']->format('H:i'), 'in_minutes' => (int) ceil($now->diffInSeconds($window['start']) / 60)];
                }
            }
        }

        return ['current' => $current, 'next' => $next];
    }

    /**
     * Prayers of the last 30 days whose time is over and that were not marked, newest first: the ones to make up.
     *
     * @return list<array{date: string, name: string}>
     */
    public function missed(User $user, Carbon $now): array
    {
        $since = $this->since($user);
        if ($since === null) {
            return [];
        }
        $today = $now->copy()->timezone(config('app.timezone'))->toDateString();
        $from = max(self::addDays($since, 1), self::addDays($today, -self::MISSED_DAYS));
        $marked = PrayerLog::where('user_id', $user->id)->where('date', '>=', $from)->get()
            ->mapWithKeys(fn (PrayerLog $log) => [substr((string) $log->date, 0, 10).' '.$log->prayer => true])->all();

        $missed = [];
        for ($date = $today; $date >= $from; $date = self::addDays($date, -1)) {
            foreach (array_reverse(self::windows($user, $date), true) as $name => $window) {
                if ($now->gte($window['end']) && ! isset($marked["{$date} {$name}"])) {
                    $missed[] = ['date' => $date, 'name' => $name];
                }
            }
        }

        return $missed;
    }

    /**
     * The streak of days with all five prayed in their time, the year by days and the numbers.
     *
     * @return array{streak: array{current: int, best: int, previous_best: int, unit: string, spare: int, next: int|null}, run: array{current: int, previous: int, unit: string, kept: list<string>}, days: list<array{date: string, value: int, qaza: int, state: string}>, stats: array{rate: float|null, kept_days: int, prayed: int}}
     */
    public function history(User $user, Carbon $now): array
    {
        $today = $now->copy()->timezone(config('app.timezone'))->toDateString();
        $since = $this->since($user);
        $from = self::addDays($today, -self::HISTORY_DAYS + 1);

        $logs = PrayerLog::where('user_id', $user->id)->where('date', '>=', $since ?? $today)->where('date', '<=', $today)->get();
        $onTime = $qaza = [];
        foreach ($logs as $log) {
            $date = substr((string) $log->date, 0, 10);
            $log->status === 'qaza' ? $qaza[$date] = ($qaza[$date] ?? 0) + 1 : $onTime[$date] = ($onTime[$date] ?? 0) + 1;
        }
        $paused = $this->pausedDays($user, $since ?? $today, $today);
        $windows = self::windows($user, $today);

        // From the first mark on, every day: the streak looks at all of them, the map at the last 53 weeks.
        $periods = $dates = $days = [];
        for ($date = $since ?? $today; $date <= $today; $date = self::addDays($date, 1)) {
            $kept = ($onTime[$date] ?? 0) >= count(PrayerTimes::NAMES);
            $state = match (true) {
                $kept => StreakCounter::KEPT,
                $date === $since, isset($paused[$date]) => StreakCounter::PAUSED,
                // Today stays open while every prayer missing from it can still be prayed in its time.
                $date === $today => $this->todayLost($windows, $this->marks($user, $today), $now) ? StreakCounter::MISSED : StreakCounter::OPEN,
                default => StreakCounter::MISSED,
            };
            $periods[] = $state;
            $dates[] = $date;
            if ($date >= $from) {
                $days[] = ['date' => $date, 'value' => $onTime[$date] ?? 0, 'qaza' => $qaza[$date] ?? 0, 'state' => $state];
            }
        }
        $count = StreakCounter::count($since === null ? [] : $periods, PHP_INT_MAX, 0);

        // How often all five were in time over the last 30 judged days.
        $recent = array_slice(array_filter($days, fn (array $d) => in_array($d['state'], [StreakCounter::KEPT, StreakCounter::MISSED], true)), -30);
        $judged = count($recent);

        return [
            'streak' => [
                'current' => $count['current'],
                'best' => $count['best'],
                'previous_best' => $count['previous'],
                'unit' => 'day',
                'spare' => 0,
                'next' => StreakCounter::nextMilestone($count['current'], 'day'),
            ],
            'run' => [
                'current' => $count['current'],
                'previous' => $count['previous'],
                'unit' => 'day',
                'kept' => StreakCounter::keptDates($periods, $dates, $count['start']),
            ],
            'days' => $days,
            'stats' => [
                'rate' => $judged === 0 ? null : round(count(array_filter($recent, fn (array $d) => $d['state'] === StreakCounter::KEPT)) / $judged, 3),
                'kept_days' => count(array_filter($periods, fn (string $s) => $s === StreakCounter::KEPT)),
                'prayed' => array_sum($onTime) + array_sum($qaza),
            ],
        ];
    }

    /** Some prayer of today is past its time without being prayed in it: the day can no longer keep the streak. */
    private function todayLost(array $windows, array $marks, Carbon $now): bool
    {
        foreach ($windows as $name => $window) {
            if (($marks[$name] ?? null) !== 'on_time' && $now->gte($window['end'])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private function pausedDays(User $user, string $from, string $to): array
    {
        $days = [];
        $pauses = HabitPause::where('user_id', $user->id)->where('ends_on', '>=', $from)->where('starts_on', '<=', $to)->get();
        foreach ($pauses as $pause) {
            for ($date = max($from, substr((string) $pause->starts_on, 0, 10)); $date <= min($to, substr((string) $pause->ends_on, 0, 10)); $date = self::addDays($date, 1)) {
                $days[$date] = true;
            }
        }

        return $days;
    }

    private static function addDays(string $date, int $days): string
    {
        return Carbon::parse($date, config('app.timezone'))->addDays($days)->toDateString();
    }
}
