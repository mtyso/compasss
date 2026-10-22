<?php

namespace App\Telegram\Phrase;

final class ParsedPhrase
{
    /**
     * @param  'expense'|'income'|'question'  $kind
     * @param  int  $amount  minor units of $currency
     * @param  'cash'|'card'|null  $wallet  «налик», «картой»: which kind of account was named
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $note,
        public readonly ?string $wallet = null,
    ) {}
}
