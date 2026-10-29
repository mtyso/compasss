<?php

use App\Models\Transaction;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 15:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 20_000_000]);
    $this->cafe = $this->user->categories()->firstWhere('system_key', 'cafe');
    $this->user->incomeSchedules()->create(['name' => 'Зарплата', 'day_of_month' => 10, 'expected_amount' => 12_000_000, 'currency' => 'UZS', 'account_id' => $this->card->id, 'active' => true]);
});

function recordTx(object $test, string $type, int $amount, string $when, array $extra = []): Transaction
{
    return $test->user->transactions()->create([
        'type' => $type, 'account_id' => $test->card->id,
        'amount' => $amount, 'currency' => 'UZS', 'rate' => '1.0000', 'amount_uzs' => $amount,
        'category_id' => $type === 'transfer' ? null : $test->cafe->id,
        'occurred_at' => Carbon::parse($when, 'Asia/Tashkent'), 'source' => 'app',
        ...$extra,
    ]);
}

function recordTexts(object $test): array
{
    return collect($test->getJson('/api/records', $test->auth)->assertOk()->json('data'))
        ->map(fn (array $r) => "{$r['date']} {$r['text']}")->all();
}

it('writes a habit milestone dated when the run reached it, and a record line that follows its run', function () {
    $habit = $this->user->habits()->create(['name' => 'Бег', 'type' => 'check', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]], 'started_on' => '2026-09-01']);
    // Run one: 1–10 September, then 11–20 missed (a spare takes the first, the second breaks it). Run two: from the 21st.
    foreach ([['2026-09-01', '2026-09-10'], ['2026-09-21', '2026-10-20']] as [$from, $to]) {
        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
            $habit->logs()->create(['user_id' => $this->user->id, 'date' => $day->toDateString(), 'value' => 1]);
        }
    }
    $this->putJson("/api/habits/{$habit->id}/logs/2026-10-21", ['value' => 1], $this->auth)->assertOk();

    $records = $this->getJson('/api/records', $this->auth)->assertOk()->assertJsonCount(2, 'data');
    expect($records->json('data.0'))->toMatchArray([
        'kind' => 'streak_record', 'subject' => "h{$habit->id}", 'value' => 31, 'date' => '2026-10-21',
        'text' => 'Бег: новый рекорд, 31 день подряд', 'ref' => ['type' => 'habit', 'id' => $habit->id],
    ])->and($records->json('data.1'))->toMatchArray([
        'kind' => 'streak_milestone', 'value' => 30, 'date' => '2026-10-20', 'text' => 'Бег: 30 дней подряд',
    ]);

    // The next day the record line moves on with the run, and nothing else is added.
    $this->travelTo(Carbon::parse('2026-10-22 09:00', 'Asia/Tashkent'));
    $this->putJson("/api/habits/{$habit->id}/logs/2026-10-22", ['value' => 1], $this->auth)->assertOk();
    expect(recordTexts($this))->toBe(['2026-10-22 Бег: новый рекорд, 32 дня подряд', '2026-10-20 Бег: 30 дней подряд']);

    // In the app's language.
    $this->getJson('/api/records', [...$this->auth, 'Accept-Language' => 'uz'])->assertJsonPath('data.0.text', 'Бег: yangi rekord, ketma-ket 32 kun');
});

it('writes goal steps once each, only the highest one crossed, and keeps a removed line away', function () {
    $goal = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Машина', 'target_amount' => 10_000_000, 'currency' => 'UZS', 'monthly_amount' => 1_000_000], $this->auth)
        ->assertCreated()->json('id');
    $contribute = fn (int $amount) => $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $goal, 'amount' => $amount]]], $this->auth)->assertOk();
    $sum = fn (int $amount) => Format::money($amount, 'UZS', 'ru');

    $contribute(3_000_000);
    // 85% at once: one line for 75%, none for 50%.
    $contribute(5_500_000);
    expect(recordTexts($this))->toBe([
        '2026-10-21 Машина: собрано 75%, '.$sum(8_500_000),
        '2026-10-21 Машина: собрано 25%, '.$sum(3_000_000),
        '2026-10-21 Машина: первый взнос, '.$sum(3_000_000),
    ]);

    $line = $this->user->records()->where('kind', 'goal_step')->where('value', 75)->value('id');
    $this->deleteJson("/api/records/{$line}", [], $this->auth)->assertNoContent();
    $this->artisan('streaks:close-day')->assertSuccessful();
    expect(recordTexts($this))->toHaveCount(2)
        ->and($this->user->records()->withTrashed()->count())->toBe(3);

    // Bought: the last line for the goal.
    $this->user->goals()->find($goal)->update(['done_at' => Carbon::parse('2026-10-21 16:00', 'Asia/Tashkent')]);
    $this->artisan('streaks:close-day')->assertSuccessful();
    expect(recordTexts($this)[0])->toBe('2026-10-21 Цель достигнута: Машина');
});

it('writes a debt paid off, the cushion, last month in budget, a savings-rate record and the records streak', function () {
    $this->travelTo(Carbon::parse('2026-11-02 10:00', 'Asia/Tashkent'));
    $salary = $this->user->categories()->firstWhere('system_key', 'salary');
    $this->user->accounts()->create(['name' => 'Подушка', 'type' => 'savings', 'currency' => 'UZS', 'opening_balance' => 25_000_000, 'spendable' => false, 'is_cushion' => true]);
    $debt = $this->user->accounts()->create(['name' => 'Долг другу', 'type' => 'debt', 'currency' => 'UZS', 'opening_balance' => -5_000_000, 'spendable' => false]);
    recordTx($this, 'transfer', 5_000_000, '2026-10-15 12:00', ['to_account_id' => $debt->id, 'to_amount' => 5_000_000]);

    // Every day since July: 250 000 in a café. Salaries: 10% kept in July, 20% in August, 30% in September, 35% in October.
    for ($day = Carbon::parse('2026-07-01'); $day->lte(Carbon::parse('2026-11-01')); $day->addDay()) {
        recordTx($this, 'expense', 250_000, $day->toDateString().' 13:00');
    }
    foreach (['2026-07' => 8_600_000, '2026-08' => 9_700_000, '2026-09' => 10_700_000, '2026-10' => 12_000_000] as $month => $income) {
        recordTx($this, 'income', $income, "{$month}-10 10:00", ['category_id' => $salary->id]);
    }
    $this->putJson('/api/budgets/2026-10', ['items' => [['category_id' => $this->cafe->id, 'limit_uzs' => 8_000_000]]], $this->auth)->assertOk();

    $this->artisan('streaks:close-day')->assertSuccessful();

    // Usual spending is 7 750 000 a month, so 25 mln is 3.2 months: one line for 3, none for 1.
    expect(recordTexts($this))->toBe([
        '2026-11-02 Подушка: 3 месяца расходов',
        '2026-11-01 Октябрь: отложено 35% дохода, рекорд',
        '2026-11-01 Октябрь в бюджете: '.Format::uzs(7_750_000).' из '.Format::uzs(8_000_000),
        '2026-10-15 Долг закрыт: Долг другу',
        '2026-10-08 Записи 100 дней подряд',
        '2026-07-30 Записи 30 дней подряд',
    ]);

    // Running again changes nothing.
    $this->artisan('streaks:close-day')->assertSuccessful();
    expect($this->user->records()->count())->toBe(6);
});

it('shows last week on Monday beside the week before', function () {
    $this->travelTo(Carbon::parse('2026-10-26 09:00', 'Asia/Tashkent'));
    $habit = $this->user->habits()->create(['name' => 'Вода', 'type' => 'check', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]], 'started_on' => '2026-10-01']);
    for ($day = Carbon::parse('2026-10-12'); $day->lte(Carbon::parse('2026-10-25')); $day->addDay()) {
        $date = $day->toDateString();
        if ($date !== '2026-10-22') {
            $this->user->dayResults()->create(['date' => $date, 'limit_uzs' => 100_000]);
        }
        recordTx($this, 'expense', $date === '2026-10-24' ? 150_000 : 50_000, "{$date} 13:00");
        if ($date !== '2026-10-21') {
            $habit->logs()->create(['user_id' => $this->user->id, 'date' => $date, 'value' => 1]);
        }
    }
    $goal = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Машина', 'target_amount' => 100_000_000, 'currency' => 'UZS', 'monthly_amount' => 3_000_000], $this->auth)
        ->assertCreated()->json();
    recordTx($this, 'transfer', 1_000_000, '2026-10-20 12:00', ['to_account_id' => $goal['account_id'], 'to_amount' => 1_000_000]);
    $this->user->records()->create(['kind' => 'cushion', 'ref' => 'cushion|1', 'value' => 1, 'date' => '2026-10-20']);

    $week = $this->getJson('/api/today', $this->auth)->assertOk()->json('week');
    expect($week)->toMatchArray([
        'from' => '2026-10-19', 'to' => '2026-10-25',
        // 22 October was not written down; the 24th went over.
        'limit' => ['kept' => 5, 'days' => 6],
        'habits' => ['done' => 6, 'asked' => 7],
        'goals_uzs' => 1_000_000,
        // 1 000 000 is a third of the month's 3 000 000: ten days of the goal.
        'main' => ['id' => $goal['id'], 'title' => 'Машина', 'days' => 10],
        'prev' => ['limit' => ['kept' => 7, 'days' => 7], 'habits' => ['done' => 7, 'asked' => 7], 'goals_uzs' => 0, 'main' => null],
    ])->and($week['records'])->toHaveCount(1);

    $this->travelTo(Carbon::parse('2026-10-27 09:00', 'Asia/Tashkent'));
    $this->getJson('/api/today', $this->auth)->assertJsonPath('week', null);
});
