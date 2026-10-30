<?php

use App\Advisor\Advisor;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// The advisor about goals (docs/PLAN.md, 2.6): the income split, reminders, pace, leftovers and «Можно ли купить?».

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 10:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
    Http::fake(['cbu.uz/*' => Http::failedConnection()]);
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-06-01', 'rate' => '12800.0000']);
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 12_000_000]);
    $this->postJson('/api/income-schedules', ['name' => 'Зарплата', 'day_of_month' => 10, 'expected_amount' => 12_000_000, 'currency' => 'UZS'], $this->auth)->assertCreated();
    $this->postJson('/api/income-schedules', ['name' => 'Аванс', 'day_of_month' => 25, 'expected_amount' => 4_000_000, 'currency' => 'UZS'], $this->auth)->assertCreated();
    $this->cat = fn (string $key) => $this->user->categories()->firstWhere('system_key', $key);
    $this->advisor = app(Advisor::class);
    // 15 mln by 1 July: 1 667 000 a month, 1 251 000 of it from the salary period
    $this->goal = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Отпуск', 'currency' => 'UZS', 'target_amount' => 15_000_000, 'deadline' => '2027-07-01'], $this->auth)->json();
});

it('splits a salary into payments, goals and money for each day, and puts the goals\' share aside from the insight', function () {
    $this->postJson('/api/transactions', [
        'type' => 'income', 'account_id' => $this->card->id, 'amount' => 12_000_000,
        'category_id' => ($this->cat)('salary')->id, 'occurred_at' => '2026-10-10 09:30',
    ], $this->auth)->assertCreated();

    $split = $this->user->insights()->where('rule', 'income_split')->sole();
    expect($split->payload)->toMatchArray(['variant' => 'goals', 'goals_uzs' => 1_251_000, 'goal_id' => $this->goal['id'], 'account_id' => $this->card->id])
        ->and($split->text('ru')['body'])->toContain("в цели по плану 1\u{202F}251\u{202F}000 сум");

    $this->postJson("/api/insights/{$split->id}/action", [], $this->auth)->assertOk()
        ->assertJsonPath('transactions.0.type', 'transfer')
        ->assertJsonPath('transactions.0.amount', 1_251_000)
        ->assertJsonPath('transactions.0.to_account_id', $this->goal['account_id'])
        ->assertJsonPath('goals.due_uzs', 0)
        ->assertJsonPath('insight.action', null);
});

it('reminds of contributions not made in the last days before an income, and drops the reminder once they are made', function () {
    $this->travelTo(Carbon::parse('2026-10-23 08:00', 'Asia/Tashkent'));
    $this->advisor->run($this->user, 'morning', now());

    $due = $this->user->insights()->where('rule', 'goal_due')->sole();
    expect($due->payload)->toMatchArray(['variant' => 'first', 'goals_uzs' => 1_251_000, 'days' => 2])
        ->and($due->severity)->toBe('info')
        ->and($due->text('ru')['title'])->toBe("В цели ещё не отложено 1\u{202F}251\u{202F}000 сум")
        ->and($due->toApi('ru')['action'])->toBe('contribute');

    $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $this->goal['id'], 'amount' => 1_251_000]]], $this->auth)->assertOk();
    $this->advisor->run($this->user, 'morning', now());
    expect($this->user->insights()->where('rule', 'goal_due')->count())->toBe(0);
});

it('warns when a goal falls behind its line and says what a month needs now', function () {
    $this->travelTo(Carbon::parse('2026-11-10 08:00', 'Asia/Tashkent'));
    $this->advisor->run($this->user, 'morning', now());

    $pace = $this->user->insights()->where('rule', 'goal_pace')->sole();
    expect($pace->severity)->toBe('warn')
        ->and($pace->payload)->toMatchArray(['variant' => 'behind', 'goal_id' => $this->goal['id'], 'monthly_amount' => 1_875_000, 'deadline_on' => '2027-07-01'])
        ->and($pace->text('ru')['title'])->toStartWith('«Отпуск» отстаёт от графика на ')
        ->and($pace->text('ru')['body'])->toBe("Чтобы успеть к 1 июля, откладывай 1\u{202F}875\u{202F}000 сум в месяц. Посмотри «Как быстрее» на экране цели.");
});

it('does not call a goal saving its own smaller amount behind', function () {
    $this->patchJson("/api/goals/{$this->goal['id']}", ['monthly_amount' => 1_000_000], $this->auth)->assertOk()->assertJsonPath('status', 'later');
    $this->travelTo(Carbon::parse('2026-11-10 08:00', 'Asia/Tashkent'));
    $this->advisor->run($this->user, 'morning', now());

    expect($this->user->insights()->where('rule', 'goal_pace')->count())->toBe(0);
});

it('with «Из остатка» names the goals\' plan on payday without setting it aside, and does not press with reminders', function () {
    $this->patchJson('/api/me', ['settings' => ['goal_mode' => 'leftover']], $this->auth)->assertOk();
    $this->user->refresh();
    $this->postJson('/api/transactions', [
        'type' => 'income', 'account_id' => $this->card->id, 'amount' => 12_000_000,
        'category_id' => ($this->cat)('salary')->id, 'occurred_at' => '2026-10-10 09:30',
    ], $this->auth)->assertCreated();

    // 24 000 000 on the card for 15 days until the advance, the goals are not taken out of it
    $split = $this->user->insights()->where('rule', 'income_split')->sole();
    expect($split->payload)->toMatchArray(['variant' => 'leftover', 'plan_uzs' => 1_251_000, 'left_uzs' => 24_000_000, 'per_day_uzs' => 1_600_000, 'goal_id' => $this->goal['id']])
        ->and($split->toApi('ru')['action'])->toBeNull()
        ->and($split->text('ru')['body'])->toContain("В цели по плану 1\u{202F}251\u{202F}000 сум: отложишь из того, что останется");

    $this->travelTo(Carbon::parse('2026-10-23 08:00', 'Asia/Tashkent'));
    $this->advisor->run($this->user, 'morning', now());
    expect($this->user->insights()->where('rule', 'goal_due')->count())->toBe(0);
});

it('with «Из остатка» says calmly when the actual pace gets to a goal after its deadline', function () {
    $this->patchJson('/api/me', ['settings' => ['goal_mode' => 'leftover']], $this->auth)->assertOk();
    $this->user->refresh();
    $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $this->goal['id'], 'amount' => 500_000]]], $this->auth)->assertOk();
    $this->travelTo(Carbon::parse('2026-11-10 08:00', 'Asia/Tashkent'));
    $this->advisor->run($this->user, 'morning', now());

    $pace = $this->user->insights()->where('rule', 'goal_pace')->sole();
    expect($pace->severity)->toBe('info')
        ->and($pace->payload)->toMatchArray(['variant' => 'slower', 'goal_id' => $this->goal['id'], 'deadline_on' => '2027-07-01'])
        ->and($pace->text('ru')['title'])->toStartWith('«Отпуск» в нынешнем темпе будет к ')
        ->and($pace->text('ru')['body'])->toEndWith('Если так спокойнее, просто сдвинь срок.');
});

it('suggests putting what is left on the last evening before an income into the main goal', function () {
    $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $this->goal['id'], 'amount' => 1_251_000]]], $this->auth)->assertOk();
    $this->postJson('/api/transactions', [
        'type' => 'expense', 'account_id' => $this->card->id, 'amount' => 10_000_000,
        'category_id' => ($this->cat)('groceries')->id, 'occurred_at' => '2026-10-20 12:00',
    ], $this->auth)->assertCreated();

    // 24 October, the advance comes tomorrow: 12 000 000 − 1 251 000 − 10 000 000 = 749 000 left
    $this->travelTo(Carbon::parse('2026-10-24 20:00', 'Asia/Tashkent'));
    $this->advisor->run($this->user, 'evening', now());

    $leftover = $this->user->insights()->where('rule', 'goal_leftover')->sole();
    expect($leftover->payload)->toMatchArray(['variant' => 'days', 'left_uzs' => 700_000, 'amount' => 700_000, 'days' => 13])
        ->and($leftover->text('ru')['body'])->toBe("Отложи 700\u{202F}000 сум в «Отпуск»: цель станет ближе примерно на 13 дн.");

    $this->postJson("/api/insights/{$leftover->id}/action", [], $this->auth)->assertOk()
        ->assertJsonPath('transactions.0.amount', 700_000)
        ->assertJsonPath('goals.goals.0.saved', 1_951_000);
});

it('celebrates a collected down payment and names the installment payment', function () {
    $car = $this->postJson('/api/goals', [
        'type' => 'buy', 'title' => 'Машина', 'currency' => 'UZS', 'price' => 10_000_000, 'down_percent' => 30, 'loan_months' => 12,
    ], $this->auth)->assertJsonPath('target_amount', 3_000_000)->json();

    $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $car['id'], 'amount' => 3_000_000]]], $this->auth)->assertOk();

    $reached = $this->user->insights()->where('rule', 'goal_reached')->sole();
    expect($reached->payload)->toMatchArray(['variant' => 'installment', 'goal_id' => $car['id'], 'payment_amount' => 584_000])
        ->and($reached->text('ru')['title'])->toBe('Первый взнос на «Машина» собран!');
});

it('tells how many days of saving for the main goal a purchase is', function () {
    $this->postJson('/api/advisor/can-afford', ['amount' => 500_000, 'currency' => 'UZS'], $this->auth)->assertOk()
        ->assertJsonPath('reasons.1', 'Это 9 дн. накоплений на «Отпуск».');
});
