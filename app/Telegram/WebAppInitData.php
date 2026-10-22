<?php

namespace App\Telegram;

/**
 * Checks the launch data Telegram hands a Mini App (Telegram.WebApp.initData):
 * a query string signed with the bot token, so the user in it is who Telegram says.
 *
 *   secret = HMAC-SHA256(key: "WebAppData", message: bot token)
 *   hash   = hex HMAC-SHA256(key: secret, message: data-check-string)
 *
 * The data-check-string is every field but `hash`, sorted by key, as key=value lines.
 */
final class WebAppInitData
{
    /**
     * @return array<string, string>|null the fields when the signature holds and the data is fresh enough
     */
    public static function validate(string $initData, string $botToken, int $maxAgeSeconds, ?int $now = null): ?array
    {
        $fields = [];
        foreach (explode('&', $initData) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[urldecode($key)] = urldecode($value);
        }

        $hash = $fields['hash'] ?? null;
        unset($fields['hash']);
        if (! is_string($hash) || ! isset($fields['auth_date'], $fields['user']) || $botToken === '') {
            return null;
        }

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        // Bot API 8.0 added a `signature` field for third-party checks. It stays in the string
        // for the HMAC check; dropping it is tried too in case a client signs without it.
        $valid = hash_equals(hash_hmac('sha256', self::checkString($fields), $secret), $hash)
            || (isset($fields['signature']) && hash_equals(hash_hmac('sha256', self::checkString(array_diff_key($fields, ['signature' => 1])), $secret), $hash));

        if (! $valid) {
            return null;
        }

        $age = ($now ?? time()) - (int) $fields['auth_date'];
        if ($age > $maxAgeSeconds || $age < -300) {
            return null;
        }

        return $fields;
    }

    /** @param  array<string, string>  $fields */
    public static function checkString(array $fields): string
    {
        ksort($fields, SORT_STRING);

        return implode("\n", array_map(fn ($key, $value) => "{$key}={$value}", array_keys($fields), $fields));
    }

    /** Builds signed launch data, for tests and local runs without Telegram. */
    public static function sign(array $fields, string $botToken): string
    {
        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $fields['hash'] = hash_hmac('sha256', self::checkString($fields), $secret);

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }
}
