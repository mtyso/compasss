<?php

namespace App\Console\Commands;

use App\Money\ExchangeRates;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rates:fetch')]
#[Description("Store today's CBU exchange rates")]
class FetchRates extends Command
{
    public function handle(ExchangeRates $rates): int
    {
        $failed = false;
        foreach (array_keys(Money::CURRENCIES) as $currency) {
            if ($currency === 'UZS') {
                continue;
            }
            $rate = $rates->fetch($currency, now()->startOfDay());
            $rate ? $this->info("{$currency}: {$rate}") : $this->warn("{$currency}: CBU did not answer");
            $failed = $failed || $rate === null;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
