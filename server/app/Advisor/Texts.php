<?php

namespace App\Advisor;

use App\Models\Category;
use App\Models\Goal;
use App\Support\Format;

/**
 * Turns advisor payloads into translation parameters. Values are formatted by name:
 * `*_uzs` are sums, `amount` and `*_amount` sums in the payload's `currency`, `*_on` dates, `*months` one-decimal
 * numbers, `*month` months (YYYY-MM); `category_id` adds the category's name as `category`, and
 * `goal_id` the goal's title as `goal`.
 */
final class Texts
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function params(array $payload, string $locale): array
    {
        $params = [];
        foreach ($payload as $key => $value) {
            if ($value === null || is_array($value) || in_array($key, ['category_id', 'goal_id', 'account_id', 'variant'], true)) {
                continue;
            }
            $params[$key] = match (true) {
                str_ends_with($key, '_uzs') => Format::uzs((int) $value, $locale),
                $key === 'amount' || str_ends_with($key, '_amount') => Format::money((int) $value, $payload['currency'] ?? 'UZS', $locale),
                str_ends_with($key, '_on') => Format::dayMonth((string) $value, $locale),
                str_ends_with($key, 'months') => Format::decimal((float) $value),
                str_ends_with($key, 'month') => Format::month((string) $value, $locale),
                default => (string) $value,
            };
        }
        if (isset($payload['category_id'])) {
            $params['category'] = Category::find($payload['category_id'])?->names()[$locale] ?? '—';
        }

        if (isset($payload['goal_id'])) {
            $params['goal'] = Goal::find($payload['goal_id'])?->title ?? '—';
        }

        return $params;
    }
}
