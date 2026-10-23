<?php

use App\Calendar\Occurrences;
use App\Models\Event;
use App\Support\Repeat;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Events expanded into days (App\Calendar\Occurrences). MonthDay reads the app time zone from config.
uses(TestCase::class);

function calendarDay(string $date): Carbon
{
    return Carbon::parse($date, 'Asia/Tashkent')->startOfDay();
}

/** @param  array<string, mixed>  $attributes */
function calendarEvent(array $attributes): Event
{
    return new Event(['title' => 'Событие', 'starts_on' => '2026-10-21', 'ends_on' => $attributes['starts_on'] ?? '2026-10-21', ...$attributes]);
}

/** @return list<string> start dates of the occurrences touching the range */
function occurrenceDates(Event $event, string $from, string $to): array
{
    return array_column(Occurrences::of($event, calendarDay($from), calendarDay($to)), 'date');
}

/**
 * Plain stepping from the first day, the way the fast-forward must agree with.
 *
 * @return list<array{date: string, end_date: string}>
 */
function steppedOccurrences(Event $event, Carbon $from, Carbon $to): array
{
    $span = $event->spanDays();
    if ($event->start_time !== null && $event->end_time === '00:00' && $span >= 1) {
        $span--;
    }
    $until = $event->repeat_until ? calendarDay($event->repeat_until) : null;
    $occurrences = [];
    $date = calendarDay($event->starts_on);
    while ($date->lte($to) && ($until === null || $date->lte($until))) {
        $end = $date->copy()->addDays($span);
        if ($end->gte($from) && ! in_array($date->toDateString(), $event->skip_dates ?? [], true)) {
            $occurrences[] = ['date' => $date->toDateString(), 'end_date' => $end->toDateString()];
        }
        if ($event->repeat === null) {
            break;
        }
        $date = Repeat::step($event->repeat, $date);
    }

    return $occurrences;
}

it('jumps over years of a daily event straight to the range', function () {
    $daily = calendarEvent(['starts_on' => '2006-03-01', 'repeat' => ['unit' => 'day', 'every' => 1]]);

    expect(occurrenceDates($daily, '2026-10-19', '2026-10-25'))
        ->toBe(['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25']);
});

it('repeats every other day from the first one', function () {
    $event = calendarEvent(['starts_on' => '2026-10-01', 'repeat' => ['unit' => 'day', 'every' => 2]]);

    expect(occurrenceDates($event, '2026-10-20', '2026-10-26'))->toBe(['2026-10-21', '2026-10-23', '2026-10-25']);
});

it('repeats on weekdays, every week or every other week', function () {
    // Mon, Wed, Fri from Wednesday 7 October
    $weekly = calendarEvent(['starts_on' => '2026-10-07', 'repeat' => ['unit' => 'week', 'every' => 1, 'days' => [1, 3, 5]]]);
    expect(occurrenceDates($weekly, '2026-10-05', '2026-10-18'))->toBe(['2026-10-07', '2026-10-09', '2026-10-12', '2026-10-14', '2026-10-16']);

    // Tue and Thu every second week from Tuesday 6 October: weeks of 6, 20 October and 3 November
    $fortnightly = calendarEvent(['starts_on' => '2026-10-06', 'repeat' => ['unit' => 'week', 'every' => 2, 'days' => [2, 4]]]);
    expect(occurrenceDates($fortnightly, '2026-10-01', '2026-11-08'))
        ->toBe(['2026-10-06', '2026-10-08', '2026-10-20', '2026-10-22', '2026-11-03', '2026-11-05'])
        ->and(occurrenceDates($fortnightly, '2027-03-01', '2027-03-14'))->toBe(['2027-03-09', '2027-03-11']);
});

it('keeps the 31st through short months and the 29th of February through common years', function () {
    $monthly = calendarEvent(['starts_on' => '2026-01-31', 'repeat' => ['unit' => 'month', 'every' => 1, 'day' => 31]]);
    expect(occurrenceDates($monthly, '2026-02-01', '2026-05-31'))->toBe(['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31']);

    $birthday = calendarEvent(['starts_on' => '2028-02-29', 'repeat' => ['unit' => 'year', 'every' => 1, 'day' => 29]]);
    expect(occurrenceDates($birthday, '2028-01-01', '2032-12-31'))->toBe(['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'])
        ->and(occurrenceDates($birthday, '2032-02-01', '2032-03-31'))->toBe(['2032-02-29']);
});

it('stops after repeat_until and leaves skipped days out', function () {
    $event = calendarEvent([
        'starts_on' => '2026-10-19', 'repeat' => ['unit' => 'day', 'every' => 1],
        'repeat_until' => '2026-10-23', 'skip_dates' => ['2026-10-21'],
    ]);

    expect(occurrenceDates($event, '2026-10-01', '2026-10-31'))->toBe(['2026-10-19', '2026-10-20', '2026-10-22', '2026-10-23']);
});

it('shows a multi-day event in a range it started before', function () {
    $trip = calendarEvent(['starts_on' => '2026-10-17', 'ends_on' => '2026-10-20']);
    expect(Occurrences::of($trip, calendarDay('2026-10-19'), calendarDay('2026-10-25')))->toBe([['date' => '2026-10-17', 'end_date' => '2026-10-20']])
        ->and(occurrenceDates($trip, '2026-10-21', '2026-10-25'))->toBe([]);

    // A weekly three-day trip: the one from the week before still reaches into the range.
    $weekly = calendarEvent(['starts_on' => '2026-10-02', 'ends_on' => '2026-10-04', 'repeat' => ['unit' => 'week', 'every' => 1]]);
    expect(occurrenceDates($weekly, '2026-10-18', '2026-10-22'))->toBe(['2026-10-16']);
});

it('keeps an evening ending at midnight on its own day, and a night on both', function () {
    $evening = calendarEvent(['starts_on' => '2026-10-21', 'ends_on' => '2026-10-22', 'start_time' => '22:00', 'end_time' => '00:00']);
    expect(Occurrences::of($evening, calendarDay('2026-10-21'), calendarDay('2026-10-22')))->toBe([['date' => '2026-10-21', 'end_date' => '2026-10-21']])
        ->and(occurrenceDates($evening, '2026-10-22', '2026-10-22'))->toBe([]);

    $night = calendarEvent(['starts_on' => '2026-10-21', 'ends_on' => '2026-10-22', 'start_time' => '23:00', 'end_time' => '01:00']);
    expect(occurrenceDates($night, '2026-10-21', '2026-10-21'))->toBe(['2026-10-21'])
        ->and(occurrenceDates($night, '2026-10-22', '2026-10-22'))->toBe(['2026-10-21']);
});

it('knows the days an occurrence starts on', function () {
    $event = calendarEvent([
        'starts_on' => '2026-10-05', 'ends_on' => '2026-10-06', 'repeat' => ['unit' => 'week', 'every' => 1, 'days' => [1, 4]],
        'repeat_until' => '2026-10-31', 'skip_dates' => ['2026-10-15'],
    ]);

    expect(Occurrences::isOccurrence($event, '2026-10-08'))->toBeTrue()
        ->and(Occurrences::isOccurrence($event, '2026-10-09'))->toBeFalse()   // inside the two days, not a start
        ->and(Occurrences::isOccurrence($event, '2026-10-15'))->toBeFalse()   // skipped
        ->and(Occurrences::isOccurrence($event, '2026-11-02'))->toBeFalse()   // after repeat_until
        ->and(Occurrences::isOccurrence($event, '2026-10-01'))->toBeFalse()   // before the first
        ->and(Occurrences::isOccurrence(calendarEvent(['starts_on' => '2026-10-21']), '2026-10-21'))->toBeTrue();
});

it('lands exactly where plain stepping does, for many rules and ranges', function () {
    mt_srand(20261023);
    $base = calendarDay('2018-01-01');

    for ($case = 0; $case < 600; $case++) {
        $unit = ['day', 'week', 'month', 'year'][mt_rand(0, 3)];
        $start = $base->copy()->addDays(mt_rand(0, 3300));
        if ($unit !== 'day' && $unit !== 'week' && mt_rand(0, 1)) {
            // Ends of months and 29 February are where month arithmetic goes wrong.
            $start = mt_rand(0, 2) === 0
                ? calendarDay(mt_rand(2019, 2027).'-02-28')->addDays(mt_rand(0, 1))
                : $start->copy()->endOfMonth()->startOfDay()->subDays(mt_rand(0, 3));
        }
        $rule = match ($unit) {
            'day' => ['unit' => 'day', 'every' => mt_rand(1, 5)],
            'week' => ['unit' => 'week', 'every' => mt_rand(1, 3)],
            'month' => ['unit' => 'month', 'every' => mt_rand(1, 4), 'day' => mt_rand(0, 3) ? $start->day : mt_rand(28, 31)],
            'year' => ['unit' => 'year', 'every' => mt_rand(1, 4), 'day' => mt_rand(0, 3) ? $start->day : mt_rand(28, 31)],
        };
        if ($unit === 'week' && mt_rand(0, 2)) {
            $rule['days'] = array_values(array_unique(array_map(fn () => mt_rand(1, 7), range(1, mt_rand(1, 5)))));
            sort($rule['days']);
        }
        $span = mt_rand(0, 4) === 0 ? mt_rand(1, 3) : 0;
        $timed = mt_rand(0, 1) === 1;
        $event = calendarEvent([
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->copy()->addDays($span)->toDateString(),
            'start_time' => $timed ? '22:00' : null,
            'end_time' => $timed ? ['23:30', '00:00', '01:00'][mt_rand(0, 2)] : null,
            'repeat' => $rule,
            'repeat_until' => mt_rand(0, 3) === 0 ? $start->copy()->addDays(mt_rand(0, 2000))->toDateString() : null,
        ]);

        // Plain stepping walks every occurrence from the first, so keep the daily ranges within a year or so.
        $reach = match ($unit) {
            'day' => 400 * $rule['every'],
            'week' => 1000,
            default => 9000,
        };
        $from = $start->copy()->addDays(mt_rand(-60, $reach));
        $to = $from->copy()->addDays(mt_rand(0, 91));
        $day = $from->copy()->addDays(mt_rand(0, Event::daysBetween($from, $to)));
        $event->skip_dates = mt_rand(0, 1) ? [$from->copy()->addDays(mt_rand(0, 6))->toDateString()] : null;
        $expected = steppedOccurrences($event, $from, $to);

        $label = json_encode([$event->starts_on, $event->ends_on, $event->end_time, $rule, $event->repeat_until, $event->skip_dates, $from->toDateString(), $to->toDateString()]);
        expect(Occurrences::of($event, $from, $to))->toBe($expected, $label)
            ->and(Occurrences::isOccurrence($event, $day->toDateString()))->toBe(in_array($day->toDateString(), array_column($expected, 'date'), true), $label);
    }
});
