<?php

namespace App\Telegram\Views;

use App\Models\Account;
use App\Models\Category;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Numbers, dates and names the way the app shows them (app/src/lib/format.ts):
 * «32 000 сум», «$12,50», «21 октября» / «21-oktabr». Uzbek dates are spelled out here:
 * Carbon's «uz» locale is Cyrillic.
 */
final class Fmt
{
    private const NARROW_NBSP = "\u{202F}";

    /** Emoji for the ready-made categories; custom ones get theirs from the icon. */
    private const CATEGORY_EMOJI = [
        'groceries' => '🛒', 'groceries_supermarket' => '🛒', 'groceries_bazaar' => '🧺', 'groceries_corner' => '🏪',
        'cafe' => '🍽', 'cafe_delivery' => '🛵', 'cafe_coffee' => '☕', 'cafe_restaurants' => '🍽',
        'transport' => '🚗', 'transport_taxi' => '🚕', 'transport_public' => '🚇', 'transport_fuel' => '⛽',
        'transport_parking' => '🅿️', 'transport_service' => '🔧',
        'home' => '🏠', 'home_rent' => '🔑', 'home_utilities' => '💡', 'home_internet' => '🌐',
        'home_supplies' => '🧽', 'home_repair' => '🛠',
        'connection' => '📱', 'connection_mobile' => '📱', 'connection_subscriptions' => '🔁',
        'health' => '🩺', 'health_pharmacy' => '💊', 'health_doctors' => '🩺', 'health_sport' => '🏋️',
        'clothes' => '👕', 'beauty' => '💅', 'beauty_haircut' => '💈', 'beauty_cosmetics' => '🧴',
        'family' => '🎁', 'family_gifts' => '🎁', 'family_help' => '🤝', 'family_celebrations' => '🎉',
        'leisure' => '🌴', 'leisure_events' => '🎬', 'leisure_hobby' => '🎨', 'leisure_travel' => '✈️',
        'education' => '🎓', 'education_courses' => '🎓', 'education_books' => '📚',
        'finance' => '🏦', 'finance_fees' => '🏦', 'finance_interest' => '📉', 'finance_taxes' => '🧾',
        'charity' => '🤲', 'other_expense' => '📦',
        'salary' => '💰', 'side_job' => '💻', 'bonus' => '🏆', 'gifts_income' => '🎁',
        'interest_cashback' => '💸', 'sales' => '🏷', 'other_income' => '💵',
    ];

    private const ICON_EMOJI = [
        'shopping-basket' => '🛒', 'utensils' => '🍽', 'car' => '🚗', 'house' => '🏠', 'smartphone' => '📱',
        'heart-pulse' => '🩺', 'shirt' => '👕', 'sparkles' => '✨', 'gift' => '🎁', 'tree-palm' => '🌴',
        'graduation-cap' => '🎓', 'landmark' => '🏦', 'hand-heart' => '🤲', 'circle-ellipsis' => '📦',
        'banknote' => '💵', 'briefcase' => '💼', 'award' => '🏆', 'percent' => '💸', 'tag' => '🏷',
        'baby' => '👶', 'paw-print' => '🐾', 'dumbbell' => '🏋️', 'plane' => '✈️', 'coffee' => '☕',
        'book' => '📚', 'wrench' => '🔧',
    ];

    private const ACCOUNT_EMOJI = ['card' => '💳', 'cash' => '💵', 'savings' => '🐷', 'deposit' => '🏦', 'other' => '👛'];

    public static function number(int $value): string
    {
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', self::NARROW_NBSP, (string) $value);
    }

    /** «32 000 сум», «$12,50»; with $sign «−32 000 сум» / «+500 000 сум». */
    public static function money(int $minor, string $currency, string $locale, string $sign = ''): string
    {
        $decimals = Money::decimals($currency);
        $abs = abs($minor);
        $units = intdiv($abs, 10 ** $decimals);
        $fraction = $decimals > 0 ? ','.str_pad((string) ($abs % 10 ** $decimals), $decimals, '0', STR_PAD_LEFT) : '';
        $sign = $sign !== '' ? $sign : ($minor < 0 ? '−' : '');

        if ($currency === 'USD') {
            return $sign.'$'.self::number($units).($fraction === ',00' ? '' : $fraction);
        }

        return $sign.self::number($units).' '.__('bot.currency_uzs', [], $locale);
    }

    public static function uzs(int $amount, string $locale, string $sign = ''): string
    {
        return self::money($amount, 'UZS', $locale, $sign);
    }

    /** «21 октября» / «21-oktabr». */
    public static function date(Carbon $date, string $locale): string
    {
        $month = __('bot.months', [], $locale)[$date->month - 1];

        return $locale === 'uz' ? "{$date->day}-{$month}" : "{$date->day} {$month}";
    }

    /** «Среда, 21 октября». */
    public static function longDate(Carbon $date, string $locale): string
    {
        return __('bot.weekdays', [], $locale)[$date->dayOfWeek].', '.self::date($date, $locale);
    }

    /** «Октябрь». */
    public static function monthName(Carbon $date, string $locale): string
    {
        return __('bot.month_names', [], $locale)[$date->month - 1];
    }

    /** «сегодня, 14:20», «вчера, 09:05», «18 октября, 20:10». */
    public static function when(Carbon $at, string $locale, ?Carbon $now = null): string
    {
        $at = $at->copy()->timezone(config('app.timezone'));
        $now = ($now ?? now())->copy()->timezone(config('app.timezone'));

        $day = match (true) {
            $at->isSameDay($now) => __('bot.today_word', [], $locale),
            $at->isSameDay($now->copy()->subDay()) => __('bot.yesterday_word', [], $locale),
            default => self::date($at, $locale),
        };

        return $day.', '.$at->format('H:i');
    }

    /** «9 дней» / «9 kun». */
    public static function days(int $count, string $locale): string
    {
        return trans_choice('bot.days', $count, ['count' => $count], $locale);
    }

    public static function categoryEmoji(?Category $category, ?Category $parent = null): string
    {
        if ($category === null) {
            return '📦';
        }

        return self::CATEGORY_EMOJI[$category->system_key ?? '']
            ?? self::ICON_EMOJI[$category->icon ?? '']
            ?? ($parent ? self::categoryEmoji($parent) : '📦');
    }

    public static function categoryName(?Category $category, string $locale): string
    {
        return $category ? $category->names()[$locale] : '—';
    }

    /** «🚕 Такси · Транспорт» for a subcategory, «🛒 Продукты» for a main one. */
    public static function categoryLabel(?Category $category, string $locale): string
    {
        $parent = $category?->parent_id ? $category->parent : null;
        $label = self::categoryEmoji($category, $parent).' '.self::categoryName($category, $locale);

        return $parent ? $label.' · '.self::categoryName($parent, $locale) : $label;
    }

    public static function accountLabel(?Account $account): string
    {
        return $account ? (self::ACCOUNT_EMOJI[$account->type] ?? '👛').' '.$account->name : '—';
    }
}
