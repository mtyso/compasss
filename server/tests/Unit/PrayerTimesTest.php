<?php

use App\Prayer\Cities;
use App\Prayer\PrayerTimes;

// Prayer times as the Muslim Board of Uzbekistan's taqvim gives them (docs/PLAN.md, D40). The rows are the
// Board's table for Tashkent: Fajr, sunrise, Dhuhr, Asr, Maghrib, Isha. It rounds its own way, so a minute apart is allowed.

dataset('taqvim', [
    'winter' => ['2026-01-15', ['06:23', '07:47', '12:32', '15:37', '17:21', '18:42']],
    'summer solstice' => ['2026-06-21', ['03:04', '04:50', '12:25', '17:40', '20:03', '21:46']],
    'autumn' => ['2026-09-15', ['04:43', '06:03', '12:18', '16:42', '18:36', '19:52']],
    'october' => ['2026-10-01', ['05:01', '06:20', '12:13', '16:19', '18:09', '19:23']],
    'winter solstice' => ['2026-12-21', ['06:19', '07:45', '12:21', '15:16', '17:01', '18:23']],
]);

function minutesOf(string $clock): int
{
    [$h, $m] = explode(':', $clock);

    return (int) $h * 60 + (int) $m;
}

it('gives Tashkent the taqvim\'s times within a minute', function (string $date, array $taqvim) {
    $times = PrayerTimes::tashkent($date);
    foreach (['fajr', 'sunrise', 'dhuhr', 'asr', 'maghrib', 'isha'] as $i => $name) {
        expect(abs($times[$name] - minutesOf($taqvim[$i])))->toBeLessThanOrEqual(1, "{$name} on {$date}");
    }
})->with('taqvim');

it('matches the taqvim to the minute on a usual day, with the middle of the night for Isha', function () {
    $clocks = array_map(fn (int $m) => PrayerTimes::clock($m), PrayerTimes::tashkent('2026-10-30'));

    expect($clocks)->toBe([
        'fajr' => '05:32', 'sunrise' => '06:52', 'dhuhr' => '12:07', 'asr' => '15:39',
        'maghrib' => '17:25', 'isha' => '18:41', 'midnight' => '23:27',
    ]);
});

it('moves every time of a city by its minutes, past midnight included', function () {
    $tashkent = PrayerTimes::tashkent('2026-06-21');
    $nukus = PrayerTimes::for('2026-06-21', Cities::get('nukus')['offset']);

    expect(array_map(fn (int $a, int $b) => $b - $a, $tashkent, $nukus))->each->toBe(39)
        ->and(PrayerTimes::clock($nukus['midnight']))->toBe('00:11')
        ->and(PrayerTimes::clock(PrayerTimes::for('2026-10-30', Cities::get('samarkand')['offset'])['asr']))->toBe('15:48')
        ->and(PrayerTimes::clock(PrayerTimes::for('2026-10-30', Cities::get('andijan')['offset'])['fajr']))->toBe('05:20');
});

it('knows every city it lists, falls back to Tashkent and names them in both languages', function () {
    expect(explode(',', Cities::LIST))->toBe(array_keys(Cities::ALL))
        ->and(Cities::get('nowhere')['slug'])->toBe('tashkent')
        ->and(Cities::get('fergana', 'uz')['name'])->toBe("Farg'ona")
        ->and(Cities::list()[0]['slug'])->toBe('tashkent')
        ->and(Cities::list()[1]['name'])->toBe('Алмалык');
});
