<?php

use App\Models\Transaction;
use App\Models\User;
use App\Telegram\WebAppInitData;
use Illuminate\Http\Client\Factory;
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

it('sends last month\'s report on the 1st: kept share, against the usual, budget and goals', function () {
    $this->travelTo(Carbon::parse('2026-11-01 20:00', 'Asia/Tashkent'));
    $op = fn (string $type, int $amount, string $when, string $key, array $extra = []) => $this->user->transactions()->create([
        'type' => $type, 'account_id' => $this->card->id, 'amount' => $amount, 'currency' => 'UZS', 'rate' => '1', 'amount_uzs' => $amount,
        'occurred_at' => Carbon::parse($when, 'Asia/Tashkent'), 'category_id' => $this->user->categories()->firstWhere('system_key', $key)->id, ...$extra,
    ]);
    foreach (['08' => 500_000, '09' => 500_000, '10' => 900_000] as $m => $cafe) {
        $op('income', 10_000_000, "2026-{$m}-03 09:00", 'salary');
        $op('expense', $cafe, "2026-{$m}-10 20:00", 'cafe');
    }
    $op('expense', 1_000_000, '2026-10-20 12:00', 'clothes', ['is_one_off' => true]);
    $this->user->budgets()->create(['month' => '2026-10', 'category_id' => $this->user->categories()->firstWhere('system_key', 'cafe')->id, 'limit_uzs' => 600_000]);

    $this->artisan('bot:digest monthly')->assertSuccessful();

    $sent = cmdCalls('sendRichMessage')[0];
    expect($sent['rich_message']['html'])
        ->toContain(cmdNb('Итоги месяца: Октябрь'))
        ->toContain(cmdNb('Отложено 8 100 000 сум, это 81% дохода. Месяцем раньше было 95%.'))
        ->toContain(cmdNb('Без разовых трат на 80% больше обычного (обычно 500 000 сум).'))
        ->toContain('Больше обычного')
        ->toContain(cmdNb('+400 000 сум'))
        ->toContain('В пределах бюджета: 0 из 1.')
        ->and(json_encode($sent['reply_markup']))->toContain('money\\/report\\/2026-10');
});

it('leaves the weekly totals out on a Sunday that is the 1st, when the month\'s report comes', function () {
    $this->travelTo(Carbon::parse('2026-11-01 20:00', 'Asia/Tashkent'));
    $this->user->transactions()->create([
        'type' => 'expense', 'account_id' => $this->card->id, 'amount' => 300_000, 'currency' => 'UZS', 'rate' => '1', 'amount_uzs' => 300_000,
        'occurred_at' => now()->subDays(2), 'category_id' => $this->user->categories()->firstWhere('system_key', 'cafe')->id,
    ]);

    $this->artisan('bot:digest weekly')->assertSuccessful();
    $this->artisan('bot:digest monthly')->assertSuccessful();

    expect(cmdCalls('sendRichMessage'))->toHaveCount(1)
        ->and(cmdCalls('sendRichMessage')[0]['rich_message']['html'])->toContain('Итоги месяца: Октябрь');
});

it('sets up the webhook, commands in both languages and the Mini App menu button', function () {
    $this->artisan('telegram:setup')->assertSuccessful();

    expect(cmdCalls('setWebhook')[0])->toMatchArray(['url' => 'https://kompas.example.uz/telegram/webhook', 'secret_token' => 'hook-secret'])
        ->and(cmdCalls('setChatMenuButton')[0]['menu_button'])->toBe(['type' => 'web_app', 'text' => 'Компас', 'web_app' => ['url' => 'https://kompas.example.uz/']])
        ->and(cmdCalls('setMyCommands'))->toHaveCount(2)
        ->and(cmdCalls('setMyCommands')[1]['language_code'])->toBe('uz')
        ->and(collect(cmdCalls('setMyCommands')[0]['commands'])->pluck('command')->all())->toBe(['today', 'month', 'help']);
});

it('answers updates fetched with telegram:poll, and shows who wrote', function () {
    Http::swap(new Factory);
    Http::fake([
        'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 7, 'message' => [
                'message_id' => 1, 'text' => '/today',
                'from' => ['id' => CMD_OWNER, 'first_name' => 'Elyor'],
                'chat' => ['id' => CMD_OWNER, 'type' => 'private'],
            ]],
            ['update_id' => 8, 'message' => [
                'message_id' => 2, 'text' => '/start',
                'from' => ['id' => 555, 'first_name' => 'Stranger'],
                'chat' => ['id' => 555, 'type' => 'private'],
            ]],
        ]]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 70]]),
    ]);

    $this->artisan('telegram:poll --once')
        ->expectsOutputToContain('Elyor (111222333): /today')
        ->expectsOutputToContain('Stranger (555): /start  <- not in ALLOWED_TELEGRAM_IDS')
        ->assertSuccessful();

    expect(cmdCalls('getUpdates')[0])->toMatchArray(['offset' => 0, 'timeout' => 0])
        ->and(cmdCalls('sendRichMessage')[0]['chat_id'])->toBe(CMD_OWNER)
        ->and(cmdCalls('sendRichMessage')[0]['rich_message']['html'])->toContain('Сегодня можно')
        ->and(cmdCalls('sendMessage')[0])->toMatchArray(['chat_id' => 555, 'text' => __('bot.private', [], 'ru')]);
});

it('stops telegram:poll when the bot has a webhook or no token', function () {
    Http::swap(new Factory);
    Http::fake(['api.telegram.org/*/getUpdates' => Http::response([
        'ok' => false, 'error_code' => 409, 'description' => "Conflict: can't use getUpdates method while webhook is active",
    ], 409)]);

    $this->artisan('telegram:poll')
        ->expectsOutputToContain('While a webhook is set')
        ->assertFailed();

    config(['telegram.bot_token' => null]);
    $this->artisan('telegram:poll')->expectsOutputToContain('TELEGRAM_BOT_TOKEN')->assertFailed();
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

// --- Tasks and habits in the day's summary (step 1.6) ----------------------------------

function cmdThings(User $user): array
{
    $every = ['days' => [1, 2, 3, 4, 5, 6, 7]];
    $habit = fn (array $a) => $user->habits()->create([...$a, 'schedule' => $every, 'started_on' => '2026-10-01']);

    $things = [
        'call' => $user->tasks()->create(['title' => 'Позвонить в банк', 'due_on' => '2026-10-21', 'due_time' => '10:00', 'priority' => 1]),
        'gift' => $user->tasks()->create(['title' => 'Купить подарок', 'due_on' => '2026-10-19']),
        'gym' => $user->tasks()->create(['title' => 'Зал', 'due_on' => '2026-10-21', 'repeat' => ['unit' => 'day', 'every' => 2]]),
        'later' => $user->tasks()->create(['title' => 'Позже', 'due_on' => '2026-10-25']),
        'workout' => $habit(['name' => 'Зарядка', 'type' => 'check', 'target' => 1]),
        'water' => $habit(['name' => 'Вода', 'type' => 'count', 'target' => 8, 'unit' => 'стаканов']),
        'coffee' => $habit(['name' => 'Кофе', 'type' => 'limit', 'target' => 2]),
    ];
    foreach (['2026-10-19', '2026-10-20'] as $day) {
        $things['workout']->logs()->create(['date' => $day, 'value' => 1, 'user_id' => $user->id]);
    }
    $things['water']->logs()->create(['date' => '2026-10-21', 'value' => 3, 'user_id' => $user->id]);
    $things['coffee']->logs()->create(['date' => '2026-10-21', 'value' => 3, 'user_id' => $user->id]);

    return $things;
}

function cmdButtons(array $call): array
{
    return collect($call['reply_markup']['inline_keyboard'] ?? [])->flatten(1)
        ->map(fn ($b) => $b['callback_data'] ?? $b['web_app']['url'])->all();
}

function cmdTap(string $data): void
{
    cmdSend(['callback_query' => [
        'id' => 'cb', 'data' => $data,
        'from' => ['id' => CMD_OWNER, 'first_name' => 'Elyor'],
        'message' => ['message_id' => 70, 'chat' => ['id' => CMD_OWNER, 'type' => 'private']],
    ]]);
}

it('lists today\'s tasks and habits under the limit, with a tap for each', function () {
    $t = cmdThings($this->user);

    cmdText('/today');

    $card = cmdCalls('sendRichMessage')[0];
    expect($card['rich_message']['html'])
        ->toContain('<h4>Дела на сегодня</h4>')
        ->toContain('<li>Купить подарок · <i>просрочено</i></li>')
        ->toContain('<li>❗ <b>10:00</b> Позвонить в банк</li>')
        ->not->toContain('Позже')
        ->toContain('<h4>Привычки</h4>')
        ->toContain('<li>⬜ Зарядка · 🔥 2 дня подряд</li>')
        ->toContain('<li>⬜ Вода — 3 из 8 стаканов</li>')
        ->toContain('<li>⚠️ Кофе — 3 при лимите 2</li>')
        ->and(cmdButtons($card))->toContain(
            "task:done:{$t['gift']->id}", "task:done:{$t['call']->id}", "task:done:{$t['gym']->id}",
            "habit:inc:{$t['workout']->id}", "habit:inc:{$t['water']->id}", "habit:inc:{$t['coffee']->id}",
        );
});

it('ticks tasks off and logs habits from the summary, once per tap', function () {
    $t = cmdThings($this->user);
    $toast = fn () => collect(cmdCalls('answerCallbackQuery'))->last()['text'] ?? null;

    cmdTap("task:done:{$t['gift']->id}");
    expect($t['gift']->fresh()->done_at)->not->toBeNull()
        ->and($toast())->toBe('Готово: Купить подарок')
        ->and(collect(cmdCalls('editMessageText'))->last()['rich_message']['html'])->not->toContain('Купить подарок');

    cmdTap("task:done:{$t['gift']->id}");
    expect($toast())->toBe(__('bot.record.gone'));

    // A repeating task moves on, and a second tap does not move it again.
    cmdTap("task:done:{$t['gym']->id}");
    cmdTap("task:done:{$t['gym']->id}");
    expect($t['gym']->fresh()->due_on)->toStartWith('2026-10-23')
        ->and($t['gym']->fresh()->done_at)->toBeNull();

    cmdTap("habit:inc:{$t['water']->id}");
    expect($t['water']->logs()->where('date', '2026-10-21')->value('value'))->toBe(4)
        ->and($toast())->toBe('Вода: 4 из 8 стаканов');

    cmdTap("habit:inc:{$t['workout']->id}");
    cmdTap("habit:inc:{$t['workout']->id}");
    expect($t['workout']->logs()->where('date', '2026-10-21')->value('value'))->toBe(1)
        ->and($toast())->toBe('Зарядка: готово')
        ->and(cmdButtons(collect(cmdCalls('editMessageText'))->last()))->not->toContain("habit:inc:{$t['workout']->id}");
});
