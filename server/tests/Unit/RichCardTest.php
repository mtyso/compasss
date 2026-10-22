<?php

use App\Telegram\Rich\Card;

it('renders the same card as a rich message and as plain Telegram HTML', function () {
    $card = Card::make()
        ->heading('−32 000 сум · Такси & Ко', 2)
        ->paragraph('🚕 <b>Такси</b>')
        ->facts([['Счёт', 'Humo']])
        ->table(['Категория', 'Сумма'], [['Кафе', '120 000']])
        ->list(['<code>такси 32к</code>'])
        ->quote('Сегодня можно ещё <b>58 000</b>')
        ->divider()
        ->footer('Записано из Telegram');

    expect($card->rich())->toBe(
        '<h2>−32 000 сум · Такси &amp; Ко</h2>'
        .'<p>🚕 <b>Такси</b></p>'
        .'<table compact><tr><td>Счёт</td><td align="right">Humo</td></tr></table>'
        .'<table compact striped><tr><th>Категория</th><th align="right">Сумма</th></tr><tr><td>Кафе</td><td align="right">120 000</td></tr></table>'
        .'<ul><li><code>такси 32к</code></li></ul>'
        .'<blockquote>Сегодня можно ещё <b>58 000</b></blockquote>'
        .'<hr/>'
        .'<footer>Записано из Telegram</footer>'
    );

    expect($card->legacy())->toBe(implode("\n\n", [
        '<b>−32 000 сум · Такси &amp; Ко</b>',
        '🚕 <b>Такси</b>',
        'Счёт: Humo',
        'Кафе · 120 000',
        '• <code>такси 32к</code>',
        '<blockquote>Сегодня можно ещё <b>58 000</b></blockquote>',
        '<i>Записано из Telegram</i>',
    ]));
});

it('escapes quotes with numeric entities, which both formats accept', function () {
    expect(Card::e("so'm <b>"))->toBe('so&#039;m &lt;b&gt;');
});

it('leaves out empty tables and lists', function () {
    expect(Card::make()->facts([])->table(['A'], [])->list([])->rich())->toBe('');
});
