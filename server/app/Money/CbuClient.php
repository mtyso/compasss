<?php

namespace App\Money;

use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Official rates of the Central Bank of Uzbekistan (free JSON, no key). */
class CbuClient
{
    /** @return string|null som per one unit of $currency on $date */
    public function rate(string $currency, Carbon $date): ?string
    {
        $url = "https://cbu.uz/uz/arkhiv-kursov-valyut/json/{$currency}/{$date->toDateString()}/";

        try {
            $response = Http::timeout(5)->acceptJson()->get($url);
        } catch (ConnectionException $e) {
            Log::warning("CBU rate {$currency} {$date->toDateString()}: {$e->getMessage()}");

            return null;
        }

        $row = collect($response->json() ?? [])->firstWhere('Ccy', $currency);
        if (! $response->ok() || ! is_numeric($row['Rate'] ?? null)) {
            Log::warning("CBU rate {$currency} {$date->toDateString()}: unexpected answer {$response->status()}");

            return null;
        }

        $nominal = is_numeric($row['Nominal'] ?? null) && (int) $row['Nominal'] > 0 ? (string) (int) $row['Nominal'] : '1';

        return Money::rate(bcdiv((string) $row['Rate'], $nominal, 4));
    }
}
