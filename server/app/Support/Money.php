<?php

namespace App\Support;

/**
 * Amounts are integers in the currency's minor units (D8); rates are decimal
 * strings and go through bcmath, never float (D9).
 */
final class Money
{
    /** Supported currencies and their number of minor-unit digits. */
    public const CURRENCIES = ['UZS' => 0, 'USD' => 2];

    public static function decimals(string $currency): int
    {
        return self::CURRENCIES[$currency];
    }

    /** Normalises a rate to the 4 decimal places the database stores. */
    public static function rate(string|int|float $rate): string
    {
        return bcadd((string) $rate, '0', 4);
    }

    /** Converts minor units of $currency into whole som at $rate (som per unit), rounding half away from zero. */
    public static function toUzs(int $amount, string $currency, string $rate): int
    {
        if ($currency === 'UZS') {
            return $amount;
        }

        $major = bcdiv((string) $amount, bcpow('10', (string) self::decimals($currency)), 8);

        return self::round(bcmul($major, $rate, 8));
    }

    /** Converts whole som into minor units of $currency at $rate, rounding half away from zero. */
    public static function fromUzs(int $amountUzs, string $currency, string $rate): int
    {
        if ($currency === 'UZS') {
            return $amountUzs;
        }
        if (bccomp($rate, '0', 4) <= 0) {
            return 0;
        }
        $major = bcdiv((string) $amountUzs, $rate, 8);

        return self::round(bcmul($major, bcpow('10', (string) self::decimals($currency)), 8));
    }

    private static function round(string $value): int
    {
        $negative = str_starts_with($value, '-');
        // bcadd with scale 0 truncates, so adding a half first rounds the absolute value.
        $rounded = bcadd(ltrim($value, '-'), '0.5', 0);

        return (int) ($negative && $rounded !== '0' ? '-'.$rounded : $rounded);
    }
}
