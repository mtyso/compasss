<?php

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Support\Carbon;

// «Можно ли это купить?» (docs/PLAN.md, 2.3), the «Советы» feed and the advisor on «Сегодня».

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 15:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-10-21', 'rate' => '12000.0000']);

    // 4 000 000 free until the salary on 10 November: 20 days, 200 000 a day
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 4_000_000]);
    $this->archive = $this->user->accounts()->create(['name' => 'Старая карта', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 0, 'spendable' => false]);
    $this->user->incomeSchedules()->create(['name' => 'Зарплата', 'day_of_month' => 10, 'expected_amount' => 12_000_000, 'currency' => 'UZS']);
    $this->cat = fn (string $key) => $this->user->categories()->firstWhere('system_key', $key);
});

function canAfford(object $test, int $amount, ?string $category = null, string $currency = 'UZS')
{
    return $test->postJson('/api/advisor/can-afford', [
        'amount' => $amount, 'currency' => $currency, 'category_id' => $category ? ($test->cat)($category)->id : null,
    ], $test->auth)->assertOk();
}

function historyExpense(object $test, int $amount, string $category, string $when): void
{
    $test->user->transactions()->create([
        'type' => 'expense', 'account_id' => $test->archive->id, 'amount' => $amount, 'currency' => 'UZS', 'rate' => '1.0000',
        'amount_uzs' => $amount, 'category_id' => ($test->cat)($category)->id, 'occurred_at' => Carbon::parse($when, 'Asia/Tashkent'),
    ]);
}

it('says yes when the purchase fits what is left of today', function () {
    canAfford($this, 150_000)
        ->assertJsonPath('verdict', 'yes')
        ->assertJsonPath('title', 'Можно')
        ->assertJsonPath('reasons.0', "Укладывается в сегодняшний лимит: на сегодня останется 50\u{202F}000 сум.");
});

it('says yes, but shows how the daily limit drops', function () {
    canAfford($this, 1_000_000)
        ->assertJsonPath('verdict', 'yes_but')
        ->assertJsonPath('limit_before_uzs', 200_000)
        ->assertJsonPath('limit_after_uzs', 150_000)
        ->assertJsonPath('reasons.0', "Дневной лимит на оставшиеся дни (20) станет 150\u{202F}000 сум вместо 200\u{202F}000 сум.");
});

it('suggests waiting for the salary when the limit would fall below everyday essentials', function () {
    // Essentials cost 3 600 000 a month, 120 000 a day; after 1 800 000 the limit is 110 000.
    foreach (['07', '08', '09'] as $month) {
        historyExpense($this, 3_600_000, 'groceries', "2026-{$month}-02 10:00");
    }

    canAfford($this, 1_800_000)
        ->assertJsonPath('verdict', 'wait')
        ->assertJsonPath('wait_until', '2026-11-10')
        ->assertJsonPath('reasons.1', 'После дохода 10 ноября хватит без ущерба.');
});

it('suggests waiting for next month when the category budget would be exceeded', function () {
    $this->user->budgets()->create(['month' => '2026-10', 'category_id' => ($this->cat)('cafe')->id, 'limit_uzs' => 300_000]);
    historyExpense($this, 250_000, 'cafe_delivery', '2026-10-05 13:00');

    canAfford($this, 100_000, 'cafe_coffee')
        ->assertJsonPath('verdict', 'wait')
        ->assertJsonPath('wait_until', '2026-11-01')
        ->assertJsonPath('reasons.1', "Бюджет «Кафе и рестораны» будет превышен на 50\u{202F}000 сум.")
        ->assertJsonPath('reasons.2', 'С 1 ноября начнётся новый бюджет.');
});

it('says no when the money set aside or the cushion would have to pay for it', function () {
    foreach (['07', '08', '09'] as $month) {
        historyExpense($this, 6_000_000, 'groceries', "2026-{$month}-02 10:00");
    }
    $this->user->accounts()->create(['name' => 'Подушка', 'type' => 'savings', 'currency' => 'UZS', 'opening_balance' => 6_000_000, 'spendable' => false, 'is_cushion' => true]);

    canAfford($this, 500, null, 'USD') // $5 is fine
        ->assertJsonPath('verdict', 'yes');
    canAfford($this, 5_000_000)
        ->assertJsonPath('verdict', 'no')
        ->assertJsonPath('title', 'Не стоит')
        ->assertJsonPath('reasons.0', "До дохода не хватает 1\u{202F}000\u{202F}000 сум: придётся взять из денег, отложенных на платежи, или из запаса.")
        ->assertJsonPath('reasons.1', 'Если взять из подушки, её хватит на 0,8 мес.');
    canAfford($this, 20_000_000)
        ->assertJsonPath('verdict', 'no')
        ->assertJsonPath('reasons.1', 'Даже всей подушки на это не хватит.');
});

it('answers in Uzbek when asked in Uzbek', function () {
    $this->withHeader('Accept-Language', 'uz');

    canAfford($this, 150_000)->assertJsonPath('title', 'Mumkin');
});

it('shows the most important advice on «Сегодня» and runs the morning rules once a day', function () {
    $this->user->budgets()->create(['month' => '2026-10', 'category_id' => ($this->cat)('cafe')->id, 'limit_uzs' => 300_000]);
    $this->user->budgets()->create(['month' => '2026-10', 'category_id' => ($this->cat)('groceries')->id, 'limit_uzs' => 1_000_000]);
    historyExpense($this, 400_000, 'cafe', '2026-10-05 13:00');
    historyExpense($this, 850_000, 'groceries', '2026-10-05 13:00');

    $this->getJson('/api/today', $this->auth)->assertOk()
        ->assertJsonCount(2, 'insights')
        ->assertJsonPath('insights.0.severity', 'alert')
        ->assertJsonPath('insights.0.title', 'Кафе и рестораны: бюджет превышен')
        ->assertJsonPath('insights.1.title', 'Продукты: 85% бюджета')
        ->assertJsonPath('insights_new', 2);

    // Later the same day nothing is evaluated again, even with new data.
    historyExpense($this, 500_000, 'cafe', '2026-09-05 13:00');
    $this->getJson('/api/today', $this->auth)->assertJsonPath('insights_new', 2);
});

it('lets the feed be read, rated and a rule muted', function () {
    $this->user->budgets()->create(['month' => '2026-10', 'category_id' => ($this->cat)('cafe')->id, 'limit_uzs' => 300_000]);
    historyExpense($this, 400_000, 'cafe', '2026-10-05 13:00');
    $this->getJson('/api/today', $this->auth);

    $feed = $this->getJson('/api/insights', $this->auth)->assertOk()->assertJsonPath('new_count', 1);
    $id = $feed->json('data.0.id');

    $this->postJson('/api/insights/seen', [], $this->auth)->assertNoContent();
    $this->getJson('/api/insights', $this->auth)->assertJsonPath('new_count', 0)->assertJsonPath('data.0.status', 'seen');

    $this->postJson("/api/insights/{$id}/feedback", ['value' => 'useful'], $this->auth)->assertJsonPath('insight.status', 'useful');
    $this->postJson("/api/insights/{$id}/feedback", ['value' => 'mute'], $this->auth)
        ->assertJsonPath('settings.muted_rules', ['budget_threshold']);
    $this->postJson("/api/insights/{$id}/feedback", ['value' => 'nonsense'], $this->auth)->assertJsonValidationErrors('value');

    // Somebody else's insight does not exist for this user.
    $other = User::factory()->create();
    $foreign = $other->insights()->create(['rule' => 'cushion', 'severity' => 'info', 'period_key' => '2026-10', 'payload' => []]);
    $this->postJson("/api/insights/{$foreign->id}/feedback", ['value' => 'useful'], $this->auth)->assertNotFound();
});

it('marks a large expense as one-off right from the insight', function () {
    foreach (range(1, 6) as $day) {
        historyExpense($this, 150_000, 'clothes', "2026-09-{$day} 12:00");
    }
    $coat = $this->postJson('/api/transactions', [
        'type' => 'expense', 'account_id' => $this->card->id, 'amount' => 900_000,
        'category_id' => ($this->cat)('clothes')->id, 'occurred_at' => '2026-10-21 13:00',
    ], $this->auth)->assertCreated()->json('id');

    $insight = $this->getJson('/api/insights', $this->auth)->json('data.0');
    expect($insight['rule'])->toBe('large_spend')->and($insight['action'])->toBe('mark_one_off');

    $this->postJson("/api/insights/{$insight['id']}/action", [], $this->auth)->assertOk()
        ->assertJsonPath('transaction.is_one_off', true)
        ->assertJsonPath('insight.action', null);
    expect($this->user->transactions()->find($coat)->is_one_off)->toBeTrue();
});

it('validates the advisor settings', function () {
    $this->patchJson('/api/me', ['settings' => ['advisor_strictness' => 'strict', 'budget_alert_percent' => 90, 'muted_rules' => ['cushion']]], $this->auth)
        ->assertOk()->assertJsonPath('settings.advisor_strictness', 'strict');
    $this->patchJson('/api/me', ['settings' => ['muted_rules' => ['no_such_rule']]], $this->auth)->assertJsonValidationErrors('settings.muted_rules.0');
    $this->patchJson('/api/me', ['settings' => ['advisor_strictness' => 'angry']], $this->auth)->assertJsonValidationErrors('settings.advisor_strictness');
});
