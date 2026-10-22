<?php

use App\Models\ExchangeRate;
use App\Money\ExchangeRates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function cbuAnswer(string $rate, string $nominal = '1'): array
{
    return [['id' => 69, 'Code' => '840', 'Ccy' => 'USD', 'Nominal' => $nominal, 'Rate' => $rate, 'Date' => '21.10.2026']];
}

it('fetches a missing rate from CBU once and stores it', function () {
    Http::fake(['cbu.uz/uz/arkhiv-kursov-valyut/json/USD/2026-10-21/' => Http::response(cbuAnswer('12061.5'))]);
    $rates = app(ExchangeRates::class);

    expect($rates->rateFor('USD', Carbon::parse('2026-10-21 15:00')))->toBe('12061.5000')
        ->and($rates->rateFor('USD', Carbon::parse('2026-10-21 18:00')))->toBe('12061.5000');

    Http::assertSentCount(1);
    expect(ExchangeRate::where('currency', 'USD')->whereDate('date', '2026-10-21')->count())->toBe(1);
});

it('divides by the nominal', function () {
    Http::fake(['cbu.uz/*' => Http::response(cbuAnswer('1520.30', '10'))]);

    expect(app(ExchangeRates::class)->rateFor('USD', Carbon::parse('2026-10-21')))->toBe('152.0300');
});

it('falls back to the latest earlier rate and does not retry CBU for ten minutes', function () {
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-10-18', 'rate' => '12040.0000']);
    Http::fake(['cbu.uz/*' => Http::failedConnection()]);
    $rates = app(ExchangeRates::class);

    expect($rates->rateFor('USD', Carbon::parse('2026-10-21')))->toBe('12040.0000')
        ->and($rates->rateFor('USD', Carbon::parse('2026-10-21')))->toBe('12040.0000');
    Http::assertSentCount(1);
});

it('does not ask CBU about future days', function () {
    $this->travelTo('2026-10-21 12:00');
    Http::preventStrayRequests();
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-10-21', 'rate' => '12061.5000']);

    expect(app(ExchangeRates::class)->rateFor('USD', Carbon::parse('2026-10-25')))->toBe('12061.5000');
});

it('stores today\'s rate from the scheduled command', function () {
    $this->travelTo('2026-10-21 09:30');
    Http::fake(['cbu.uz/*' => Http::response(cbuAnswer('12061.5'))]);

    $this->artisan('rates:fetch')->expectsOutputToContain('USD: 12061.5000')->assertSuccessful();
});
