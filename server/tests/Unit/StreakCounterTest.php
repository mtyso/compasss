<?php

use App\Streaks\StreakCounter;

// Streaks with spares (docs/PLAN.md, D37): every 7 kept periods earn a spare that covers one miss.

function periods(string $pattern): array
{
    // k kept, m missed, p paused, o open (the period under way)
    return array_map(fn (string $c) => match ($c) {
        'k' => StreakCounter::KEPT,
        'm' => StreakCounter::MISSED,
        'p' => StreakCounter::PAUSED,
        'o' => StreakCounter::OPEN,
    }, str_split($pattern));
}

it('counts kept periods and breaks on a miss with no spare', function () {
    expect(StreakCounter::count(periods('kkkmkk'), 7, 2))
        ->toBe(['current' => 2, 'best' => 3, 'previous' => 3, 'spare' => 0, 'spared' => [], 'start' => 4]);
    expect(StreakCounter::count([], 7, 2))->toBe(['current' => 0, 'best' => 0, 'previous' => 0, 'spare' => 0, 'spared' => [], 'start' => null]);
});

it('covers a miss with a spare earned by seven kept days', function () {
    // The miss adds nothing, so 7 + 3 kept days make 10.
    expect(StreakCounter::count(periods('kkkkkkkmkkk'), 7, 2))
        ->toBe(['current' => 10, 'best' => 10, 'previous' => 0, 'spare' => 0, 'spared' => [7], 'start' => 0]);
});

it('keeps at most two spares and breaks on the third miss in a row', function () {
    $two = StreakCounter::count(periods(str_repeat('k', 21).'mm'), 7, 2);
    expect($two['current'])->toBe(21)->and($two['spare'])->toBe(0)->and($two['spared'])->toBe([21, 22]);

    $three = StreakCounter::count(periods(str_repeat('k', 21).'mmmk'), 7, 2);
    expect($three)->toBe(['current' => 1, 'best' => 21, 'previous' => 21, 'spare' => 0, 'spared' => [], 'start' => 24]);
});

it('does not let every other day hold a streak', function () {
    expect(StreakCounter::count(periods('kmkmkmkmkm'), 7, 2)['current'])->toBe(0);
});

it('lets pauses and the open period count neither way', function () {
    expect(StreakCounter::count(periods('kkppppkko'), 7, 2))
        ->toBe(['current' => 4, 'best' => 4, 'previous' => 0, 'spare' => 0, 'spared' => [], 'start' => 0]);
});

it('earns a spare week for every four kept weeks', function () {
    $weeks = StreakCounter::count(periods('kkkkmkkkkmmk'), 4, 1);
    expect($weeks['spared'])->toBe([])->and($weeks['current'])->toBe(1)->and($weeks['best'])->toBe(8);
});

it('names the next milestone', function () {
    expect(StreakCounter::nextMilestone(0, 'day'))->toBe(7)
        ->and(StreakCounter::nextMilestone(7, 'day'))->toBe(14)
        ->and(StreakCounter::nextMilestone(29, 'day'))->toBe(30)
        ->and(StreakCounter::nextMilestone(365, 'day'))->toBeNull()
        ->and(StreakCounter::nextMilestone(5, 'week'))->toBe(8);
});
