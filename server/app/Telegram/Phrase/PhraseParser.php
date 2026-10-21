<?php

namespace App\Telegram\Phrase;

use App\Support\AmountParser;

/**
 * Turns a chat message into an operation (docs/PLAN.md, section 9):
 * «такси 32к», «обед 85 000», «кофе 22000 налик», «$20 подписка», «+500к зарплата»,
 * and recognises «можно купить наушники 600к?» as a question, not a purchase.
 */
final class PhraseParser
{
    private const AMOUNT = '/(?<![\p{L}\d.,])'
        .'(?<pre>\$\s?)?'
        .'(?<num>\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)?)'
        .'(?:\s?(?<suf>тыс|млн|mln|ming|к|k|м|m)(?!\p{L}))?'
        .'(?:\s?(?<cur>\$|usd|долл\p{L}*|dollar\p{L}*|сум(?!\p{L})|so[\'ʻ’‘`]?m|sum(?!\p{L})|uzs))?'
        .'(?![\p{L}\d])/u';

    private const USD_WORDS = '/^(\$|usd|долл\p{L}*|dollar\p{L}*)$/u';

    /** Words that say «paid in cash» or «by card»: they pick the account and leave the note. */
    private const WALLET_WORDS = [
        'cash' => ['налик', 'наликом', 'наличка', 'наличкой', 'наличные', 'наличными', 'нал', 'кэш', 'кеш', 'cash', 'naqd', 'naqdga', 'naqd pul'],
        'card' => ['картой', 'карта', 'карточкой', 'karta', 'kartadan', 'kartaga', 'card'],
    ];

    private const QUESTION = '/\?|^(можно|могу ли|стоит ли|хватит ли|потяну ли)\b|купить|sotib ol|olsam|bo[\'ʻ’]?ladimi|arziydimi/u';

    public static function parse(string $text): ?ParsedPhrase
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        $lower = mb_strtolower($text);

        $kind = 'expense';
        if (preg_match('/^[+]/u', $lower)) {
            $kind = 'income';
            $lower = ltrim(mb_substr($lower, 1));
            $text = ltrim(mb_substr($text, 1));
        } elseif (preg_match('/^[-−–]/u', $lower)) {
            $lower = ltrim(mb_substr($lower, 1));
            $text = ltrim(mb_substr($text, 1));
        } elseif (preg_match(self::QUESTION, $lower)) {
            $kind = 'question';
        }

        $token = self::pickAmount($lower);
        if ($token === null) {
            return null;
        }

        $currency = $token['pre'] !== '' || preg_match(self::USD_WORDS, $token['cur']) ? 'USD' : 'UZS';
        $number = str_replace(',', '.', preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $token['num']));
        $amount = AmountParser::toMinor($number, $token['suf'], $currency);
        if ($amount === null) {
            return null;
        }

        // Cut the amount out of the original text (same offsets: lowercasing keeps lengths in these scripts).
        $rest = mb_substr($text, 0, $token['offset']).' '.mb_substr($text, $token['offset'] + $token['length']);
        [$note, $wallet] = self::extractWallet($rest);

        if ($kind === 'question') {
            $note = self::questionSubject($note);
        }

        return new ParsedPhrase($kind, $amount, $currency, $note, $wallet);
    }

    /**
     * The amount among the numbers of the phrase: one with a currency or a «к/м» suffix wins,
     * otherwise the largest («такси 2 раза 32к», «2 кофе 22000»).
     *
     * @return array{pre: string, num: string, suf: string, cur: string, offset: int, length: int}|null
     */
    private static function pickAmount(string $lower): ?array
    {
        if (! preg_match_all(self::AMOUNT, $lower, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        $tokens = array_map(fn (array $m) => [
            'pre' => $m['pre'][0] ?? '',
            'num' => $m['num'][0],
            'suf' => $m['suf'][0] ?? '',
            'cur' => trim($m['cur'][0] ?? ''),
            // PREG_OFFSET_CAPTURE counts bytes; the note is cut by characters.
            'offset' => mb_strlen(substr($lower, 0, $m[0][1])),
            'length' => mb_strlen($m[0][0]),
        ], $matches);

        $marked = array_values(array_filter($tokens, fn ($t) => $t['pre'] !== '' || $t['suf'] !== '' || $t['cur'] !== ''));
        if ($marked !== []) {
            return $marked[0];
        }

        usort($tokens, fn ($a, $b) => self::rough($b) <=> self::rough($a));

        return $tokens[0];
    }

    private static function rough(array $token): float
    {
        return (float) str_replace(',', '.', preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $token['num']));
    }

    /** @return array{0: string, 1: 'cash'|'card'|null} */
    private static function extractWallet(string $text): array
    {
        $wallet = null;
        foreach (self::WALLET_WORDS as $kind => $words) {
            $pattern = '/(?<!\p{L})('.implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words)).')(?!\p{L})/iu';
            $cleaned = preg_replace($pattern, ' ', $text, -1, $count);
            if ($count > 0) {
                $wallet ??= $kind;
                $text = $cleaned;
            }
        }

        return [self::tidy($text), $wallet];
    }

    /** «можно купить наушники ?» → «наушники». */
    private static function questionSubject(string $note): string
    {
        $note = preg_replace('/(?<!\p{L})(можно|ли|я|мне|купить|взять|стоит|хватит|потяну|за|sotib|olsam|olish|bo[\'ʻ’]?ladimi|arziydimi|mumkinmi)(?!\p{L})/iu', ' ', $note);

        return self::tidy($note);
    }

    private static function tidy(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text), " \t\n\r\0\x0B.,;:!?—–-");

        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
