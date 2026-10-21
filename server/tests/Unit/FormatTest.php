<?php

use App\Support\Format;
use Illuminate\Support\Carbon;

it('formats sums like the app', function () {
    expect(Format::uzs(1_250_000))->toBe("1\u{202F}250\u{202F}000 сум")
        ->and(Format::uzs(-45_000, 'uz'))->toBe("−45\u{202F}000 so'm")
        ->and(Format::money(2_050, 'USD'))->toBe('$20,50');
});

it('spells dates and months in both languages', function () {
    expect(Format::dayMonth('2026-11-10'))->toBe('10 ноября')
        ->and(Format::dayMonth(Carbon::parse('2026-11-10'), 'uz'))->toBe('10-noyabr')
        ->and(Format::month('2026-10'))->toBe('октябрь')
        ->and(Format::month('2026-10', 'uz'))->toBe('oktabr')
        ->and(Format::decimal(1.44))->toBe('1,4');
});
