<?php

namespace App\Habits;

use App\Models\Habit;
use Illuminate\Support\Carbon;

/**
 * Where a habit stands today: the value logged, the last seven days and the streaks.
 *
 * A streak counts kept scheduled days in a row (or kept weeks, for «N раз в неделю»). Today, or this
 * week, only adds to it once kept and never breaks it while there is still time; days off the
 * schedule are skipped. A limit habit is kept on a day nothing was logged, and is broken today as
 * soon as it goes over.
 */
final class HabitProgress
{
    /** How far back streaks look. */
    private const HORIZON_DAYS = 400;

    /** @param  array<string, int>  $values  logged value by date */
    private function __construct(private readonly Habit $habit, private readonly Carbon $today, private readonly array $values) {}

    public static function for(Habit $habit, Carbon $today): self
    {
        $from = $habit->startDate()->max($today->copy()->subDays(self::HORIZON_DAYS));
        $values = $habit->logs()
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $today->toDateString())
            ->pluck('value', 'date')
            ->mapWithKeys(fn ($value, $date) => [substr((string) $date, 0, 10) => (int) $value])
            ->all();

        return new self($habit, $today->copy()->startOfDay(), $values);
    }

    public function value(Carbon $day): int
    {
        return $this->values[$day->toDateString()] ?? 0;
    }

    /** done, open (still to do today), over (a limit broken today) or off (not scheduled today). */
    public function stateToday(): string
    {
        $value = $this->value($this->today);
        if ($this->habit->type === 'limit') {
            return $this->habit->keeps($value) ? 'done' : 'over';
        }
        if ($this->habit->keeps($value)) {
            return 'done';
        }
        if ($this->habit->perWeek() !== null) {
            return $this->weekCount($this->today) >= $this->habit->perWeek() ? 'off' : 'open';
        }

        return $this->habit->isScheduledOn($this->today) ? 'open' : 'off';
    }

    /** @return array{current: int, best: int, unit: string} */
    public function streaks(): array
    {
        $perWeek = $this->habit->perWeek();
        $periods = $perWeek === null ? $this->dayResults() : $this->weekResults($perWeek);

        // Walking back from now: the current period may still be open.
        $current = 0;
        foreach (array_reverse($periods) as $index => $kept) {
            if ($kept === true) {
                $current++;
            } elseif (! ($index === 0 && $kept === null)) {
                break;
            }
        }
        $best = $run = 0;
        foreach ($periods as $kept) {
            $run = $kept === true ? $run + 1 : ($kept === null ? $run : 0);
            $best = max($best, $run);
        }

        return ['current' => $current, 'best' => max($best, $current), 'unit' => $perWeek === null ? 'day' : 'week'];
    }

    /**
     * Scheduled days from the start to today: true kept, false missed, null today while still open.
     *
     * @return list<bool|null>
     */
    private function dayResults(): array
    {
        $results = [];
        for ($day = $this->from(); $day->lte($this->today); $day->addDay()) {
            if (! $this->habit->isScheduledOn($day)) {
                continue;
            }
            $kept = $this->habit->keeps($this->value($day));
            $results[] = $kept || ! $day->eq($this->today) || $this->habit->type === 'limit' ? $kept : null;
        }

        return $results;
    }

    /**
     * Weeks from the start to this one: true kept, false missed, null for this week and the first
     * (partial) week while not kept.
     *
     * @return list<bool|null>
     */
    private function weekResults(int $perWeek): array
    {
        $results = [];
        $start = $this->from()->startOfWeek(Carbon::MONDAY);
        $thisWeek = $this->today->copy()->startOfWeek(Carbon::MONDAY);
        for ($week = $start; $week->lte($thisWeek); $week->addWeek()) {
            $kept = $this->weekCount($week) >= $perWeek;
            $partial = $week->eq($thisWeek) || $week->lt($this->from());
            $results[] = $kept ? true : ($partial ? null : false);
        }

        return $results;
    }

    /** Days kept in the week of $day, up to today. */
    private function weekCount(Carbon $day): int
    {
        $count = 0;
        $end = $day->copy()->startOfWeek(Carbon::MONDAY)->addDays(6)->min($this->today);
        for ($d = $day->copy()->startOfWeek(Carbon::MONDAY)->max($this->from()); $d->lte($end); $d->addDay()) {
            $count += $this->value($d) > 0 && $this->habit->keeps($this->value($d)) ? 1 : 0;
        }

        return $count;
    }

    private function from(): Carbon
    {
        return $this->habit->startDate()->max($this->today->copy()->subDays(self::HORIZON_DAYS));
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $days = [];
        for ($day = $this->today->copy()->subDays(6); $day->lte($this->today); $day->addDay()) {
            $value = $this->value($day);
            $days[] = [
                'date' => $day->toDateString(),
                'value' => $value,
                'kept' => $day->gte($this->habit->startDate()) && ($value > 0 || $this->habit->type === 'limit') && $this->habit->keeps($value),
                'scheduled' => $this->habit->isScheduledOn($day),
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
