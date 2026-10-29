<?php

namespace App\Habits;

use App\Models\Habit;
use App\Models\HabitPause;
use App\Streaks\StreakCounter;
use Illuminate\Support\Carbon;

/**
 * Where a habit stands today: the value logged, the last seven days, the streaks and the history.
 *
 * A streak counts kept scheduled days in a row (or kept weeks, for «N раз в неделю»). Today, or this
 * week, only adds to it once kept and never breaks it while there is still time; days off the
 * schedule are skipped. A limit habit is kept on a day nothing was logged, and is broken today as
 * soon as it goes over. Every 7 kept days (4 kept weeks) earn a spare that covers one miss, and days
 * in a pause count neither way unless the habit was kept anyway (docs/PLAN.md, D37).
 */
final class HabitProgress
{
    /** How far back streaks look, unless the whole history is asked for. */
    private const HORIZON_DAYS = 400;

    /** @var array{periods: list<array{date: string, state: string}>, count: array{current: int, best: int, previous: int, spare: int, spared: list<int>, start: int|null}}|null */
    private ?array $counted = null;

    /**
     * @param  array<string, int>  $values  logged value by date
     * @param  array<string, true>  $paused  dates inside a pause
     */
    private function __construct(
        private readonly Habit $habit,
        private readonly Carbon $today,
        private readonly Carbon $from,
        private readonly array $values,
        private readonly array $paused,
    ) {}

    public static function for(Habit $habit, Carbon $today, bool $wholeHistory = false): self
    {
        $today = $today->copy()->startOfDay();
        $from = $wholeHistory ? $habit->startDate() : $habit->startDate()->max($today->copy()->subDays(self::HORIZON_DAYS));
        $values = $habit->logs()
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $today->toDateString())
            ->pluck('value', 'date')
            ->mapWithKeys(fn ($value, $date) => [substr((string) $date, 0, 10) => (int) $value])
            ->all();

        // This week's later days too: a pause ahead lowers what the week asks for.
        $weekEnd = $today->copy()->startOfWeek(Carbon::MONDAY)->addDays(6);
        $paused = [];
        $pauses = HabitPause::where('user_id', $habit->user_id)
            ->where('ends_on', '>=', $from->toDateString())
            ->where('starts_on', '<=', $weekEnd->toDateString())
            ->get();
        foreach ($pauses as $pause) {
            $day = Carbon::parse($pause->starts_on, config('app.timezone'))->max($from->copy());
            $end = Carbon::parse($pause->ends_on, config('app.timezone'))->min($weekEnd->copy());
            for (; $day->lte($end); $day->addDay()) {
                $paused[$day->toDateString()] = true;
            }
        }

        return new self($habit, $today, $from->copy(), $values, $paused);
    }

    public function value(Carbon $day): int
    {
        return $this->values[$day->toDateString()] ?? 0;
    }

    public function isPaused(Carbon $day): bool
    {
        return isset($this->paused[$day->toDateString()]);
    }

    /** done, open (still to do today), over (a limit broken today) or off (not scheduled today, or paused). */
    public function stateToday(): string
    {
        $value = $this->value($this->today);
        if ($this->habit->type === 'limit') {
            return $this->habit->keeps($value) ? 'done' : 'over';
        }
        if ($this->habit->keeps($value)) {
            return 'done';
        }
        if ($this->isPaused($this->today)) {
            return 'off';
        }
        if ($this->habit->perWeek() !== null) {
            return $this->weekCount($this->today) >= $this->weekNeed($this->today) ? 'off' : 'open';
        }

        return $this->habit->isScheduledOn($this->today) ? 'open' : 'off';
    }

    /** @return array{current: int, best: int, previous_best: int, unit: string, spare: int, next: int|null} */
    public function streaks(): array
    {
        $count = $this->counted()['count'];
        $unit = $this->unit();

        return [
            'current' => $count['current'],
            'best' => $count['best'],
            // The longest run before this one: passing it is a new record
            'previous_best' => $count['previous'],
            'unit' => $unit,
            'spare' => $count['spare'],
            'next' => StreakCounter::nextMilestone($count['current'], $unit),
        ];
    }

    /**
     * The current run for the records journal: its length, the longest run before it and the day each of its
     * periods was kept (a week: its Sunday, or today while it goes on).
     *
     * @return array{current: int, previous: int, unit: string, kept: list<string>}
     */
    public function run(): array
    {
        ['periods' => $periods, 'count' => $count] = $this->counted();
        $unit = $this->unit();
        $dates = array_map(fn (array $p) => $unit === 'day'
            ? $p['date']
            : Carbon::parse($p['date'], config('app.timezone'))->addDays(6)->min($this->today->copy())->toDateString(), $periods);

        return [
            'current' => $count['current'],
            'previous' => $count['previous'],
            'unit' => $unit,
            'kept' => StreakCounter::keptDates(array_column($periods, 'state'), $dates, $count['start']),
        ];
    }

    /**
     * How the week from $monday went, for the week summary: times done and times asked. A daily habit counts its
     * scheduled days (a day a spare covered is still a miss here), a weekly one what the week asked for.
     *
     * @return array{done: int, asked: int}
     */
    public function week(Carbon $monday): array
    {
        $monday = $monday->copy()->startOfDay();
        if ($this->habit->perWeek() !== null) {
            $sunday = $monday->copy()->addDays(6);
            if ($sunday->lt($this->from) || $monday->gt($this->today)) {
                return ['done' => 0, 'asked' => 0];
            }
            $asked = $this->weekNeed($monday);
            $done = min($asked, $this->weekCount($monday));
            // The first, partial week asks nothing unless it was kept anyway, as in the streak.
            if ($monday->lt($this->from) && $done < $asked) {
                return ['done' => 0, 'asked' => 0];
            }

            return ['done' => $done, 'asked' => $asked];
        }
        $from = $monday->toDateString();
        $to = $monday->copy()->addDays(6)->toDateString();
        $done = $asked = 0;
        foreach ($this->counted()['periods'] as $period) {
            if ($period['date'] < $from || $period['date'] > $to) {
                continue;
            }
            $done += $period['state'] === StreakCounter::KEPT ? 1 : 0;
            $asked += in_array($period['state'], [StreakCounter::KEPT, StreakCounter::MISSED], true) ? 1 : 0;
        }

        return ['done' => $done, 'asked' => $asked];
    }

    private function unit(): string
    {
        return $this->habit->perWeek() === null ? 'day' : 'week';
    }

    /**
     * The periods the streak is counted over (scheduled days, or weeks from Monday) and the count.
     *
     * @return array{periods: list<array{date: string, state: string}>, count: array{current: int, best: int, previous: int, spare: int, spared: list<int>, start: int|null}}
     */
    private function counted(): array
    {
        if ($this->counted === null) {
            $perWeek = $this->habit->perWeek();
            $periods = $perWeek === null ? $this->dayPeriods() : $this->weekPeriods($perWeek);
            $count = $perWeek === null
                ? StreakCounter::count(array_column($periods, 'state'), StreakCounter::SPARE_EVERY_DAYS, StreakCounter::SPARE_MAX_DAYS)
                : StreakCounter::count(array_column($periods, 'state'), StreakCounter::SPARE_EVERY_WEEKS, StreakCounter::SPARE_MAX_WEEKS);
            $this->counted = ['periods' => $periods, 'count' => $count];
        }

        return $this->counted;
    }

    /** @return list<array{date: string, state: string}> scheduled days from the start to today */
    private function dayPeriods(): array
    {
        $periods = [];
        for ($day = $this->from->copy(); $day->lte($this->today); $day->addDay()) {
            if (! $this->habit->isScheduledOn($day)) {
                continue;
            }
            $value = $this->value($day);
            $kept = $this->habit->keeps($value);
            $state = match (true) {
                // Kept in a pause still counts; a limit with nothing logged in one says nothing.
                $this->isPaused($day) => $kept && $value > 0 ? StreakCounter::KEPT : StreakCounter::PAUSED,
                $kept => StreakCounter::KEPT,
                $day->eq($this->today) && $this->habit->type !== 'limit' => StreakCounter::OPEN,
                default => StreakCounter::MISSED,
            };
            $periods[] = ['date' => $day->toDateString(), 'state' => $state];
        }

        return $periods;
    }

    /**
     * Weeks from the start to this one. A week is kept once it has as many days as it asks for; this
     * week, and the first (partial) week, stay open while not kept.
     *
     * @return list<array{date: string, state: string}>
     */
    private function weekPeriods(int $perWeek): array
    {
        $periods = [];
        $thisWeek = $this->today->copy()->startOfWeek(Carbon::MONDAY);
        for ($week = $this->from->copy()->startOfWeek(Carbon::MONDAY); $week->lte($thisWeek); $week->addWeek()) {
            $need = $this->weekNeed($week);
            $count = $this->weekCount($week);
            $state = match (true) {
                $count >= ($need > 0 ? $need : $perWeek) => StreakCounter::KEPT,
                $need === 0 || $week->lt($this->from) => StreakCounter::PAUSED,
                $week->eq($thisWeek) => StreakCounter::OPEN,
                default => StreakCounter::MISSED,
            };
            $periods[] = ['date' => $week->toDateString(), 'state' => $state];
        }

        return $periods;
    }

    /**
     * Times the week of $day asks for: «N раз в неделю», and in step with the free days when a pause
     * takes some: three times a week with two free days asks once.
     */
    private function weekNeed(Carbon $day): int
    {
        $start = $day->copy()->startOfWeek(Carbon::MONDAY);
        $free = 0;
        for ($d = $start->copy(); $d->lte($start->copy()->addDays(6)); $d->addDay()) {
            $free += $this->isPaused($d) ? 0 : 1;
        }

        return intdiv((int) $this->habit->perWeek() * $free + 6, 7);
    }

    /** Days kept in the week of $day, up to today. */
    private function weekCount(Carbon $day): int
    {
        $count = 0;
        $end = $day->copy()->startOfWeek(Carbon::MONDAY)->addDays(6)->min($this->today->copy());
        for ($d = $day->copy()->startOfWeek(Carbon::MONDAY)->max($this->from->copy()); $d->lte($end); $d->addDay()) {
            $count += $this->doneOn($d) ? 1 : 0;
        }

        return $count;
    }

    /** Something was logged that day and it keeps the habit. */
    private function doneOn(Carbon $day): bool
    {
        $value = $this->value($day);

        return $value > 0 && $this->habit->keeps($value);
    }

    /**
     * Each day's state for the history map: kept, missed, spared (a spare covered it), paused, open
     * (today, still to do), off (not scheduled) or none (before the start).
     *
     * @return array<string, string>
     */
    private function dayStates(): array
    {
        $states = [];
        if ($this->habit->perWeek() === null) {
            ['periods' => $periods, 'count' => $count] = $this->counted();
            $spared = array_flip($count['spared']);
            foreach ($periods as $index => $period) {
                $states[$period['date']] = isset($spared[$index]) ? 'spared' : $period['state'];
            }
        }
        // A weekly habit has no missed days, only done ones; a day done off the schedule still shows.
        foreach ($this->values as $date => $value) {
            if (! isset($states[$date]) && $value > 0 && $this->habit->keeps($value)) {
                $states[$date] = StreakCounter::KEPT;
            }
        }

        return $states;
    }

    /**
     * Every day from $from to today for the habit's screen, and its numbers.
     *
     * @return array{days: list<array{date: string, value: int, state: string}>, stats: array<string, mixed>}
     */
    public function history(Carbon $from): array
    {
        $states = $this->dayStates();
        $start = $this->habit->startDate();
        $days = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($this->today); $day->addDay()) {
            $date = $day->toDateString();
            $state = $states[$date] ?? match (true) {
                $day->lt($start) => 'none',
                $this->isPaused($day) => StreakCounter::PAUSED,
                default => 'off',
            };
            $days[] = ['date' => $date, 'value' => $this->value($day), 'state' => $state];
        }

        return ['days' => $days, 'stats' => $this->stats($states)];
    }

    /**
     * @param  array<string, string>  $states
     * @return array<string, mixed>
     */
    private function stats(array $states): array
    {
        ['periods' => $periods, 'count' => $count] = $this->counted();
        $keptDays = count(array_filter($states, fn (string $s) => $s === StreakCounter::KEPT));

        // Kept days in the current run: the weeks of a weekly habit add up their days.
        $runDays = 0;
        if ($count['start'] !== null) {
            foreach (array_slice($periods, $count['start']) as $period) {
                if ($period['state'] !== StreakCounter::KEPT) {
                    continue;
                }
                $runDays += $this->unit() === 'day' ? 1 : $this->weekCount(Carbon::parse($period['date'], config('app.timezone')));
            }
        }

        // How often it was kept lately: the last 30 days, or the last 4 finished weeks.
        if ($this->unit() === 'day') {
            $since = $this->today->copy()->subDays(29)->toDateString();
            $recent = array_filter($states, fn (string $s, string $date) => $date >= $since, ARRAY_FILTER_USE_BOTH);
            $judged = array_filter($recent, fn (string $s) => in_array($s, [StreakCounter::KEPT, StreakCounter::MISSED, 'spared'], true));
            $rate = $judged === [] ? null : count(array_filter($judged, fn (string $s) => $s === StreakCounter::KEPT)) / count($judged);
        } else {
            $finished = array_slice(array_filter($periods, fn (array $p) => in_array($p['state'], [StreakCounter::KEPT, StreakCounter::MISSED], true)), -4);
            $rate = $finished === [] ? null : count(array_filter($finished, fn (array $p) => $p['state'] === StreakCounter::KEPT)) / count($finished);
        }

        $saves = (int) $this->habit->saves_uzs;

        return [
            'rate' => $rate === null ? null : round($rate, 3),
            'rate_over' => $this->unit() === 'day' ? '30d' : '4w',
            'kept_days' => $keptDays,
            'total_value' => $this->habit->type === 'check' ? null : array_sum($this->values),
            'saved_uzs' => $saves > 0 ? $saves * $keptDays : null,
            'streak_saved_uzs' => $saves > 0 ? $saves * $runDays : null,
        ];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $states = $this->dayStates();
        $days = [];
        for ($day = $this->today->copy()->subDays(6); $day->lte($this->today); $day->addDay()) {
            $value = $this->value($day);
            $paused = $this->isPaused($day);
            $days[] = [
                'date' => $day->toDateString(),
                'value' => $value,
                'kept' => $day->gte($this->habit->startDate()) && ($value > 0 || ($this->habit->type === 'limit' && ! $paused)) && $this->habit->keeps($value),
                'scheduled' => $this->habit->isScheduledOn($day),
                'spared' => ($states[$day->toDateString()] ?? null) === 'spared',
                'paused' => $paused,
            ];
        }
        $perWeek = $this->habit->perWeek();

        return [
            'today' => ['value' => $this->value($this->today), 'state' => $this->stateToday()],
            'week_count' => $perWeek === null ? null : $this->weekCount($this->today),
            'streak' => $this->streaks(),
            'days' => $days,
        ];
    }
}
