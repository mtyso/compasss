<?php

use App\Support\AmountParser;
use App\Telegram\Phrase\CategoryGuesser;
use App\Telegram\Phrase\PhraseParser;

it('reads amounts like the app does', function (string $input, string $currency, ?int $minor) {
    expect(AmountParser::parse($input, $currency))->toBe($minor);
})->with([
    ['32000', 'UZS', 32_000],
    ['32 000', 'UZS', 32_000],
    ['32к', 'UZS', 32_000],
    ['1,5м', 'UZS', 1_500_000],
    ['1.5 млн', 'UZS', 1_500_000],
    ['15 ming', 'UZS', 15_000],
    ['12.50', 'USD', 1_250],
    ['0', 'UZS', null],
    ['abc', 'UZS', null],
]);

it('turns phrases into operations', function (string $text, string $kind, int $amount, string $currency, string $note, ?string $wallet) {
    $phrase = PhraseParser::parse($text);

    expect($phrase)->not->toBeNull()
        ->and([$phrase->kind, $phrase->amount, $phrase->currency, $phrase->note, $phrase->wallet])
        ->toBe([$kind, $amount, $currency, $note, $wallet]);
})->with([
    'suffix' => ['такси 32к', 'expense', 32_000, 'UZS', 'Такси', null],
    'spaced thousands' => ['обед 85 000', 'expense', 85_000, 'UZS', 'Обед', null],
    'cash word' => ['кофе 22000 налик', 'expense', 22_000, 'UZS', 'Кофе', 'cash'],
    'dollar in front' => ['$20 подписка', 'expense', 2_000, 'USD', 'Подписка', null],
    'dollar after' => ['20$ netflix', 'expense', 2_000, 'USD', 'Netflix', null],
    'cents' => ['$12.50 icloud', 'expense', 1_250, 'USD', 'Icloud', null],
    'plus is income' => ['+500к зарплата', 'income', 500_000, 'UZS', 'Зарплата', null],
    'millions' => ['1,5 млн аренда', 'expense', 1_500_000, 'UZS', 'Аренда', null],
    'uzbek' => ['taksi 15 ming kartadan', 'expense', 15_000, 'UZS', 'Taksi', 'card'],
    'currency word' => ["15 000 so'm non", 'expense', 15_000, 'UZS', 'Non', null],
    'marked amount wins' => ['такси 2 раза 32к', 'expense', 32_000, 'UZS', 'Такси 2 раза', null],
    'largest number wins' => ['2 кофе 22000', 'expense', 22_000, 'UZS', '2 кофе', null],
    'question' => ['можно купить наушники 600к?', 'question', 600_000, 'UZS', 'Наушники', null],
    'uzbek question' => ["quloqchin 600k olsam bo'ladimi?", 'question', 600_000, 'UZS', 'Quloqchin', null],
]);

it('ignores messages without an amount', function () {
    expect(PhraseParser::parse('привет'))->toBeNull()
        ->and(PhraseParser::parse('такси'))->toBeNull();
});

it('finds the category from keywords in both languages', function (string $note, ?string $kind, ?string $key) {
    expect(CategoryGuesser::keyFor($note, $kind))->toBe($key);
})->with([
    ['Такси до дома', 'expense', 'transport_taxi'],
    ['Taksi', 'expense', 'transport_taxi'],
    ['Кофе', 'expense', 'cafe_coffee'],
    ['Обед с коллегами', 'expense', 'cafe_restaurants'],
    ['Аптека', 'expense', 'health_pharmacy'],
    ['Korzinka', 'expense', 'groceries_supermarket'],
    ['Ремонт машины', 'expense', 'transport_service'],
    ['Ремонт в ванной', 'expense', 'home_repair'],
    ['Подписка Netflix', 'expense', 'connection_subscriptions'],
    ['Зарплата', 'income', 'salary'],
    ['Зарплата', 'expense', null],
    ['Что-то странное', 'expense', null],
]);

it('hears an income in «зарплата 12м» but not in «премиум»', function () {
    expect(CategoryGuesser::soundsLikeIncome('Зарплата'))->toBeTrue()
        ->and(CategoryGuesser::soundsLikeIncome('Maosh'))->toBeTrue()
        ->and(CategoryGuesser::soundsLikeIncome('Telegram премиум'))->toBeFalse()
        ->and(CategoryGuesser::soundsLikeIncome('Такси'))->toBeFalse();
});
