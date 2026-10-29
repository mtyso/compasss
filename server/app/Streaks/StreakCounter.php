<?php

namespace App\Streaks;

/**
 * A streak over a run of periods (days or weeks), oldest first (docs/PLAN.md, D37).
 *
 * Each kept period adds one. Every `spareEvery` kept periods in a row earn a spare, up to `spareMax`
 * held at once; a missed period then spends a spare instead of breaking the streak, and adds nothing.
 * A missed period with no spare left breaks it. Paused periods (a pause, the first partial week)
 * count neither way, and so does the period still under way at the end.
 */
final class StreakCounter
{
    public const KEPT = 'kept';

    public const MISSED = 'missed';

    public const PAUSED = 'paused';

    public const OPEN = 'open';

    /** Streak lengths worth marking, in days and in weeks. */
    public const DAY_MILESTONES = [7, 14, 30, 60, 100, 200, 365];

    public const WEEK_MILESTONES = [4, 8, 12, 26, 52];

    /**
     * `previous` is the longest run before the current one (beating it is a record), `spared` lists the
     * indexes a spare covered in the current run, `start` is where that run begins.
     *
     * @param  list<string>  $periods  one of the constants above per period, oldest first
     * @return array{current: int, best: int, previous: int, spare: int, spared: list<int>, start: int|null}
     */
    public static function count(array $periods, int $spareEvery, int $spareMax): array
    {
        $run = $best = $previous = $spare = $towardsSpare = 0;
        $start = null;
        $spared = [];
        foreach ($periods as $index => $state) {
            if ($state === self::KEPT) {
                $start ??= $index;
                $run++;
                $best = max($best, $run);
                if (++$towardsSpare === $spareEvery) {
                    $towardsSpare = 0;
                    $spare = min($spareMax, $spare + 1);
                }
            } elseif ($state === self::MISSED) {
                if ($spare > 0) {
                    $spare--;
                    $spared[] = $index;
                } else {
                    $previous = max($previous, $run);
                    $run = $towardsSpare = 0;
                    $start = null;
                }
            }
        }
        // Spares spent on an earlier run that broke later are history, not part of this one.
        $spared = $start === null ? [] : array_values(array_filter($spared, fn (int $i) => $i > $start));

        return ['current' => $run, 'best' => $best, 'previous' => $previous, 'spare' => $spare, 'spared' => $spared, 'start' => $start];
    }

    /** The next milestone above the current streak, or null past the last one. */
    public static function nextMilestone(int $current, string $unit): ?int
    {
        foreach ($unit === 'week' ? self::WEEK_MILESTONES : self::DAY_MILESTONES as $milestone) {
            if ($milestone > $current) {
                return $milestone;
            }
        }

        return null;
    }
}
