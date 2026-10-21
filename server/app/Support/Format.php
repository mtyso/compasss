<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Numbers and dates in words for advisor texts and bot messages, matching app/src/lib/format.ts:
 * «1 250 000 сум», «10 ноября», «10-noyabr».
 */
final class Format
{
    // Narrow no-break space between thousands, as in the app
    private const GROUP = "\u{202F}";

    private const MONTHS = [
        'ru' => ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'],
        'uz' => ['yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun', 'iyul', 'avgust', 'sentabr', 'oktabr', 'noyabr', 'dekabr'],
    ];

    // Russian «10 ноября» needs the genitive
    private const MONTHS_RU_GENITIVE = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    public static function money(int $minor, string $currency, string $locale = 'ru'): string
    {
        $sign = $minor < 0 ? '−' : '';
        $abs = abs($minor);
        $decimals = Money::decimals($currency);
        $units = intdiv($abs, 10 ** $decimals);
        $number = number_format($units, 0, '', self::GROUP);
        if ($decimals > 0) {
            $number .= ','.str_pad((string) ($abs % 10 ** $decimals), $decimals, '0', STR_PAD_LEFT);
        }

        return $currency === 'USD' ? "{$sign}\${$number}" : $sign.$number.' '.($locale === 'uz' ? "so'm" : 'сум');
    }

    public static function uzs(int $amount, string $locale = 'ru'): string
    {
        return self::money($amount, 'UZS', $locale);
    }

    public static function dayMonth(Carbon|string $date, string $locale = 'ru'): string
    {
        $date = is_string($date) ? Carbon::parse($date) : $date;

        return $locale === 'uz'
            ? $date->day.'-'.self::MONTHS['uz'][$date->month - 1]
            : $date->day.' '.self::MONTHS_RU_GENITIVE[$date->month - 1];
    }

    /** «октябрь», «oktabr» for a month given as YYYY-MM. */
    public static function month(string $month, string $locale = 'ru'): string
    {
        return self::MONTHS[$locale === 'uz' ? 'uz' : 'ru'][(int) substr($month, 5, 2) - 1];
    }

    /** One decimal with a comma: «1,4». */
    public static function decimal(float $value): string
    {
        return str_replace('.', ',', (string) round($value, 1));
    }
}
