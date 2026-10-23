<?php

use App\Support\Repeat;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// MonthDay reads the app time zone from config.
uses(TestCase::class);

function repeatDay(string $date): Carbon
{
    return Carbon::parse($date, 'Asia/Tashkent');
}

it('steps daily, weekly, monthly and yearly rules', function (array $rule, string $from, string $next) {
    expect(Repeat::step($rule, repeatDay($from))->toDateString())->toBe($next);
})->with([
    'every day' => [['unit' => 'day', 'every' => 1], '2026-10-21', '2026-10-22'],
    'every 3 days' => [['unit' => 'day', 'every' => 3], '2026-10-30', '2026-11-02'],
    'every week' => [['unit' => 'week', 'every' => 1], '2026-10-21', '2026-10-28'],
    'Mon, Wed, Fri from Wed' => [['unit' => 'week', 'every' => 1, 'days' => [1, 3, 5]], '2026-10-21', '2026-10-23'],
    'Mon, Wed, Fri from Fri' => [['unit' => 'week', 'every' => 1, 'days' => [1, 3, 5]], '2026-10-23', '2026-10-26'],
    'every 2 weeks on Mon from Mon' => [['unit' => 'week', 'every' => 2, 'days' => [1]], '2026-10-19', '2026-11-02'],
    'monthly on the 31st into November' => [['unit' => 'month', 'every' => 1, 'day' => 31], '2026-10-31', '2026-11-30'],
    'monthly on the 31st back to December' => [['unit' => 'month', 'every' => 1, 'day' => 31], '2026-11-30', '2026-12-31'],
    'yearly on 29 February' => [['unit' => 'year', 'every' => 1], '2028-02-29', '2029-02-28'],
    'yearly on the 29th back to a leap year' => [['unit' => 'year', 'every' => 1, 'day' => 29], '2031-02-28', '2032-02-29'],
    'every 2 years on the 31st' => [['unit' => 'year', 'every' => 2, 'day' => 31], '2026-12-31', '2028-12-31'],
]);

it('moves an overdue task past today when it is completed', function () {
    $daily = ['unit' => 'day', 'every' => 1];

    expect(Repeat::nextAfter($daily, repeatDay('2026-10-15'), repeatDay('2026-10-21'))->toDateString())->toBe('2026-10-22')
        ->and(Repeat::nextAfter(['unit' => 'month', 'every' => 1, 'day' => 5], repeatDay('2026-11-05'), repeatDay('2026-11-03'))->toDateString())->toBe('2026-12-05');
});
