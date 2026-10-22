<?php

namespace App\Support;

/**
 * Reads amounts the way people type them: «32000», «32 000», «32к», «1,5м», «12.50».
 * Server twin of parseAmount() in app/src/lib/money.ts: the same input gives the same amount.
 */
final class AmountParser
{
    /** Multipliers for the short forms, Russian and Uzbek. */
    public const SUFFIXES = [
        'к' => 1_000, 'k' => 1_000, 'тыс' => 1_000, 'ming' => 1_000,
        'м' => 1_000_000, 'm' => 1_000_000, 'млн' => 1_000_000, 'mln' => 1_000_000,
    ];

    /** @return int|null minor units of $currency, or null when it is not a positive amount */
    public static function parse(string $input, string $currency): ?int
    {
        $compact = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', mb_strtolower(trim($input)));
        $suffixes = implode('|', array_map('preg_quote', array_keys(self::SUFFIXES)));

        if (! preg_match('/^(\d+(?:[.,]\d+)?)('.$suffixes.')?$/u', $compact, $m)) {
            return null;
        }

        return self::toMinor(str_replace(',', '.', $m[1]), $m[2] ?? '', $currency);
    }

    /** "1.5" + "м" in UZS gives 1500000; "12.5" in USD gives 1250. Exact: bcmath, no float. */
    public static function toMinor(string $number, string $suffix, string $currency): ?int
    {
        $multiplier = $suffix === '' ? 1 : self::SUFFIXES[mb_strtolower($suffix)];
        $scale = bcpow('10', (string) Money::decimals($currency));
        $minor = bcmul(bcmul($number, (string) $multiplier, 8), $scale, 8);

        // Round half up to whole minor units, as Math.round does in the app.
        $rounded = bcadd($minor, '0.5', 0);

        if (bccomp($rounded, '0') <= 0 || bccomp($rounded, (string) PHP_INT_MAX) > 0) {
            return null;
        }

        return (int) $rounded;
    }
}
