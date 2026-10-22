<?php

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const BOT_OWNER = 111222333;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 14:20', 'Asia/Tashkent'));
    config([
        'telegram.bot_token' => 'test-token',
        'telegram.webhook_secret' => 'hook-secret',
        'telegram.allowed_ids' => [(string) BOT_OWNER],
        'telegram.webapp_url' => 'https://kompas.example.uz',
        'telegram.rich_messages' => true,
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 50]])]);

    $this->user = User::factory()->create(['telegram_id' => BOT_OWNER, 'locale' => 'ru']);
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 3_000_000]);
    $this->cash = $this->user->accounts()->create(['name' => 'Кошелёк', 'type' => 'cash', 'currency' => 'UZS', 'opening_balance' => 500_000]);
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-10-21', 'rate' => '12000.0000']);
});

function botSay(string $text, int $from = BOT_OWNER): void
{
    test()->postJson('/telegram/webhook', ['update_id' => 1, 'message' => [
        'message_id' => 10,
        'from' => ['id' => $from, 'first_name' => 'Elyor', 'language_code' => 'ru'],
        'chat' => ['id' => $from, 'type' => 'private'],
        'text' => $text,
    ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
}

function botTap(string $data, int $from = BOT_OWNER): void
{
    test()->postJson('/telegram/webhook', ['update_id' => 2, 'callback_query' => [
        'id' => 'cb', 'data' => $data,
        'from' => ['id' => $from, 'first_name' => 'Elyor'],
        'message' => ['message_id' => 50, 'chat' => ['id' => $from, 'type' => 'private']],
    ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
}

/** Numbers in cards are grouped with a narrow no-break space, as in the app. */
function nb(string $text): string
{
    return preg_replace('/(?<=\d) (?=\d)/u', "\u{202F}", $text);
}

/** @return list<array<string, mixed>> */
function botCalls(string $method): array
{
    return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), "/{$method}"))
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

function botLastCard(): array
{
    return collect(botCalls('sendRichMessage'))->last();
}

/** @return list<string> every button's callback data or web app address */
function botButtons(array $markup): array
{
    return collect($markup['inline_keyboard'] ?? [])->flatten(1)
        ->map(fn ($b) => $b['callback_data'] ?? $b['web_app']['url'] ?? $b['url'])->all();
}

it('records «такси 32к» as a taxi expense from the main card and answers with a rich card', function () {
    botSay('такси 32к');

    $tx = Transaction::sole();
    expect($tx->type)->toBe('expense')
        ->and($tx->amount)->toBe(32_000)
        ->and($tx->account_id)->toBe($this->card->id)
        ->and($tx->category->system_key)->toBe('transport_taxi')
        ->and($tx->note)->toBe('Такси')
        ->and($tx->source)->toBe('bot');

    $card = botLastCard();
    expect($card['chat_id'])->toBe(BOT_OWNER)
        ->and($card['rich_message']['html'])
        ->toContain(nb('<h2>−32 000 сум · Такси</h2>'))
        ->toContain('🚕 Такси · Транспорт')
        ->toContain('💳 Humo')
        ->toContain('Сегодня можно ещё')
        ->toContain('<footer>Записано из Telegram · сегодня, 14:20</footer>')
        ->and(botButtons($card['reply_markup']))->toBe([
            "tx:cats:{$tx->id}", "tx:accs:{$tx->id}", "tx:del:{$tx->id}", 'https://kompas.example.uz/money',
        ])
        ->and($card['reply_markup']['inline_keyboard'][1][0]['style'])->toBe('danger');
});

it('shows how much is left today and how much tomorrow when the limit is blown', function () {
    // 3 500 000 to spend, no paydays: 11 days to the month end, 318 181 a day.
    botSay('обед 85 000');
    expect(botLastCard()['rich_message']['html'])->toContain(nb('Сегодня можно ещё <b>233 181 сум</b> из 318 181 сум.'))
        ->toContain('До конца месяца — 11 дней.');

    botSay('телефон 400к');
    expect(botLastCard()['rich_message']['html'])->toContain(nb('Лимит на сегодня превышен на <b>166 819 сум</b>.'))
        ->toContain(nb('Завтра будет 301 500 сум на день.'));
});

it('takes «налик» to the cash account and «$20» to som at the CBU rate', function () {
    botSay('кофе 22000 налик');
    expect(Transaction::latest('id')->first()->account_id)->toBe($this->cash->id);

    botSay('$20 подписка');
    $tx = Transaction::latest('id')->first();
    expect($tx->account_id)->toBe($this->card->id)
        ->and($tx->amount)->toBe(240_000)
        ->and($tx->note)->toBe('Подписка ($20,00)')
        ->and($tx->category->system_key)->toBe('connection_subscriptions');
});

it('keeps dollars in a dollar account when there is one', function () {
    $dollars = $this->user->accounts()->create(['name' => 'Visa $', 'type' => 'card', 'currency' => 'USD', 'opening_balance' => 50_000]);

    botSay('$12.50 icloud');

    $tx = Transaction::sole();
    expect($tx->account_id)->toBe($dollars->id)
        ->and($tx->amount)->toBe(1_250)
        ->and($tx->amount_uzs)->toBe(150_000)
        ->and(botLastCard()['rich_message']['html'])->toContain('−$12,50 · Icloud')->toContain(nb('≈ 150 000 сум по курсу ЦБ'));
});

it('records «+500к зарплата» and «зарплата 12м» as income', function () {
    botSay('+500к зарплата');
    botSay('зарплата 12м');

    expect(Transaction::pluck('type')->all())->toBe(['income', 'income'])
        ->and(Transaction::first()->category->system_key)->toBe('salary')
        ->and(botLastCard()['rich_message']['html'])->toContain(nb('+12 000 000 сум · Зарплата'))->toContain('Лимит на сегодня теперь');
});

it('remembers a category changed by hand for the same words', function () {
    botSay('ашхана 40к');
    $tx = Transaction::sole();
    expect($tx->category->system_key)->toBe('other_expense');

    $cafe = $this->user->categories()->firstWhere('system_key', 'cafe_restaurants');
    botTap("tx:cat:{$tx->id}:{$cafe->id}");
    botSay('Ашхана 35к');

    expect(Transaction::latest('id')->first()->category_id)->toBe($cafe->id);
});

it('opens the category picker, its subcategories, and saves the choice in place', function () {
    botSay('такси 32к');
    $tx = Transaction::sole();
    $transport = $this->user->categories()->firstWhere('system_key', 'transport');
    $public = $this->user->categories()->firstWhere('system_key', 'transport_public');
    $clothes = $this->user->categories()->firstWhere('system_key', 'clothes');

    botTap("tx:cats:{$tx->id}");
    $picker = botButtons(botCalls('editMessageReplyMarkup')[0]['reply_markup']);
    expect($picker)->toContain("tx:sub:{$tx->id}:{$transport->id}")
        ->toContain("tx:cat:{$tx->id}:{$clothes->id}")
        ->and(end($picker))->toBe("tx:card:{$tx->id}");

    botTap("tx:sub:{$tx->id}:{$transport->id}");
    expect(botButtons(botCalls('editMessageReplyMarkup')[1]['reply_markup']))
        ->toContain("tx:cat:{$tx->id}:{$public->id}")
        ->toContain("tx:cat:{$tx->id}:{$transport->id}")
        ->toContain("tx:cats:{$tx->id}");

    botTap("tx:cat:{$tx->id}:{$public->id}");
    expect($tx->fresh()->category_id)->toBe($public->id);

    $edit = botCalls('editMessageText')[0];
    expect($edit['message_id'])->toBe(50)
        ->and($edit['rich_message']['html'])->toContain('🚇 Общественный транспорт · Транспорт');
});

it('moves an operation to another account of the same currency', function () {
    $this->user->accounts()->create(['name' => 'Visa $', 'type' => 'card', 'currency' => 'USD']);
    botSay('такси 32к');
    $tx = Transaction::sole();

    botTap("tx:accs:{$tx->id}");
    expect(botButtons(botCalls('editMessageReplyMarkup')[0]['reply_markup']))
        ->toBe(["tx:acc:{$tx->id}:{$this->card->id}", "tx:acc:{$tx->id}:{$this->cash->id}", "tx:card:{$tx->id}"]);

    botTap("tx:acc:{$tx->id}:{$this->cash->id}");
    expect($tx->fresh()->account_id)->toBe($this->cash->id)
        ->and($tx->fresh()->rate)->toBe('1.0000');
});

it('undoes and restores from the buttons', function () {
    botSay('такси 32к');
    $tx = Transaction::sole();

    botTap("tx:del:{$tx->id}");
    expect(Transaction::count())->toBe(0)
        ->and(botCalls('editMessageText')[0]['rich_message']['html'])->toContain('<s>')->toContain('Запись удалена')
        ->and(botButtons(botCalls('editMessageText')[0]['reply_markup']))->toBe(["tx:restore:{$tx->id}"]);

    botTap("tx:cats:{$tx->id}");
    expect(collect(botCalls('answerCallbackQuery'))->last()['text'])->toBe(__('bot.record.gone'));

    botTap("tx:restore:{$tx->id}");
    expect(Transaction::count())->toBe(1);
});

it('counts «интернет 150к» as the internet payment due, and undo makes it due again', function () {
    $payment = $this->user->recurringPayments()->create([
        'name' => 'Интернет', 'amount' => 150_000, 'currency' => 'UZS',
        'category_id' => $this->user->categories()->firstWhere('system_key', 'home_internet')->id,
        'account_id' => $this->card->id, 'day_of_month' => 21, 'next_due_on' => '2026-10-21', 'active' => true,
    ]);

    botSay('интернет 150к');
    $tx = Transaction::sole();
    expect($tx->recurring_payment_id)->toBe($payment->id)
        ->and(botLastCard()['rich_message']['html'])->toContain('✅ Засчитано как платёж «Интернет». Следующий — 21 ноября.')
        ->and($payment->fresh()->next_due_on)->toBe('2026-11-21');

    botTap("tx:del:{$tx->id}");
    expect($payment->fresh()->next_due_on)->toBe('2026-10-21');
    botTap("tx:restore:{$tx->id}");
    expect($payment->fresh()->next_due_on)->toBe('2026-11-21');
});

it('asks for an account first when there is none', function () {
    $this->user->accounts()->delete();

    botSay('такси 32к');

    expect(Transaction::count())->toBe(0)
        ->and(botLastCard()['rich_message']['html'])->toContain(__('bot.record.no_accounts'))
        ->and(botButtons(botLastCard()['reply_markup']))->toBe(['https://kompas.example.uz/more/accounts/new']);
});

it('explains the format when there is no amount', function () {
    botSay('привет');

    expect(Transaction::count())->toBe(0)
        ->and(botLastCard()['rich_message']['html'])->toContain('<code>такси 32к</code>');
});

it('does not let a stranger record anything or press the buttons', function () {
    botSay('такси 32к');
    $tx = Transaction::sole();

    botSay('такси 99к', 555);
    botTap("tx:del:{$tx->id}", 555);

    expect(Transaction::count())->toBe(1)
        ->and(collect(botCalls('sendMessage'))->last()['text'])->toBe(__('bot.private'));
});

it('falls back to a plain HTML message when Telegram refuses the rich one', function () {
    Http::swap(new Factory);
    Http::fake([
        'api.telegram.org/*/sendRichMessage' => Http::response(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse rich message"], 400),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 51]]),
    ]);

    botSay('такси 32к');

    $plain = botCalls('sendMessage')[0];
    expect($plain['parse_mode'])->toBe('HTML')
        ->and($plain['text'])->toStartWith(nb('<b>−32 000 сум · Такси</b>'))
        ->and($plain['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toStartWith('tx:cats:');
});

it('does not resend when Telegram did not answer at all', function () {
    Http::swap(new Factory);
    Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('timed out')]);

    botSay('такси 32к');

    expect(Transaction::count())->toBe(1)
        ->and(botCalls('sendMessage'))->toBe([]);
});

it('sends plain HTML only when rich messages are switched off', function () {
    config(['telegram.rich_messages' => false]);

    botSay('такси 32к');

    expect(botCalls('sendRichMessage'))->toBe([])
        ->and(botCalls('sendMessage')[0]['text'])->toContain('<blockquote>Сегодня можно ещё');
});

it('leaves out Mini App buttons when the app address is not https', function () {
    config(['telegram.webapp_url' => 'http://localhost:8000']);

    botSay('такси 32к');

    expect(botButtons(botLastCard()['reply_markup']))->each->toStartWith('tx:');
});

it('answers in Uzbek for an Uzbek account', function () {
    $this->user->update(['locale' => 'uz']);

    botSay('taksi 15 ming');

    expect(botLastCard()['rich_message']['html'])->toContain(nb('−15 000 so&#039;m · Taksi'))->toContain('Bugun yana');
});
