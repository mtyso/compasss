<?php

use App\Advisor\DailyLimit;
use Illuminate\Support\Carbon;

function day(string $date): Carbon
{
    return Carbon::parse($date, 'Asia/Tashkent');
}

it('matches the hand calculation from the plan', function () {
    // 21 October, salary on 10 November: 11 days of October + 9 of November.
    $limit = new DailyLimit(
        today: day('2026-10-21'),
        spendableUzs: 5_000_000,
        spentTodayUzs: 50_000,
        payments: [
            ['due' => day('2026-11-01'), 'amount_uzs' => 2_000_000], // rent, before payday: set aside
            ['due' => day('2026-10-15'), 'amount_uzs' => 150_000],   // internet, overdue: set aside
            ['due' => day('2026-11-15'), 'amount_uzs' => 300_000],   // gym, after payday: not yet
        ],
        nextIncome: day('2026-11-10'),
        minBalanceUzs: 200_000,
    );

    // free = 5 000 000 − 2 150 000 − 200 000 = 2 650 000; at the start of the day 2 700 000.
    expect($limit->daysLeft)->toBe(20)
        ->and($limit->upcomingUzs)->toBe(2_150_000)
        ->and($limit->freeUzs)->toBe(2_650_000)
        ->and($limit->limitUzs)->toBe(135_000)
        ->and($limit->leftTodayUzs)->toBe(85_000);
});

it('keeps the same limit all day while what is left goes down', function () {
    $morning = new DailyLimit(day('2026-10-21'), 2_000_000, 0, [], day('2026-10-31'));
    $evening = new DailyLimit(day('2026-10-21'), 1_900_000, 100_000, [], day('2026-10-31'));

    expect($morning->limitUzs)->toBe(200_000)
        ->and($evening->limitUzs)->toBe(200_000)
        ->and($evening->leftTodayUzs)->toBe(100_000);
});

it('runs to the end of the month when no payday is set', function () {
    $limit = new DailyLimit(day('2026-10-21'), 1_100_000, 0, [], null);

    expect($limit->daysLeft)->toBe(11)
        ->and($limit->estimatedEnd)->toBeTrue()
        ->and($limit->limitUzs)->toBe(100_000)
        ->and($limit->toApi()['until'])->toBe('2026-11-01');
});

it('gives zero, not a negative limit, when payments eat everything', function () {
    $limit = new DailyLimit(day('2026-10-21'), 1_000_000, 30_000, [['due' => day('2026-10-25'), 'amount_uzs' => 2_000_000]], day('2026-11-10'));

    expect($limit->freeUzs)->toBe(-1_000_000)
        ->and($limit->limitUzs)->toBe(0)
        ->and($limit->leftTodayUzs)->toBe(-30_000);
});

it('treats the day before payday as the last day', function () {
    $limit = new DailyLimit(day('2026-11-09'), 300_000, 0, [], day('2026-11-10'));

    expect($limit->daysLeft)->toBe(1)->and($limit->limitUzs)->toBe(300_000);
});
