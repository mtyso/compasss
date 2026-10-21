<?php

use App\Models\Transaction;
use App\Models\User;
use App\Telegram\WebAppInitData;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const CMD_OWNER = 111222333;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 09:00', 'Asia/Tashkent'));
    config([
        'app.url' => 'https://kompas.example.uz',
        'telegram.bot_token' => 'test-token',
        'telegram.webhook_secret' => 'hook-secret',
        'telegram.allowed_ids' => [(string) CMD_OWNER],
        'telegram.webapp_url' => 'https://kompas.example.uz',
        'telegram.rich_messages' => true,
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 70]])]);

    $this->user = User::factory()->create(['telegram_id' => CMD_OWNER, 'locale' => 'ru']);
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 3_000_000]);
    $this->user->incomeSchedules()->create(['name' => 'Зарплата', 'day_of_month' => 30, 'expected_amount' => 10_000_000, 'currency' => 'UZS', 'active' => true]);
    $this->internet = $this->user->recurringPayments()->create([
        'name' => 'Интернет', 'amount' => 150_000, 'currency' => 'UZS', 'account_id' => $this->card->id,
        'category_id' => $this->user->categories()->firstWhere('system_key', 'home_internet')->id,
        'day_of_month' => 21, 'next_due_on' => '2026-10-21', 'active' => true,
    ]);
});

function cmdCalls(string $method): array
{
    return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), "/{$method}"))
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

function cmdSend(array $update): void
{
    test()->postJson('/telegram/webhook', ['update_id' => 3, ...$update], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
}

function cmdText(string $text): void
{
    cmdSend(['message' => [
        'message_id' => 1, 'text' => $text,
        'from' => ['id' => CMD_OWNER, 'first_name' => 'Elyor'],
        'chat' => ['id' => CMD_OWNER, 'type' => 'private'],
    ]]);
}

function cmdNb(string $text): string
{
    return preg_replace('/(?<=\d) (?=\d)/u', "\u{202F}", $text);
}

it('answers /today with the limit, the payday and the bills before it', function () {
    $this->user->transactions()->create([
        'type' => 'expense', 'account_id' => $this->card->id, 'amount' => 50_000,
        'currency' => 'UZS', 'rate' => '1', 'amount_uzs' => 50_000, 'occurred_at' => now(),
        'category_id' => $this->user->categories()->firstWhere('system_key', 'cafe')->id,
    ]);

    cmdText('/today');

    // 2 950 000 on the card − 150 000 internet = 2 800 000 free; + 50 000 spent today = 2 850 000 / 9 days
    $html = cmdCalls('sendRichMessage')[0]['rich_message']['html'];
    expect($html)
        ->toContain(cmdNb('<h2>Сегодня можно 266 666 сум</h2>'))
        ->toContain(cmdNb('Лимит дня 316 666 сум · потрачено 50 000 сум'))
        ->toContain(cmdNb('<td>Свободно до дохода</td><td align="right"><b>2 800 000 сум</b></td>'))
        ->toContain('30 октября, через 9 дней')
        ->toContain(cmdNb('Интернет — 150 000 сум, 21 октября'))
        ->toContain('<footer>Среда, 21 октября</footer>');

    $keyboard = cmdCalls('sendRichMessage')[0]['reply_markup']['inline_keyboard'];
    expect($keyboard[0][0])->toMatchArray(['callback_data' => "pay:{$this->internet->id}", 'style' => 'success'])
        ->and($keyboard[1][0]['web_app']['url'])->toBe('https://kompas.example.uz/');
});

it('pays a bill from the summary once and redraws it', function () {
    $press = fn () => cmdSend(['callback_query' => [
        'id' => 'q', 'data' => "pay:{$this->internet->id}",
        'from' => ['id' => CMD_OWNER, 'first_name' => 'Elyor'],
        'message' => ['message_id' => 70, 'chat' => ['id' => CMD_OWNER, 'type' => 'private']],
    ]]);

    $press();
    $press();

    expect(Transaction::sole())->amount->toBe(150_000)->recurring_payment_id->toBe($this->internet->id)
        ->and($this->internet->fresh()->next_due_on)->toBe('2026-11-21')
        ->and(cmdCalls('answerCallbackQuery')[0]['text'])->toBe(cmdNb('Оплачено: Интернет, 150 000 сум'))
        ->and(cmdCalls('answerCallbackQuery')[1]['text'])->toBe(__('bot.pay.already'))
        ->and(cmdCalls('editMessageText')[0]['reply_markup']['inline_keyboard'])->toHaveCount(1);
});

it('answers /month with spending by category against the budget', function () {
    $cafe = $this->user->categories()->firstWhere('system_key', 'cafe');
    $coffee = $this->user->categories()->firstWhere('system_key', 'cafe_coffee');
    $this->user->budgets()->create(['month' => '2026-10', 'category_id' => $cafe->id, 'limit_uzs' => 100_000]);
    foreach ([[$coffee, 80_000], [$cafe, 40_000]] as [$category, $amount]) {
        $this->user->transactions()->create([
            'type' => 'expense', 'account_id' => $this->card->id, 'amount' => $amount,
            'currency' => 'UZS', 'rate' => '1', 'amount_uzs' => $amount, 'occurred_at' => now()->subDay(), 'category_id' => $category->id,
        ]);
    }

    cmdText('/month');

    expect(cmdCalls('sendRichMessage')[0]['rich_message']['html'])
        ->toContain(cmdNb('<h2>Октябрь: потрачено 120 000 сум</h2>'))
        ->toContain(cmdNb('<td>🍽 Кафе и рестораны</td><td align="right">120 000</td><td align="right"><b>120%</b></td>'));
});

it('sends the morning summary with the advice that waited for it, once', function () {
    $cafe = $this->user->categories()->firstWhere('system_key', 'cafe');
    $insight = $this->user->insights()->create([
        'rule' => 'budget_threshold', 'severity' => 'warn', 'period_key' => '2026-10:near', 'subject' => (string) $cafe->id,
        'payload' => ['variant' => 'near', 'category_id' => $cafe->id, 'percent' => 85, 'left_uzs' => 30_000], 'status' => 'new',
    ]);

    $this->artisan('bot:digest morning')->assertSuccessful();
    $this->artisan('bot:notify')->assertSuccessful();

    expect(cmdCalls('sendRichMessage'))->toHaveCount(1)
        ->and(cmdCalls('sendRichMessage')[0]['rich_message']['html'])
        ->toContain('Доброе утро! Сегодня можно')
        ->toContain('<h4>🟠 Кафе и рестораны: 85% бюджета</h4>')
        ->and($insight->fresh()->sent_via)->toBe(['telegram']);
});

it('stays silent before the bot token is set', function () {
    config(['telegram.bot_token' => null]);

    $this->artisan('bot:digest morning')->assertSuccessful();
    $this->artisan('bot:notify')->assertSuccessful();

    expect(cmdCalls('sendRichMessage'))->toBeEmpty();
});

it('sends weekly totals compared with the week before', function () {
    $cafe = $this->user->categories()->firstWhere('system_key', 'cafe_coffee');
    foreach ([[now()->subDays(2), 300_000], [now()->subDays(9), 500_000]] as [$at, $amount]) {
        $this->user->transactions()->create([
            'type' => 'expense', 'account_id' => $this->card->id, 'amount' => $amount,
            'currency' => 'UZS', 'rate' => '1', 'amount_uzs' => $amount, 'occurred_at' => $at, 'category_id' => $cafe->id,
        ]);
    }

    $this->artisan('bot:digest weekly')->assertSuccessful();

    expect(cmdCalls('sendRichMessage')[0]['rich_message']['html'])
        ->toContain(cmdNb('Неделя: потрачено 300 000 сум'))
        ->toContain(cmdNb('На 200 000 сум меньше, чем неделей раньше.'))
        ->toContain('🍽 Кафе и рестораны');
});

it('sets up the webhook, commands in both languages and the Mini App menu button', function () {
    $this->artisan('telegram:setup')->assertSuccessful();

    expect(cmdCalls('setWebhook')[0])->toMatchArray(['url' => 'https://kompas.example.uz/telegram/webhook', 'secret_token' => 'hook-secret'])
        ->and(cmdCalls('setChatMenuButton')[0]['menu_button'])->toBe(['type' => 'web_app', 'text' => 'Компас', 'web_app' => ['url' => 'https://kompas.example.uz/']])
        ->and(cmdCalls('setMyCommands'))->toHaveCount(2)
        ->and(cmdCalls('setMyCommands')[1]['language_code'])->toBe('uz')
        ->and(collect(cmdCalls('setMyCommands')[0]['commands'])->pluck('command')->all())->toBe(['today', 'month', 'help']);
});

it('signs in the Mini App with launch data and replaces the token of the same Telegram app', function () {
    $initData = fn () => WebAppInitData::sign([
        'user' => json_encode(['id' => CMD_OWNER, 'first_name' => 'Elyor', 'username' => 'elyor']),
        'auth_date' => (string) now()->timestamp,
    ], 'test-token');

    $first = $this->postJson('/api/auth/telegram/webapp', ['init_data' => $initData(), 'platform' => 'android'])
        ->assertOk()->assertJsonPath('user.id', $this->user->id)->json('token');
    $second = $this->postJson('/api/auth/telegram/webapp', ['init_data' => $initData(), 'platform' => 'android'])->json('token');

    expect($this->user->tokens()->pluck('name')->all())->toBe(['Telegram · android']);
    $this->getJson('/api/me', ['Authorization' => "Bearer {$second}"])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/me', ['Authorization' => "Bearer {$first}"])->assertUnauthorized();
});

it('refuses forged, stale or foreign Mini App launch data', function () {
    $signed = fn (int $id, string $token = 'test-token', ?int $at = null) => WebAppInitData::sign([
        'user' => json_encode(['id' => $id, 'first_name' => 'X']),
        'auth_date' => (string) ($at ?? now()->timestamp),
    ], $token);

    $this->postJson('/api/auth/telegram/webapp', ['init_data' => $signed(CMD_OWNER, 'wrong-token')])->assertUnauthorized();
    $this->postJson('/api/auth/telegram/webapp', ['init_data' => $signed(CMD_OWNER, at: now()->subDays(2)->timestamp)])->assertUnauthorized();
    $this->postJson('/api/auth/telegram/webapp', ['init_data' => $signed(555)])->assertForbidden();

    config(['telegram.bot_token' => null]);
    $this->postJson('/api/auth/telegram/webapp', ['init_data' => $signed(CMD_OWNER)])->assertStatus(503);

    expect($this->user->tokens()->count())->toBe(0);
});
