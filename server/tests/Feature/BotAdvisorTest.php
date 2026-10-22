<?php

use App\Models\ExchangeRate;
use App\Models\Insight;
use App\Models\Transaction;
use App\Models\User;
use App\Money\TransactionWriter;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const ADV_OWNER = 111222333;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 14:20', 'Asia/Tashkent'));
    config([
        'telegram.bot_token' => 'test-token',
        'telegram.webhook_secret' => 'hook-secret',
        'telegram.allowed_ids' => [(string) ADV_OWNER],
        'telegram.webapp_url' => 'https://kompas.example.uz',
        'telegram.rich_messages' => true,
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 60]])]);

    $this->user = User::factory()->create(['telegram_id' => ADV_OWNER, 'locale' => 'ru']);
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 3_500_000]);
    $this->taxi = $this->user->categories()->firstWhere('system_key', 'transport_taxi');
    $this->transport = $this->user->categories()->firstWhere('system_key', 'transport');
});

function advSay(string $text): void
{
    test()->postJson('/telegram/webhook', ['update_id' => 1, 'message' => [
        'message_id' => 10, 'text' => $text,
        'from' => ['id' => ADV_OWNER, 'first_name' => 'Elyor'],
        'chat' => ['id' => ADV_OWNER, 'type' => 'private'],
    ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
}

function advTap(string $data): void
{
    test()->postJson('/telegram/webhook', ['update_id' => 2, 'callback_query' => [
        'id' => 'cb', 'data' => $data,
        'from' => ['id' => ADV_OWNER, 'first_name' => 'Elyor'],
        'message' => ['message_id' => 60, 'chat' => ['id' => ADV_OWNER, 'type' => 'private']],
    ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
}

/** @return list<array<string, mixed>> */
function advCalls(string $method): array
{
    return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), "/{$method}"))
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

function advLastHtml(string $method = 'sendRichMessage'): string
{
    return collect(advCalls($method))->last()['rich_message']['html'];
}

/** @return list<string> */
function advButtons(array $call): array
{
    return collect($call['reply_markup']['inline_keyboard'] ?? [])->flatten(1)
        ->map(fn ($b) => $b['callback_data'] ?? $b['web_app']['url'])->all();
}

function advToast(): ?string
{
    return collect(advCalls('answerCallbackQuery'))->last()['text'] ?? null;
}

function advNb(string $text): string
{
    return preg_replace('/(?<=\d) (?=\d)/u', "\u{202F}", $text);
}

/** An operation entered in the app, not in the chat. */
function advSaveInApp(User $user, int $accountId, int $categoryId, int $amount): Transaction
{
    return app(TransactionWriter::class)->save($user, new Transaction, [
        'type' => 'expense', 'account_id' => $accountId, 'amount' => $amount, 'category_id' => $categoryId,
        'occurred_at' => now()->toDateTimeString(), 'source' => 'app',
    ]);
}

// --- «Можно ли купить?» -------------------------------------------------------------

it('answers «можно купить?» with the advisor\'s verdict and records the purchase on «Купил»', function () {
    advSay('можно купить наушники 600к?');

    $answer = collect(advCalls('sendRichMessage'))->last();
    expect(Transaction::count())->toBe(0)
        ->and($answer['rich_message']['html'])
        ->toContain('<h2>🟡 Можно, но</h2>')
        ->toContain(advNb('Наушники за 600 000 сум'))
        ->toContain('Дневной лимит на оставшиеся дни')
        ->and(advButtons($answer))->toHaveCount(2)
        ->and(advButtons($answer)[0])->toStartWith('buy:')
        ->and(advButtons($answer)[1])->toBe('https://kompas.example.uz/');

    advTap(advButtons($answer)[0]);

    $tx = Transaction::sole();
    expect($tx->amount)->toBe(600_000)
        ->and($tx->note)->toBe('Наушники')
        ->and($tx->source)->toBe('bot')
        // The verdict keeps only «Открыть Компас», and the purchase gets its own card.
        ->and(advButtons(collect(advCalls('editMessageReplyMarkup'))->last()))->toBe(['https://kompas.example.uz/'])
        ->and(advLastHtml())->toContain(advNb('−600 000 сум · Наушники'));

    advTap(advButtons($answer)[0]);
    expect(Transaction::count())->toBe(1)
        ->and(advToast())->toBe(__('bot.question.expired'));
});

it('says «Не стоит» when the purchase is more than the free money', function () {
    advSay('можно купить телевизор 5м?');

    expect(advLastHtml())->toContain('<h2>🔴 Не стоит</h2>')->toContain('До дохода не хватает');
});

it('checks the budget of the category the words name', function () {
    $this->user->budgets()->create(['month' => '2026-10', 'category_id' => $this->transport->id, 'limit_uzs' => 100_000]);

    advSay('можно взять такси 150к?');

    expect(advLastHtml())->toContain('<h2>🟠 Лучше подождать</h2>')
        ->toContain(advNb('Бюджет «Транспорт» будет превышен на 50 000 сум.'));
});

it('answers a question in dollars at the CBU rate', function () {
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-10-21', 'rate' => '12000.0000']);

    advSay('можно купить кроссовки $50?');

    expect(advLastHtml())->toContain(advNb('Кроссовки за $50,00 ≈ 600 000 сум'));
});

// --- Insights in the operation's card -----------------------------------------------

it('puts what the advisor found about an operation into its card and does not send it again', function () {
    $this->user->budgets()->create(['month' => '2026-10', 'category_id' => $this->transport->id, 'limit_uzs' => 100_000]);

    advSay('такси 90к');

    $insight = Insight::sole();
    expect(advLastHtml())->toContain('<h4>🟠 Транспорт: 90% бюджета</h4>')
        ->toContain(advNb('До конца месяца осталось 10 000 сум.'))
        ->and($insight->sent_via)->toBe(['telegram']);

    $this->artisan('bot:notify')->assertSuccessful();
    expect(advCalls('sendRichMessage'))->toHaveCount(1);
});

it('offers to mark a large expense as one-off right under its card', function () {
    foreach (range(1, 5) as $daysAgo) {
        $this->user->transactions()->create([
            'type' => 'expense', 'account_id' => $this->card->id, 'amount' => 30_000, 'currency' => 'UZS', 'rate' => '1',
            'amount_uzs' => 30_000, 'occurred_at' => now()->subDays($daysAgo), 'category_id' => $this->taxi->id,
        ]);
    }

    advSay('такси в аэропорт 400к');

    $tx = Transaction::latest('id')->first();
    $card = collect(advCalls('sendRichMessage'))->last();
    expect($card['rich_message']['html'])->toContain('Крупная трата: Транспорт')
        ->and(advButtons($card)[0])->toBe("tx:oneoff:{$tx->id}");

    advTap("tx:oneoff:{$tx->id}");

    $redrawn = collect(advCalls('editMessageText'))->last();
    expect($tx->fresh()->is_one_off)->toBeTrue()
        ->and(Insight::firstWhere('rule', 'large_spend')->status)->toBe('useful')
        ->and($redrawn['rich_message']['html'])->toContain('разовая')
        ->and(advButtons($redrawn))->not->toContain("tx:oneoff:{$tx->id}")
        ->and(advToast())->toBe('Отмечено как разовая');
});

// --- Insights on their own (bot:notify) ---------------------------------------------

it('pushes an alert from an operation entered in the app, once', function () {
    $this->user->budgets()->create(['month' => '2026-10', 'category_id' => $this->transport->id, 'limit_uzs' => 100_000]);
    advSaveInApp($this->user, $this->card->id, $this->taxi->id, 120_000);
    $insight = Insight::sole();

    $this->artisan('bot:notify')->assertSuccessful();
    $this->artisan('bot:notify')->assertSuccessful();

    $message = advCalls('sendRichMessage');
    expect($message)->toHaveCount(1)
        ->and($message[0]['rich_message']['html'])->toContain('<h3>🔴 Транспорт: бюджет превышен</h3>')
        ->and(advButtons($message[0]))->toBe(["ins:useful:{$insight->id}", "ins:mute:{$insight->id}", 'https://kompas.example.uz/advice'])
        ->and($insight->fresh()->sent_via)->toBe(['telegram']);
});

it('keeps quiet at night and brings the night\'s advice with the morning summary', function () {
    $this->user->budgets()->create(['month' => '2026-10', 'category_id' => $this->transport->id, 'limit_uzs' => 100_000]);
    $this->travelTo(Carbon::parse('2026-10-21 23:30', 'Asia/Tashkent'));
    advSaveInApp($this->user, $this->card->id, $this->taxi->id, 120_000);

    $this->artisan('bot:notify')->assertSuccessful();
    expect(advCalls('sendRichMessage'))->toBeEmpty();

    $this->travelTo(Carbon::parse('2026-10-22 08:00', 'Asia/Tashkent'));
    $this->artisan('bot:digest morning')->assertSuccessful();
    $this->artisan('bot:notify')->assertSuccessful();

    $messages = advCalls('sendRichMessage');
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['rich_message']['html'])->toContain('Доброе утро!')->toContain('<h4>🔴 Транспорт: бюджет превышен</h4>');
});

it('reminds in the evening when nothing was recorded, with the phrase to type', function () {
    $this->travelTo(Carbon::parse('2026-10-20 12:00', 'Asia/Tashkent'));
    advSaveInApp($this->user, $this->card->id, $this->taxi->id, 30_000);
    $this->travelTo(Carbon::parse('2026-10-21 21:00', 'Asia/Tashkent'));

    $this->artisan('advisor:run evening')->assertSuccessful();
    $this->artisan('bot:notify')->assertSuccessful();

    expect(advLastHtml())->toContain('Сегодня ничего не записано')->toContain('<code>обед 45к</code>');
});

it('takes «Полезно», «Не присылать такое» and «Разовая покупка» under a pushed insight', function () {
    $insight = fn (string $rule, array $payload = []) => $this->user->insights()->create([
        'rule' => $rule, 'severity' => 'warn', 'period_key' => '2026-10', 'subject' => (string) random_int(1, 1_000_000),
        'payload' => ['category_id' => $this->transport->id, ...$payload], 'status' => 'new',
    ]);

    $useful = $insight('category_pace', ['percent' => 70, 'pace' => 50, 'per_day_uzs' => 10_000]);
    advTap("ins:useful:{$useful->id}");
    expect($useful->fresh()->status)->toBe('useful')
        ->and(advToast())->toBe('Спасибо, учту')
        ->and(advButtons(collect(advCalls('editMessageReplyMarkup'))->last()))->toBe(['https://kompas.example.uz/advice']);

    $muted = $insight('overspend_forecast', ['forecast_uzs' => 200_000, 'limit_uzs' => 100_000]);
    $other = $insight('overspend_forecast', ['forecast_uzs' => 300_000, 'limit_uzs' => 100_000]);
    advTap("ins:mute:{$muted->id}");
    expect($this->user->fresh()->setting('muted_rules'))->toBe(['overspend_forecast'])
        ->and($other->fresh()->status)->toBe('dismissed');

    $tx = advSaveInApp($this->user, $this->card->id, $this->taxi->id, 400_000);
    $large = $insight('large_spend', ['amount_uzs' => 400_000, 'typical_uzs' => 30_000, 'transaction_id' => $tx->id]);
    advTap("ins:oneoff:{$large->id}");
    expect($tx->fresh()->is_one_off)->toBeTrue()
        ->and($large->fresh()->status)->toBe('useful');

    // Someone else's insight, or one that is gone.
    advTap('ins:useful:999999');
    expect(advToast())->toBe(__('bot.record.gone'));
});

// --- Goals: «Отложить» -------------------------------------------------------------

/** The salary day of tests/Feature/GoalAdvisorTest.php: 1 251 000 of «Отпуск» falls on this period. */
function advGoal(): array
{
    test()->travelTo(Carbon::parse('2026-10-10 10:00', 'Asia/Tashkent'));
    Http::fake(['cbu.uz/*' => Http::failedConnection()]);
    $user = test()->user;
    $user->incomeSchedules()->create(['name' => 'Зарплата', 'day_of_month' => 10, 'expected_amount' => 12_000_000, 'currency' => 'UZS', 'active' => true]);
    $user->incomeSchedules()->create(['name' => 'Аванс', 'day_of_month' => 25, 'expected_amount' => 4_000_000, 'currency' => 'UZS', 'active' => true]);
    test()->auth = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

    return test()->postJson('/api/goals', ['type' => 'save', 'title' => 'Отпуск', 'currency' => 'UZS', 'target_amount' => 15_000_000, 'deadline' => '2027-07-01'], test()->auth)
        ->assertCreated()->json();
}

it('puts the goals\' share of a salary aside from its card, once', function () {
    $goal = advGoal();

    advSay('+12м зарплата');
    $income = Transaction::sole();
    $card = collect(advCalls('sendRichMessage'))->last();
    expect($card['rich_message']['html'])->toContain('Нажми «Отложить»')
        ->and(advButtons($card))->toContain("tx:save:{$income->id}");

    advTap("tx:save:{$income->id}");
    $transfer = Transaction::where('type', 'transfer')->sole();
    expect(advToast())->toBe(advNb('Отложено в цели: 1 251 000 сум'))
        ->and($transfer->to_account_id)->toBe($goal['account_id'])
        ->and(advButtons(collect(advCalls('editMessageText'))->last()))->not->toContain("tx:save:{$income->id}");

    advTap("tx:save:{$income->id}");
    expect(advToast())->toBe('Это уже отложено')
        ->and(Transaction::where('type', 'transfer')->count())->toBe(1);
});

it('offers «Отложить» under a pushed income split and answers it like the app', function () {
    advGoal();
    $this->postJson('/api/transactions', [
        'type' => 'income', 'account_id' => $this->card->id, 'amount' => 12_000_000,
        'category_id' => $this->user->categories()->firstWhere('system_key', 'salary')->id, 'occurred_at' => '2026-10-10 09:30',
    ], $this->auth)->assertCreated();
    $split = $this->user->insights()->where('rule', 'income_split')->sole();

    $this->artisan('bot:notify')->assertSuccessful();
    expect(advButtons(advCalls('sendRichMessage')[0]))->toBe([
        "ins:save:{$split->id}", "ins:useful:{$split->id}", "ins:mute:{$split->id}", 'https://kompas.example.uz/advice',
    ]);

    advTap("ins:save:{$split->id}");
    expect(advToast())->toBe(advNb('Отложено в цели: 1 251 000 сум'))
        ->and($split->fresh()->status)->toBe('useful')
        ->and(advButtons(collect(advCalls('editMessageReplyMarkup'))->last()))->toBe(['https://kompas.example.uz/advice']);
});

// --- Payment reminders ---------------------------------------------------------------

it('pushes a payment reminder the evening before and pays it once from its button', function () {
    $internet = $this->user->categories()->firstWhere('system_key', 'home_internet');
    $payment = $this->user->recurringPayments()->create([
        'name' => 'Интернет', 'amount' => 150_000, 'currency' => 'UZS', 'category_id' => $internet->id,
        'account_id' => $this->card->id, 'day_of_month' => 22, 'next_due_on' => '2026-10-22', 'active' => true,
    ]);
    $this->travelTo(Carbon::parse('2026-10-21 21:00', 'Asia/Tashkent'));
    $this->artisan('advisor:run evening')->assertSuccessful();
    $this->artisan('bot:notify')->assertSuccessful();
    $reminder = $this->user->insights()->where('rule', 'payment_due')->sole();

    $sent = collect(advCalls('sendRichMessage'))->first(fn ($m) => str_contains($m['rich_message']['html'], 'Интернет'));
    expect($sent['rich_message']['html'])->toContain(advNb('Завтра платёж: Интернет, 150 000 сум'))
        ->and(advButtons($sent))->toBe(["ins:pay:{$reminder->id}", "ins:useful:{$reminder->id}", "ins:mute:{$reminder->id}", 'https://kompas.example.uz/advice']);

    advTap("ins:pay:{$reminder->id}");
    expect(advToast())->toBe(advNb('Оплачено: Интернет, 150 000 сум'))
        ->and(Transaction::where('recurring_payment_id', $payment->id)->count())->toBe(1)
        ->and($payment->fresh()->next_due_on)->toBe('2026-11-22')
        ->and($reminder->fresh()->status)->toBe('useful')
        // The reminder's text stays; only its buttons change.
        ->and(advCalls('editMessageText'))->toBeEmpty()
        ->and(advButtons(collect(advCalls('editMessageReplyMarkup'))->last()))->toBe(['https://kompas.example.uz/advice']);

    advTap("ins:pay:{$reminder->id}");
    expect(advToast())->toBe('Уже отмечен как оплаченный.')
        ->and(Transaction::where('recurring_payment_id', $payment->id)->count())->toBe(1);
});

it('keeps payment reminders out of the morning summary unless money is missing', function () {
    $home = $this->user->categories()->firstWhere('system_key', 'home');
    $make = fn (string $name, int $amount) => $this->user->recurringPayments()->create([
        'name' => $name, 'amount' => $amount, 'currency' => 'UZS', 'category_id' => $home->id,
        'account_id' => $this->card->id, 'day_of_month' => 22, 'next_due_on' => '2026-10-22', 'active' => true,
    ]);
    $make('Интернет', 150_000);
    $make('Аренда', 3_400_000);   // 3 500 000 on the card: 50 000 short after the internet

    $this->travelTo(Carbon::parse('2026-10-22 08:00', 'Asia/Tashkent'));
    $this->artisan('bot:digest morning')->assertSuccessful();

    $html = advLastHtml();
    expect($html)->toContain('Доброе утро!')
        ->and($html)->toContain(advNb('Сегодня Аренда: на «Humo» не хватает 50 000 сум'))
        ->and($html)->not->toContain('Сегодня платёж: Интернет')
        ->and(Insight::where('rule', 'payment_due')->get()->every(fn (Insight $i) => in_array('telegram', (array) $i->sent_via, true)))->toBeTrue();

    // Already in the summary: nothing comes on its own afterwards.
    $this->artisan('bot:notify')->assertSuccessful();
    expect(advCalls('sendRichMessage'))->toHaveCount(1);
});
