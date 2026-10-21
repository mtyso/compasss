<?php

use App\Support\Money;

it('keeps som as they are', function () {
    expect(Money::toUzs(85_000, 'UZS', '1.0000'))->toBe(85_000);
});

it('converts cents to whole som at the rate', function (int $cents, string $rate, int $som) {
    expect(Money::toUzs($cents, 'USD', $rate))->toBe($som);
})->with([
    '$20 at 12 050,12' => [2000, '12050.1200', 241_002],
    '$0.01 rounds up from half' => [1, '50.0000', 1],
    '$0.01 at 12 345,67 rounds' => [1, '12345.6700', 123],
    'refund stays negative' => [-2000, '12050.1200', -241_002],
    'a billion dollars has no float drift' => [100_000_000_000, '12999.9999', 12_999_999_900_000],
]);

it('normalises rates to four decimals', function () {
    expect(Money::rate('12050.1'))->toBe('12050.1000')
        ->and(Money::rate(1))->toBe('1.0000');
});
