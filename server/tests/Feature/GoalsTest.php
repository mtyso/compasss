<?php

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 10:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];

    // No CBU here: dollar sums use the last stored rate.
    Http::fake(['cbu.uz/*' => Http::failedConnection()]);
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-06-01', 'rate' => '12800.0000']);
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 12_000_000]);
    $this->postJson('/api/income-schedules', ['name' => 'Зарплата', 'day_of_month' => 10, 'expected_amount' => 12_000_000, 'currency' => 'UZS'], $this->auth)->assertCreated();
    $this->postJson('/api/income-schedules', ['name' => 'Аванс', 'day_of_month' => 25, 'expected_amount' => 4_000_000, 'currency' => 'UZS'], $this->auth)->assertCreated();
});

it('sets a goal\'s contribution aside before the daily limit, and «Отложить» leaves the limit as it was', function () {
    $goal = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Отпуск', 'currency' => 'UZS', 'target_amount' => 15_000_000, 'deadline' => '2027-07-01'], $this->auth)
        ->assertCreated()
        // 15 000 000 over 9 months (10 Oct to 1 Jul), rounded up to 1 000
        ->assertJsonPath('monthly_required', 1_667_000)
        ->assertJsonPath('monthly', 1_667_000)
        // The salary carries 12 of the 16 mln expected a month: 75% of the month's contribution
        ->assertJsonPath('period.planned', 1_251_000)
        ->assertJsonPath('period.due', 1_251_000)
        ->assertJsonPath('status', 'on_track')
        ->json();

    $jar = Account::find($goal['account_id']);
    expect($jar->name)->toBe('Копилка · Отпуск')
        ->and($jar->type)->toBe('savings')
        ->and($jar->spendable)->toBeFalse();

    // free = 12 000 000 − 1 251 000 = 10 749 000 for 15 days until the advance
    $this->getJson('/api/today', $this->auth)->assertOk()
        ->assertJsonPath('limit.planned_savings_uzs', 1_251_000)
        ->assertJsonPath('limit.days_left', 15)
        ->assertJsonPath('limit.limit_uzs', 716_600)
        ->assertJsonPath('goals.due_uzs', 1_251_000)
        ->assertJsonPath('goals.main_id', $goal['id'])
        ->assertJsonPath('goals.goals.0.title', 'Отпуск');

    $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $goal['id'], 'amount' => 1_251_000]]], $this->auth)
        ->assertOk()
        ->assertJsonPath('transactions.0.type', 'transfer')
        ->assertJsonPath('transactions.0.to_account_id', $jar->id)
        ->assertJsonPath('transactions.0.note', 'В цель «Отпуск»')
        ->assertJsonPath('goals.due_uzs', 0)
        ->assertJsonPath('goals.goals.0.saved', 1_251_000)
        ->assertJsonPath('goals.goals.0.period.made', 1_251_000)
        // What the period asks for does not shrink because it was paid
        ->assertJsonPath('goals.goals.0.period.planned', 1_251_000);

    $this->getJson('/api/today', $this->auth)
        ->assertJsonPath('limit.spendable_uzs', 10_749_000)
        ->assertJsonPath('limit.planned_savings_uzs', 0)
        ->assertJsonPath('limit.limit_uzs', 716_600);

    // On the advance day the advance's quarter of the month is due, counted from what is left.
    $this->travelTo(Carbon::parse('2026-10-25 09:00', 'Asia/Tashkent'));
    // 13 749 000 left over 9 months (25 Oct to 1 Jul) = 1 528 000 a month, a quarter of it now
    $this->getJson("/api/goals/{$goal['id']}", $this->auth)->assertOk()
        ->assertJsonPath('goal.monthly', 1_528_000)
        ->assertJsonPath('goal.period.planned', 382_000)
        ->assertJsonPath('goal.period.made', 0)
        ->assertJsonPath('goal.status', 'on_track')
        ->assertJsonPath('is_main', true)
        ->assertJsonPath('history.0.month', '2026-10')
        ->assertJsonPath('history.0.amount', 1_251_000);
});

it('starts a goal\'s jar with what is already saved, and an installment without interest costs nothing extra', function () {
    $goal = $this->postJson('/api/goals', [
        'type' => 'buy', 'title' => 'Dongfeng 008', 'currency' => 'UZS', 'deadline' => '2027-04-01',
        'price' => 400_000_000, 'down_percent' => 30, 'loan_months' => 60, 'rate' => 0, 'saved' => 17_948_000,
    ], $this->auth)
        ->assertCreated()
        ->assertJsonPath('target_amount', 120_000_000)
        ->assertJsonPath('saved', 17_948_000)
        // 102 052 000 left over 6 pay months
        ->assertJsonPath('monthly_required', 17_009_000)
        ->assertJsonPath('status', 'on_track')
        // 280 000 000 over 60 months with no interest
        ->assertJsonPath('installment.payment', 4_667_000)
        ->assertJsonPath('installment.overpay', 0)
        // Without interest the options go both ways: a smaller down payment buys sooner at no extra cost
        ->assertJsonPath('installment.options.0.down_percent', 20)
        ->assertJsonPath('installment.options.0.payment', 5_334_000)
        ->assertJsonPath('installment.options.0.overpay', 0)
        ->assertJsonPath('installment.options.1.down_percent', 40)
        ->json();

    expect(Account::find($goal['account_id'])->opening_balance)->toBe(17_948_000);
    // The money was already aside: it is not spending money and does not count as this period's contribution.
    $this->getJson('/api/today', $this->auth)
        ->assertJsonPath('limit.spendable_uzs', 12_000_000)
        ->assertJsonPath('goals.goals.0.period.made', 0);

    $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Отпуск', 'currency' => 'UZS', 'target_amount' => 1_000_000, 'saved' => 1, 'account_id' => $this->card->id], $this->auth)
        ->assertUnprocessable()->assertJsonValidationErrors('saved');
    $this->postJson('/api/goals', ['type' => 'debt', 'title' => 'Долг', 'currency' => 'UZS', 'target_amount' => 1_000_000, 'saved' => 1], $this->auth)
        ->assertUnprocessable()->assertJsonValidationErrors('saved');
});

it('keeps the plan forecast within the deadline when the goal keeps to its plan', function () {
    $this->travelTo(Carbon::parse('2026-10-22 12:00', 'Asia/Tashkent'));
    // 14 pay months from 10 Oct to 1 Dec 2027: 2 143 000 a month, though 14 average months from today end on 23 Dec
    $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Подушка', 'currency' => 'UZS', 'target_amount' => 30_000_000, 'deadline' => '2027-12-01'], $this->auth)
        ->assertCreated()
        ->assertJsonPath('monthly_required', 2_143_000)
        ->assertJsonPath('forecast.plan_on', '2027-12-01')
        ->assertJsonPath('status', 'on_track');
});

it('takes only the rest of the period for a goal set in the middle of it', function () {
    $this->travelTo(Carbon::parse('2026-10-21 12:00', 'Asia/Tashkent'));
    // 1 000 000 a month, 75% on the salary, 4 of the 15 days of this period left
    $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Подушка', 'currency' => 'UZS', 'target_amount' => 30_000_000, 'monthly_amount' => 1_000_000, 'cushion' => true], $this->auth)
        ->assertCreated()
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('monthly', 1_000_000)
        ->assertJsonPath('period.planned', 200_000)
        // 30 000 000 at 1 000 000 a month: 30 months
        ->assertJsonPath('forecast.plan_on', '2029-04-22');

    expect($this->user->accounts()->where('is_cushion', true)->count())->toBe(1);
});

it('saves the amount its owner chose even below what the deadline needs, and the deadline becomes a forecast', function () {
    $goal = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Машина', 'currency' => 'UZS', 'target_amount' => 15_000_000, 'deadline' => '2027-07-01', 'monthly_amount' => 1_000_000], $this->auth)
        ->assertCreated()
        ->assertJsonPath('monthly_required', 1_667_000)
        ->assertJsonPath('monthly', 1_000_000)
        ->assertJsonPath('period.planned', 750_000)
        ->assertJsonPath('status', 'later')
        // 15 months at 1 000 000, after the deadline and not pulled back to it
        ->assertJsonPath('forecast.plan_on', '2028-01-10')
        ->json();

    // (12 000 000 − 750 000) / 15 days
    $this->getJson('/api/today', $this->auth)
        ->assertJsonPath('limit.planned_savings_uzs', 750_000)
        ->assertJsonPath('limit.limit_uzs', 750_000);

    // Asking for what the deadline needs again makes it a plan to keep to.
    $this->patchJson("/api/goals/{$goal['id']}", ['monthly_amount' => 1_700_000], $this->auth)->assertOk()
        ->assertJsonPath('monthly', 1_700_000)
        ->assertJsonPath('status', 'on_track');
});

it('does not hold goals back from the limit when they get what is left over', function () {
    $this->patchJson('/api/me', ['settings' => ['goal_mode' => 'leftover']], $this->auth)->assertOk();
    $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Отпуск', 'currency' => 'UZS', 'target_amount' => 15_000_000, 'deadline' => '2027-07-01'], $this->auth)->assertCreated();

    $this->getJson('/api/today', $this->auth)
        ->assertJsonPath('goals.mode', 'leftover')
        ->assertJsonPath('goals.due_uzs', 1_251_000)
        ->assertJsonPath('limit.planned_savings_uzs', 0)
        ->assertJsonPath('limit.limit_uzs', 800_000);
});

it('still keeps a debt payment out of the limit when goals get what is left over', function () {
    $this->patchJson('/api/me', ['settings' => ['goal_mode' => 'leftover']], $this->auth)->assertOk();
    $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Отпуск', 'currency' => 'UZS', 'target_amount' => 15_000_000, 'deadline' => '2027-07-01'], $this->auth)->assertCreated();
    // 600 000 a month, 75% of it on the salary
    $debt = $this->postJson('/api/goals', ['type' => 'debt', 'title' => 'Кредит', 'currency' => 'UZS', 'target_amount' => 6_000_000, 'monthly_amount' => 600_000], $this->auth)
        ->assertCreated()->assertJsonPath('period.due', 450_000)->json();

    // (12 000 000 − 450 000) / 15 days; the vacation waits for what is left over
    $this->getJson('/api/today', $this->auth)
        ->assertJsonPath('goals.due_uzs', 1_701_000)
        ->assertJsonPath('limit.planned_savings_uzs', 450_000)
        ->assertJsonPath('limit.limit_uzs', 770_000);

    $this->travelTo(Carbon::parse('2026-10-23 08:00', 'Asia/Tashkent'));
    $this->artisan('advisor:run morning')->assertSuccessful();
    $reminder = $this->user->insights()->where('rule', 'goal_due')->sole();
    expect($reminder->payload['variant'])->toBe('leftover_debt')
        ->and($reminder->payload['debt_uzs'])->toBe(450_000);

    // Paid: only the vacation is left to the leftovers, and the limit holds nothing back.
    $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $debt['id'], 'amount' => 450_000]]], $this->auth)->assertOk();
    $this->getJson('/api/today', $this->auth)->assertJsonPath('limit.planned_savings_uzs', 0);
});

it('saves for a dollar goal from a som card at today\'s rate', function () {
    $goal = $this->postJson('/api/goals', ['type' => 'buy', 'title' => 'Ноутбук', 'currency' => 'USD', 'target_amount' => 120_000], $this->auth)
        ->assertCreated()->assertJsonPath('currency', 'USD')->json();

    $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $goal['id'], 'amount' => 10_000]]], $this->auth)
        ->assertOk()
        ->assertJsonPath('transactions.0.amount', 1_280_000)
        ->assertJsonPath('transactions.0.to_amount', 10_000)
        ->assertJsonPath('goals.goals.0.saved', 10_000);
});

it('closes a purchase goal with «Купил» paid from the jar', function () {
    $goal = $this->postJson('/api/goals', ['type' => 'buy', 'title' => 'Телефон', 'currency' => 'UZS', 'target_amount' => 3_000_000], $this->auth)->json();
    $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $goal['id'], 'amount' => 3_000_000]]], $this->auth)
        ->assertJsonPath('goals.goals.0.status', 'reached')
        ->assertJsonPath('goals.goals.0.progress', 1);

    $other = $this->user->categories()->firstWhere('system_key', 'other_expense');
    $this->postJson("/api/goals/{$goal['id']}/buy", ['category_id' => $other->id, 'amount' => 2_900_000], $this->auth)->assertOk()
        ->assertJsonPath('goal.status', 'done')
        ->assertJsonPath('transaction.type', 'expense')
        ->assertJsonPath('transaction.account_id', $goal['account_id'])
        ->assertJsonPath('transaction.is_one_off', true);

    // A finished goal asks for nothing.
    $this->getJson('/api/today', $this->auth)->assertJsonPath('goals.due_uzs', 0);
});

it('pays a debt off through its account and counts the interest', function () {
    $goal = $this->postJson('/api/goals', ['type' => 'debt', 'title' => 'Рассрочка', 'currency' => 'UZS', 'target_amount' => 6_000_000, 'monthly_amount' => 1_000_000, 'rate' => 24], $this->auth)
        ->assertCreated()
        ->assertJsonPath('saved', 0)
        ->assertJsonPath('remaining', 6_000_000)
        ->assertJsonPath('monthly', 1_000_000)
        ->json();
    expect(Account::find($goal['account_id']))->type->toBe('debt')->opening_balance->toBe(-6_000_000);

    $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $goal['id'], 'amount' => 750_000]]], $this->auth)
        ->assertJsonPath('goals.goals.0.saved', 750_000)
        ->assertJsonPath('goals.goals.0.remaining', 5_250_000)
        // 1 000 000 a month, 75% on the salary, 750 000 of it paid
        ->assertJsonPath('goals.goals.0.period.due', 0);

    $speed = $this->getJson("/api/goals/{$goal['id']}", $this->auth)->assertOk()->json('speed');
    $extra = collect($speed['levers'])->firstWhere('key', 'debt_extra');
    expect($extra['extra'])->toBe(250_000)
        ->and($extra['interest_saved'])->toBeGreaterThan(0);
});

it('marks a goal behind when a period passes without its contribution', function () {
    $goal = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Отпуск', 'currency' => 'UZS', 'target_amount' => 15_000_000, 'deadline' => '2027-07-01'], $this->auth)->json();

    $this->travelTo(Carbon::parse('2026-11-10 09:00', 'Asia/Tashkent'));
    // A month of 9 has passed with nothing put aside: the line expects about 1,7 mln by now
    $state = $this->getJson('/api/goals', $this->auth)->json('goals.0');
    expect($state['status'])->toBe('behind')
        ->and($state['behind'])->toBeGreaterThan(1_500_000)
        // The rest is spread over the 8 months left
        ->and($state['monthly'])->toBe(1_875_000);
});

it('tracks a goal without money by its measurements and steps', function () {
    $goal = $this->postJson('/api/goals', ['type' => 'other', 'title' => 'Сбросить 5 кг', 'unit' => 'кг', 'start_value' => 85, 'target_value' => 80], $this->auth)
        ->assertCreated()->assertJsonPath('current_value', 85)->json();

    $this->putJson("/api/goals/{$goal['id']}/checkins/2026-10-10", ['value' => 85], $this->auth)->assertOk();
    $this->travelTo(Carbon::parse('2026-10-20 09:00', 'Asia/Tashkent'));
    $this->putJson("/api/goals/{$goal['id']}/checkins/2026-10-20", ['value' => 84], $this->auth)->assertOk()
        ->assertJsonPath('current_value', 84)
        ->assertJsonPath('progress', 0.2)
        // 1 kg in 10 days, 4 to go
        ->assertJsonPath('forecast.fact_on', '2026-11-29');

    $this->postJson('/api/tasks', ['title' => 'Записаться в зал', 'goal_id' => $goal['id']], $this->auth)->assertCreated()->assertJsonPath('goal_id', $goal['id']);
    $this->postJson('/api/habits', ['name' => 'Без сладкого', 'type' => 'check', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]], 'goal_id' => $goal['id']], $this->auth)->assertCreated();

    $this->getJson("/api/goals/{$goal['id']}", $this->auth)->assertOk()
        ->assertJsonPath('goal.steps.total', 1)
        ->assertJsonPath('steps.0.title', 'Записаться в зал')
        ->assertJsonPath('habits.0.name', 'Без сладкого')
        ->assertJsonPath('speed', null);
});

it('suggests money habits and smaller flexible spending to reach a goal sooner', function () {
    $this->travelTo(Carbon::parse('2026-07-01 10:00', 'Asia/Tashkent'));
    $cafe = $this->user->categories()->firstWhere('system_key', 'cafe');
    foreach (['2026-07-03', '2026-08-03', '2026-09-03'] as $day) {
        $this->postJson('/api/transactions', ['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 1_200_000, 'category_id' => $cafe->id, 'occurred_at' => "{$day} 13:00"], $this->auth)->assertCreated();
    }
    $this->travelTo(Carbon::parse('2026-10-10 10:00', 'Asia/Tashkent'));
    $goal = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Отпуск', 'currency' => 'UZS', 'target_amount' => 15_000_000, 'deadline' => '2027-07-01'], $this->auth)->json();
    $this->postJson('/api/habits', ['name' => 'Без доставки', 'type' => 'check', 'schedule' => ['per_week' => 5], 'saves_uzs' => 45_000], $this->auth)->assertCreated();

    $levers = collect($this->getJson("/api/goals/{$goal['id']}", $this->auth)->json('speed.levers'));
    expect($levers->firstWhere('key', 'category_cut'))->toMatchArray(['category_id' => $cafe->id, 'extra_uzs' => 360_000, 'typical_uzs' => 1_200_000])
        // 45 000 × 5 days × 52 weeks / 12
        ->and($levers->firstWhere('key', 'habit'))->toMatchArray(['extra_uzs' => 970_000, 'name' => 'Без доставки']);
});

it('keeps a jar with money when its goal is deleted and drops an unused one', function () {
    $used = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Отпуск', 'currency' => 'UZS', 'target_amount' => 15_000_000], $this->auth)->json();
    $unused = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Машина', 'currency' => 'UZS', 'target_amount' => 150_000_000], $this->auth)->json();
    $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $used['id'], 'amount' => 100_000]]], $this->auth)->assertOk();

    $this->deleteJson("/api/goals/{$used['id']}", [], $this->auth)->assertNoContent();
    $this->deleteJson("/api/goals/{$unused['id']}", [], $this->auth)->assertNoContent();

    expect(Account::find($used['account_id']))->not->toBeNull()
        ->and(Account::find($unused['account_id']))->toBeNull()
        ->and(Goal::count())->toBe(0);
});

it('makes the first goal the main one and accepts a new order', function () {
    $a = $this->postJson('/api/goals', ['type' => 'other', 'title' => 'Английский B2'], $this->auth)->json('id');
    $b = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Отпуск', 'currency' => 'UZS', 'target_amount' => 1_000_000], $this->auth)->json('id');
    $this->getJson('/api/goals', $this->auth)->assertJsonPath('main_id', $a);

    $this->putJson('/api/goals/order', ['ids' => [$b, $a]], $this->auth)->assertNoContent();
    $this->getJson('/api/goals', $this->auth)->assertJsonPath('main_id', $b)->assertJsonPath('goals.1.id', $a);
});

it('refuses a jar that holds money for spending or belongs to another goal', function () {
    $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Отпуск', 'account_id' => $this->card->id, 'target_amount' => 1_000_000], $this->auth)
        ->assertUnprocessable()->assertJsonValidationErrors('account_id');

    $deposit = $this->user->accounts()->create(['name' => 'Вклад', 'type' => 'deposit', 'currency' => 'USD', 'opening_balance' => 50_000, 'spendable' => false]);
    $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Машина', 'account_id' => $deposit->id, 'target_amount' => 1_000_000], $this->auth)
        ->assertCreated()
        ->assertJsonPath('currency', 'USD')
        ->assertJsonPath('saved', 50_000);
    $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Дом', 'account_id' => $deposit->id, 'target_amount' => 1_000_000], $this->auth)
        ->assertUnprocessable()->assertJsonValidationErrors('account_id');
    $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Дом', 'currency' => 'UZS'], $this->auth)
        ->assertUnprocessable()->assertJsonValidationErrors('target_amount');
});

it('collects a down payment for a purchase on installment and shows the payment and the overpayment', function () {
    $goal = $this->postJson('/api/goals', [
        'type' => 'buy', 'title' => 'Dongfeng 008', 'currency' => 'UZS', 'deadline' => '2027-04-01',
        'price' => 400_000_000, 'down_percent' => 30, 'loan_months' => 36, 'rate' => 24,
    ], $this->auth)
        ->assertCreated()
        // The goal collects 30% of the price: 120 mln over 6 months
        ->assertJsonPath('target_amount', 120_000_000)
        ->assertJsonPath('monthly_required', 20_000_000)
        ->assertJsonPath('installment.down_amount', 120_000_000)
        ->assertJsonPath('installment.loan', 280_000_000)
        ->assertJsonPath('installment.payment', 10_986_000)
        ->assertJsonPath('installment.payment_uzs', 10_986_000)
        ->assertJsonPath('installment.overpay', 115_467_164)
        ->assertJsonPath('installment.options.0.down_percent', 40)
        ->assertJsonPath('installment.options.0.payment', 9_416_000)
        ->assertJsonPath('installment.options.1.down_percent', 50)
        ->assertJsonPath('installment.options.1.overpay', 82_476_556)
        ->json();

    // A new price moves the down payment with it.
    $this->patchJson("/api/goals/{$goal['id']}", ['price' => 380_000_000], $this->auth)->assertOk()
        ->assertJsonPath('target_amount', 114_000_000)
        ->assertJsonPath('installment.loan', 266_000_000);

    $this->postJson('/api/goals', ['type' => 'buy', 'title' => 'Без цены', 'currency' => 'UZS', 'down_percent' => 30], $this->auth)
        ->assertUnprocessable()->assertJsonValidationErrors('price');
});

it('turns the rest of the price into a debt goal with its monthly payment at «Купил»', function () {
    $savings = $this->user->accounts()->create(['name' => 'Вклад', 'type' => 'savings', 'currency' => 'UZS', 'opening_balance' => 120_000_000, 'spendable' => false]);
    $goal = $this->postJson('/api/goals', [
        'type' => 'buy', 'title' => 'Dongfeng 008', 'account_id' => $savings->id,
        'price' => 400_000_000, 'down_percent' => 30, 'loan_months' => 36, 'rate' => 24,
    ], $this->auth)->assertCreated()->assertJsonPath('status', 'reached')->json();

    $transport = $this->user->categories()->firstWhere('system_key', 'transport');
    $debt = $this->postJson("/api/goals/{$goal['id']}/buy", ['category_id' => $transport->id], $this->auth)->assertOk()
        ->assertJsonPath('goal.status', 'done')
        ->assertJsonPath('transaction.amount', 120_000_000)
        ->assertJsonPath('debt.type', 'debt')
        ->assertJsonPath('debt.title', 'Рассрочка · Dongfeng 008')
        // All that is owed: 280 mln and the overpayment
        ->assertJsonPath('debt.target_amount', 395_467_164)
        ->assertJsonPath('debt.remaining', 395_467_164)
        ->assertJsonPath('debt.monthly_amount', 10_986_000)
        ->assertJsonPath('debt.deadline', '2029-10-10')
        ->json('debt');

    $account = Account::find($debt['account_id']);
    expect($account->type)->toBe('debt')->and($account->spendable)->toBeFalse();
    $spent = $this->user->transactions()->where('account_id', $account->id)->get();
    expect($spent->pluck('amount')->all())->toEqualCanonicalizing([280_000_000, 115_467_164])
        ->and($spent->firstWhere('amount', 115_467_164)->category_id)->toBe($this->user->categories()->firstWhere('system_key', 'finance_interest')->id);

    // The installment takes the purchase's place; its payment is part of the plan from now on.
    $this->getJson('/api/goals', $this->auth)->assertOk()
        ->assertJsonPath('main_id', $debt['id'])
        ->assertJsonPath('goals.0.id', $debt['id']);
});
