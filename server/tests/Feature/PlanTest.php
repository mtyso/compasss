<?php

use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\MonthDay;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 15:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];

    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-10-21', 'rate' => '12000.0000']);
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 4_000_000]);
    $this->dollars = $this->user->accounts()->create(['name' => 'Наличные $', 'type' => 'cash', 'currency' => 'USD', 'opening_balance' => 10_000]);
    $this->user->accounts()->create(['name' => 'Подушка', 'type' => 'savings', 'currency' => 'UZS', 'opening_balance' => 9_000_000, 'spendable' => false, 'is_cushion' => true]);

    $this->cafe = $this->user->categories()->firstWhere('system_key', 'cafe');
    $this->rent = $this->user->categories()->firstWhere('system_key', 'home_rent');
    $this->groceries = $this->user->categories()->firstWhere('system_key', 'groceries');
});

it('computes the limit on «Сегодня» like the hand calculation', function () {
    $this->postJson('/api/income-schedules', ['name' => 'Зарплата', 'day_of_month' => 10, 'expected_amount' => 12_000_000, 'currency' => 'UZS'], $this->auth)
        ->assertCreated()->assertJsonPath('next_date', '2026-11-10');
    $this->postJson('/api/recurring-payments', ['name' => 'Аренда', 'amount' => 2_000_000, 'currency' => 'UZS', 'category_id' => $this->rent->id, 'account_id' => $this->card->id, 'day_of_month' => 1], $this->auth)
        ->assertCreated()->assertJsonPath('next_due_on', '2026-11-01');
    $this->patchJson('/api/me', ['settings' => ['min_balance_uzs' => 120_000]], $this->auth)->assertOk();
    $this->postJson('/api/transactions', ['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 60_000, 'category_id' => $this->cafe->id, 'occurred_at' => '2026-10-21 13:00'], $this->auth);
    $this->postJson('/api/transactions', ['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 40_000, 'category_id' => $this->cafe->id, 'occurred_at' => '2026-10-20 13:00'], $this->auth);

    // spendable = 4 000 000 − 100 000 + $100 × 12 000 = 5 100 000 (the cushion stays out)
    // free = 5 100 000 − 2 000 000 rent − 120 000 minimum = 2 980 000; start of day 3 040 000; 20 days
    $this->getJson('/api/today', $this->auth)->assertOk()
        ->assertJsonPath('limit.spendable_uzs', 5_100_000)
        ->assertJsonPath('limit.upcoming_payments_uzs', 2_000_000)
        ->assertJsonPath('limit.free_uzs', 2_980_000)
        ->assertJsonPath('limit.days_left', 20)
        ->assertJsonPath('limit.limit_uzs', 152_000)
        ->assertJsonPath('limit.spent_today_uzs', 60_000)
        ->assertJsonPath('limit.left_today_uzs', 92_000)
        ->assertJsonPath('next_income.name', 'Зарплата')
        ->assertJsonPath('payments.0.name', 'Аренда');
});

it('pays a recurring payment in one tap and moves it a month ahead', function () {
    $id = $this->postJson('/api/recurring-payments', [
        'name' => 'Интернет', 'amount' => 150_000, 'currency' => 'UZS', 'category_id' => $this->rent->id,
        'account_id' => $this->card->id, 'day_of_month' => 15, 'next_due_on' => '2026-10-15',
    ], $this->auth)->assertJsonPath('overdue', true)->json('id');

    $this->postJson("/api/recurring-payments/{$id}/pay", [], $this->auth)->assertOk()
        ->assertJsonPath('payment.next_due_on', '2026-11-15')
        ->assertJsonPath('payment.overdue', false)
        ->assertJsonPath('transaction.amount', 150_000)
        ->assertJsonPath('transaction.note', 'Интернет');

    $this->getJson('/api/summary?month=2026-10', $this->auth)->assertJsonPath('expense_uzs', 150_000);
});

it('keeps today\'s allowance when a set-aside payment is paid, and spreads a one-off purchase over the days left', function () {
    $this->postJson('/api/income-schedules', ['name' => 'Зарплата', 'day_of_month' => 10, 'expected_amount' => 12_000_000, 'currency' => 'UZS'], $this->auth);
    $id = $this->postJson('/api/recurring-payments', ['name' => 'Интернет', 'amount' => 200_000, 'currency' => 'UZS', 'category_id' => $this->rent->id, 'account_id' => $this->card->id, 'day_of_month' => 21], $this->auth)
        ->assertJsonPath('next_due_on', '2026-10-21')->json('id');

    // free = 5 200 000 − 200 000 internet = 5 000 000; 20 days
    $this->getJson('/api/today', $this->auth)->assertJsonPath('limit.limit_uzs', 250_000)->assertJsonPath('limit.left_today_uzs', 250_000);

    $this->postJson("/api/recurring-payments/{$id}/pay", [], $this->auth)->assertOk();
    $this->getJson('/api/today', $this->auth)
        ->assertJsonPath('limit.spent_today_uzs', 0)
        ->assertJsonPath('limit.limit_uzs', 250_000)
        ->assertJsonPath('limit.left_today_uzs', 250_000)
        ->assertJsonCount(0, 'payments');

    // A one-off 1 000 000 leaves 4 000 000 free for the same 20 days.
    $this->postJson('/api/transactions', ['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 1_000_000, 'category_id' => $this->cafe->id, 'is_one_off' => true, 'occurred_at' => '2026-10-21 14:00'], $this->auth)->assertCreated();
    $this->getJson('/api/today', $this->auth)
        ->assertJsonPath('limit.limit_uzs', 200_000)
        ->assertJsonPath('limit.left_today_uzs', 200_000);
});

it('needs the amount when paying from an account in another currency', function () {
    $id = $this->postJson('/api/recurring-payments', [
        'name' => 'ChatGPT', 'amount' => 2_000, 'currency' => 'USD', 'category_id' => $this->rent->id, 'day_of_month' => 3,
    ], $this->auth)->json('id');

    $this->postJson("/api/recurring-payments/{$id}/pay", ['account_id' => $this->card->id], $this->auth)->assertJsonValidationErrors('amount');
    $this->postJson("/api/recurring-payments/{$id}/pay", ['account_id' => $this->dollars->id], $this->auth)->assertOk()
        ->assertJsonPath('transaction.amount_uzs', 240_000);
});

it('clamps the 31st to the last day of shorter months', function () {
    expect(MonthDay::in(2026, 2, 31)->toDateString())->toBe('2026-02-28')
        ->and(MonthDay::next(31, Carbon::parse('2026-11-05'))->toDateString())->toBe('2026-11-30')
        ->and(MonthDay::nextMonth(Carbon::parse('2026-01-31'), 31)->toDateString())->toBe('2026-02-28')
        ->and(MonthDay::next(10, Carbon::parse('2026-10-10'), strictlyAfter: true)->toDateString())->toBe('2026-11-10');
});

it('shows budgets with spending, the pace marker and a forecast', function () {
    $this->putJson('/api/budgets/2026-10', ['items' => [
        ['category_id' => $this->cafe->id, 'limit_uzs' => 1_000_000],
        ['category_id' => $this->groceries->id, 'limit_uzs' => 3_000_000],
    ]], $this->auth)->assertOk();

    $coffee = $this->user->categories()->firstWhere('system_key', 'cafe_coffee');
    $this->postJson('/api/transactions', ['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 420_000, 'category_id' => $coffee->id, 'occurred_at' => '2026-10-12 10:00'], $this->auth);
    $this->postJson('/api/transactions', ['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 210_000, 'category_id' => $this->cafe->id, 'occurred_at' => '2026-10-14 10:00', 'is_one_off' => true], $this->auth);

    $budget = $this->getJson('/api/budgets?month=2026-10', $this->auth)->assertOk();

    // 21 of 31 days gone; regular 420 000 → 620 000 by the month end, plus the one-off 210 000.
    $cafe = collect($budget->json('items'))->firstWhere('category_id', $this->cafe->id);
    expect($budget->json('pace'))->toBe(0.6774)
        ->and($cafe['spent_uzs'])->toBe(630_000)
        ->and($cafe['forecast_uzs'])->toBe(210_000 + 620_000)
        ->and($budget->json('limit_uzs'))->toBe(4_000_000);
});

it('starts a new month from the previous budget and drops zero limits', function () {
    $this->putJson('/api/budgets/2026-09', ['items' => [['category_id' => $this->cafe->id, 'limit_uzs' => 800_000]]], $this->auth);

    $this->getJson('/api/budgets?month=2026-10', $this->auth)->assertJsonPath('items.0.limit_uzs', 800_000);
    $this->getJson('/api/budgets?month=2026-11', $this->auth)->assertJsonPath('items.0.limit_uzs', 800_000);
    // A past month without a budget stays empty.
    $this->getJson('/api/budgets?month=2026-08', $this->auth)->assertJsonPath('items', []);

    $this->putJson('/api/budgets/2026-10', ['items' => [['category_id' => $this->cafe->id, 'limit_uzs' => 0]]], $this->auth)
        ->assertJsonPath('items', []);
});

it('accepts budgets only for own main expense categories', function () {
    $coffee = $this->user->categories()->firstWhere('system_key', 'cafe_coffee');
    $salary = $this->user->categories()->firstWhere('system_key', 'salary');

    $this->putJson('/api/budgets/2026-10', ['items' => [['category_id' => $coffee->id, 'limit_uzs' => 1]]], $this->auth)->assertUnprocessable();
    $this->putJson('/api/budgets/2026-10', ['items' => [['category_id' => $salary->id, 'limit_uzs' => 1]]], $this->auth)->assertUnprocessable();
    $this->putJson('/api/budgets/2026-13', ['items' => []], $this->auth)->assertNotFound();
});

it('merges settings and rejects unknown ones', function () {
    $this->patchJson('/api/me', ['settings' => ['min_balance_uzs' => 100_000]], $this->auth)->assertJsonPath('settings.min_balance_uzs', 100_000);
    $this->patchJson('/api/me', ['settings' => ['hack' => 1]], $this->auth)->assertUnprocessable();
});
