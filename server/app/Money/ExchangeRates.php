<?php

namespace App\Money;

use App\Models\ExchangeRate;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ExchangeRates
{
    public function __construct(private CbuClient $cbu) {}

    /**
     * Som per unit of $currency on $date: the stored rate of that day, else the
     * CBU rate fetched now, else the latest earlier stored rate. Null when nothing is known.
     */
    public function rateFor(string $currency, Carbon $date): ?string
    {
        if ($currency === 'UZS') {
            return Money::rate(1);
        }

        $day = $date->copy()->startOfDay();
        $stored = ExchangeRate::where('currency', $currency)->whereDate('date', $day->toDateString())->value('rate');

        $rate = $stored
            ?? (! $day->isFuture() ? $this->fetch($currency, $day) : null)
            ?? ExchangeRate::where('currency', $currency)
                ->whereDate('date', '<=', $day->toDateString())
                ->orderByDesc('date')
                ->value('rate');

        // SQLite hands decimals back trimmed ("12061.5"), MariaDB padded ("12061.5000").
        return $rate === null ? null : Money::rate($rate);
    }

    /** Fetches and stores the CBU rate; a failure is remembered for 10 minutes so saving operations stays fast. */
    public function fetch(string $currency, Carbon $date): ?string
    {
        $failedKey = "cbu-failed:{$currency}:{$date->toDateString()}";
        if (Cache::has($failedKey)) {
            return null;
        }

        $rate = $this->cbu->rate($currency, $date);
        if ($rate === null) {
            Cache::put($failedKey, true, now()->addMinutes(10));

            return null;
        }

        ExchangeRate::updateOrCreate(
            ['currency' => $currency, 'date' => $date->toDateString()],
            ['rate' => $rate, 'source' => 'cbu'],
        );

        return $rate;
    }
}
