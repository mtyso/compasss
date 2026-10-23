<?php

use App\Models\Goal;
use App\Models\User;
use App\Money\Debts;
use Illuminate\Support\Carbon;

// «Итоги месяца» (docs/PLAN.md, stage 2.4, D30): income and spending, the share kept, spending against the
// usual level and the budget, and how the money goals moved.

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02 10:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 0]);
    $this->cat = fn (string $key) => $this->user->categories()->firstWhere('system_key', $key);
});

function reportOp(object $test, string $type, int $amount, string $when, ?string $category = null, array $extra = []): void
{
    $test->user->transactions()->create([
        'type' => $type, 'account_id' => $test->card->id, 'amount' => $amount, 'currency' => 'UZS', 'rate' => '1.0000',
        'amount_uzs' => $amount, 'category_id' => $category ? ($test->cat)($category)->id : null,
        'occurred_at' => Carbon::parse($when, 'Asia/Tashkent'), ...$extra,
    ]);
}

/** Salary every month from July, groceries and cafe as usual, then October with a coat and dearer cafes. */
function reportMonths(object $test): void
{
    foreach (['07' => [1_000_000, 400_000], '08' => [1_200_000, 500_000], '09' => [1_100_000, 600_000], '10' => [900_000, 800_000]] as $m => [$groceries, $cafe]) {
        reportOp($test, 'income', 10_000_000, "2026-{$m}-03 09:00", 'salary');
        reportOp($test, 'expense', $groceries, "2026-{$m}-12 18:00", 'groceries');
        reportOp($test, 'expense', $cafe, "2026-{$m}-20 20:00", 'cafe');
    }
    reportOp($test, 'expense', 1_500_000, '2026-10-25 15:00', 'clothes', ['is_one_off' => true]);
    reportOp($test, 'expense', 100_000, '2026-11-01 12:00', 'groceries');
}

it('sums up last month: kept share, spending against the usual and the budget, goals and debts moved', function () {
    reportMonths($this);
    $budget = fn (string $key, int $limit) => $this->user->budgets()->create(['month' => '2026-10', 'category_id' => ($this->cat)($key)->id, 'limit_uzs' => $limit]);
    $budget('groceries', 1_000_000);
    $budget('cafe', 600_000);

    // A car fund topped up in September, October and on 1 November; a card debt paid in October.
    $car = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Машина', 'currency' => 'UZS', 'target_amount' => 50_000_000], $this->auth)->assertCreated()->json();
    Goal::find($car['id'])->update(['started_on' => '2026-09-01']);
    foreach (['2026-09-15 10:00' => 1_000_000, '2026-10-15 10:00' => 2_000_000, '2026-11-01 10:00' => 1_000_000] as $when => $amount) {
        reportOp($this, 'transfer', $amount, $when, null, ['to_account_id' => $car['account_id'], 'to_amount' => $amount]);
    }
    [$debt, $plan] = app(Debts::class)->borrow($this->user, null, ['name' => 'Кредитка', 'currency' => 'UZS', 'amount' => 3_000_000, 'monthly_amount' => 500_000], now());
    $plan->update(['started_on' => '2026-10-01']);
    reportOp($this, 'transfer', 500_000, '2026-10-20 10:00', null, ['to_account_id' => $debt->id, 'to_amount' => 500_000]);

    $report = $this->getJson('/api/reports/month', $this->auth)->assertOk()
        ->assertJsonPath('month', '2026-10')
        ->assertJsonPath('first_month', '2026-07')
        ->assertJsonPath('tracked', true)
        ->assertJsonPath('over', true)
        ->assertJsonPath('pace', 1)
        ->assertJsonPath('income_uzs', 10_000_000)
        ->assertJsonPath('expense_uzs', 3_200_000)
        ->assertJsonPath('one_off_uzs', 1_500_000)
        ->assertJsonPath('saved_uzs', 6_800_000)
        ->assertJsonPath('rate', 68)
        // July 1.4 million, August and September 1.7 million each, without one-offs
        ->assertJsonPath('usual_uzs', 1_700_000)
        ->assertJsonPath('history', ['2026-09', '2026-08', '2026-07'])
        ->assertJsonPath('prev', ['month' => '2026-09', 'income_uzs' => 10_000_000, 'expense_uzs' => 1_700_000, 'rate' => 83])
        ->assertJsonPath('budget', ['limit_uzs' => 1_600_000, 'spent_uzs' => 1_700_000, 'kept' => 1, 'count' => 2])
        ->assertJsonPath('goals_added_uzs', 2_000_000)
        ->assertJsonPath('debts_paid_uzs', 500_000)
        ->json();

    $categories = collect($report['categories'])->keyBy(fn ($c) => $this->user->categories()->find($c['category_id'])->system_key);
    expect($categories->keys()->take(3)->all())->toBe(['clothes', 'groceries', 'cafe'])
        ->and($categories['clothes'])->toMatchArray(['total_uzs' => 1_500_000, 'one_off_uzs' => 1_500_000, 'usual_uzs' => 0, 'limit_uzs' => null])
        ->and($categories['groceries'])->toMatchArray(['total_uzs' => 900_000, 'usual_uzs' => 1_100_000, 'limit_uzs' => 1_000_000])
        ->and($categories['cafe'])->toMatchArray(['total_uzs' => 800_000, 'usual_uzs' => 500_000, 'limit_uzs' => 600_000]);

    // November's top-up is not October's; the debt counts what was paid, not what was owed.
    expect($report['goals'])->toHaveCount(2)
        ->and($report['goals'][0])->toMatchArray(['title' => 'Машина', 'added' => 2_000_000, 'saved' => 3_000_000, 'progress_from' => 0.02, 'progress_to' => 0.06, 'done' => false])
        ->and($report['goals'][1])->toMatchArray(['title' => 'Кредитка', 'type' => 'debt', 'added' => 500_000, 'saved' => 500_000, 'progress_from' => 0, 'progress_to' => 0.1667]);
});

it('shows this month so far, a month before the records without comparisons, and nothing ahead', function () {
    reportMonths($this);

    $this->getJson('/api/reports/month?month=2026-11', $this->auth)->assertOk()
        ->assertJsonPath('over', false)
        // 2 November of 30 days
        ->assertJsonPath('pace', 0.0667)
        ->assertJsonPath('expense_uzs', 100_000)
        ->assertJsonPath('rate', null)
        ->assertJsonPath('history', ['2026-10', '2026-09', '2026-08']);

    $this->getJson('/api/reports/month?month=2026-06', $this->auth)->assertOk()
        ->assertJsonPath('tracked', false)
        ->assertJsonPath('usual_uzs', null)
        ->assertJsonPath('prev', null)
        ->assertJsonPath('categories', []);

    $this->getJson('/api/reports/month?month=2026-12', $this->auth)->assertUnprocessable();
});

it('lists spending day by day, one-offs shown apart, every day of the month there', function () {
    reportMonths($this);
    reportOp($this, 'expense', 50_000, '2026-10-12 09:00', 'cafe');
    reportOp($this, 'income', 300_000, '2026-10-12 11:00', 'salary');

    $days = $this->getJson('/api/reports/month?month=2026-10', $this->auth)->assertOk()->json('days');

    expect($days)->toHaveCount(31)
        ->and($days[0])->toBe(['date' => '2026-10-01', 'expense_uzs' => 0, 'one_off_uzs' => 0])
        ->and($days[11])->toBe(['date' => '2026-10-12', 'expense_uzs' => 950_000, 'one_off_uzs' => 0])
        ->and($days[19]['expense_uzs'])->toBe(800_000)
        ->and($days[24])->toBe(['date' => '2026-10-25', 'expense_uzs' => 1_500_000, 'one_off_uzs' => 1_500_000])
        ->and(array_sum(array_column($days, 'expense_uzs')))->toBe(3_250_000);
});

it('does not count a month seen from the middle as usual', function () {
    reportOp($this, 'expense', 300_000, '2026-09-18 12:00', 'cafe');
    reportOp($this, 'expense', 500_000, '2026-10-18 12:00', 'cafe');

    // September started on the 18th: October has no history yet.
    $this->getJson('/api/reports/month', $this->auth)->assertOk()
        ->assertJsonPath('tracked', true)
        ->assertJsonPath('history', [])
        ->assertJsonPath('usual_uzs', null)
        ->assertJsonPath('categories.0.usual_uzs', null)
        ->assertJsonPath('prev.expense_uzs', 300_000);
    $this->getJson('/api/reports/month?month=2026-09', $this->auth)->assertJsonPath('tracked', false);
});
