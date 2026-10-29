<?php

use App\Advisor\Advisor;
use App\Models\Transaction;
use App\Models\User;
use App\Streaks\Summary;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Wednesday; the salary comes on the 10th, so the pay period runs from 10 Oct to 10 Nov.
    $this->travelTo(Carbon::parse('2026-10-21 15:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 4_000_000]);
    $this->cafe = $this->user->categories()->firstWhere('system_key', 'cafe');
    $this->user->incomeSchedules()->create(['name' => 'Зарплата', 'day_of_month' => 10, 'expected_amount' => 12_000_000, 'currency' => 'UZS', 'account_id' => $this->card->id, 'active' => true]);
});

function streakSpend(object $test, int $amount, string $when, array $extra = []): Transaction
{
    return $test->user->transactions()->create([
        'type' => 'expense', 'account_id' => $test->card->id,
        'amount' => $amount, 'currency' => 'UZS', 'rate' => '1.0000', 'amount_uzs' => $amount,
        'category_id' => $test->cafe->id, 'occurred_at' => Carbon::parse($when, 'Asia/Tashkent'), 'source' => 'app',
        ...$extra,
    ]);
}

it('counts days within the limit from the first day written down, a missing day neither way', function () {
    for ($day = 5; $day <= 20; $day++) {
        $date = sprintf('2026-10-%02d', $day);
        if ($day !== 12) {
            $this->user->dayResults()->create(['date' => $date, 'limit_uzs' => 100_000]);
        }
        streakSpend($this, $day === 15 ? 150_000 : 50_000, "{$date} 13:00");
    }
    // A one-off purchase and a paid payment are not everyday spending, as in the limit itself.
    streakSpend($this, 900_000, '2026-10-18 18:00', ['is_one_off' => true]);

    // 5–11 kept (a spare), 12 not written down, 13–14 kept, 15 over (the spare goes), 16–20 kept (a spare again).
    $this->getJson('/api/today', $this->auth)->assertOk()
        ->assertJsonPath('streaks.limit', [
            'current' => 14, 'best' => 14, 'previous_best' => 0, 'unit' => 'day', 'spare' => 1, 'next' => 30,
            'today' => 'within', 'covered' => false,
        ]);

    // Today's overrun breaks nothing while the day lasts, and the spare will take it.
    streakSpend($this, 400_000, '2026-10-21 14:00');
    $this->getJson('/api/today', $this->auth)
        ->assertJsonPath('limit.left_today_uzs', fn (int $left) => $left < 0)
        ->assertJsonPath('streaks.limit.today', 'over')
        ->assertJsonPath('streaks.limit.covered', true)
        ->assertJsonPath('streaks.limit.current', 14);
});

it('counts days with an operation or «Трат не было», and marks such a day a week back at most', function () {
    for ($day = 1; $day <= 20; $day++) {
        if (! in_array($day, [9, 14], true)) {
            streakSpend($this, 10_000, sprintf('2026-10-%02d 09:00', $day));
        }
    }
    $this->putJson('/api/days/2026-10-14/no-spending', ['value' => true], $this->auth)->assertOk()->assertJsonPath('no_spending', true);
    $this->putJson('/api/days/2026-10-13/no-spending', ['value' => true], $this->auth)->assertJsonValidationErrors('date');
    $this->putJson('/api/days/2026-10-22/no-spending', ['value' => true], $this->auth)->assertJsonValidationErrors('date');

    // 1–8 kept (a spare), 9 empty (the spare goes), 10–20 kept with 14 marked: 19 days, a spare again.
    $this->getJson('/api/today', $this->auth)
        ->assertJsonPath('streaks.records', [
            'current' => 19, 'best' => 19, 'previous_best' => 0, 'unit' => 'day', 'spare' => 1, 'next' => 30,
            'today' => 'open', 'no_spending' => false,
        ]);

    $this->putJson('/api/days/2026-10-21/no-spending', ['value' => true], $this->auth)->assertOk()
        ->assertJsonPath('records.current', 20)
        ->assertJsonPath('records.today', 'kept')
        ->assertJsonPath('records.no_spending', true);
    $this->putJson('/api/days/2026-10-21/no-spending', ['value' => false], $this->auth)->assertOk()
        ->assertJsonPath('records.current', 19)
        ->assertJsonPath('records.today', 'open');
});

it('counts pay periods whose contributions were all made, for all goals and for one', function () {
    $goal = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Машина', 'target_amount' => 100_000_000, 'currency' => 'UZS', 'monthly_amount' => 3_100_000], $this->auth)
        ->assertCreated()->json('id');
    $this->user->goals()->whereKey($goal)->update(['started_on' => '2026-06-01']);
    $other = 999;
    $period = fn (string $start, string $end, int $due, array $goals) => $this->user->contributionPeriods()->create([
        'starts_on' => $start, 'ends_on' => $end, 'planned_uzs' => 3_100_000, 'due_uzs' => $due, 'goals' => $goals,
    ]);
    $period('2026-06-10', '2026-07-10', 0, [['id' => $goal, 'planned' => 3_100_000, 'due' => 0]]);
    $period('2026-07-10', '2026-08-10', 500_000, [['id' => $goal, 'planned' => 3_100_000, 'due' => 500_000]]);
    $period('2026-08-10', '2026-09-10', 0, [['id' => $goal, 'planned' => 3_000_000, 'due' => 0], ['id' => $other, 'planned' => 100_000, 'due' => 0]]);
    $period('2026-09-10', '2026-10-10', 0, [['id' => $other, 'planned' => 100_000, 'due' => 0]]);

    // This period still asks for its contribution: it waits, it does not break the streak.
    $this->getJson('/api/goals', $this->auth)->assertOk()
        ->assertJsonPath('streak', ['current' => 2, 'best' => 2, 'previous_best' => 1, 'unit' => 'period', 'spare' => 0, 'next' => null, 'today' => 'open']);
    // The goal had no contribution asked in September: that period counts neither way for it.
    $this->getJson("/api/goals/{$goal}", $this->auth)->assertJsonPath('streak.current', 1)->assertJsonPath('streak.today', 'open');

    $due = $this->getJson('/api/goals', $this->auth)->json('goals.0.period.due');
    $this->postJson('/api/goals/contribute', ['account_id' => $this->card->id, 'items' => [['goal_id' => $goal, 'amount' => $due]]], $this->auth)->assertOk()
        ->assertJsonPath('goals.streak.current', 3)
        ->assertJsonPath('goals.streak.today', 'kept');
    $this->getJson('/api/today', $this->auth)
        ->assertJsonPath('streaks.contributions.current', 3)
        ->assertJsonPath('goals.streak.current', 3);
    $this->getJson("/api/goals/{$goal}", $this->auth)->assertJsonPath('streak.current', 2);
});

it('writes down the day\'s limit and the period\'s contributions through the day', function () {
    $goal = $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Машина', 'target_amount' => 100_000_000, 'currency' => 'UZS', 'monthly_amount' => 3_100_000], $this->auth)->json('id');
    streakSpend($this, 60_000, '2026-10-21 13:00');

    $this->artisan('streaks:close-day')->assertSuccessful();
    $this->travelTo(Carbon::parse('2026-10-21 23:55', 'Asia/Tashkent'));
    streakSpend($this, 40_000, '2026-10-21 20:00');
    $this->artisan('streaks:close-day')->assertSuccessful();

    $limit = $this->getJson('/api/today', $this->auth)->json('limit');
    $day = $this->user->dayResults()->sole();
    expect(substr((string) $day->date, 0, 10))->toBe('2026-10-21')
        ->and($day->limit_uzs)->toBe($limit['limit_uzs'])
        ->and($day->spent_uzs)->toBe(100_000);

    $period = $this->user->contributionPeriods()->sole();
    $plan = $this->getJson('/api/goals', $this->auth);
    expect(substr((string) $period->starts_on, 0, 10))->toBe('2026-10-10')
        ->and(substr((string) $period->ends_on, 0, 10))->toBe('2026-11-10')
        ->and($period->due_uzs)->toBe($plan->json('due_uzs'))
        ->and($period->goals)->toBe([['id' => $goal, 'planned' => $plan->json('goals.0.period.planned'), 'due' => $plan->json('goals.0.period.due')]]);

    // The next day counts yesterday, written down at 23:55.
    $this->travelTo(Carbon::parse('2026-10-22 10:00', 'Asia/Tashkent'));
    $this->getJson('/api/today', $this->auth)->assertJsonPath('streaks.limit.current', 1);
});

it('answers the evening reminder with «Трат не было»', function () {
    // Recorded yesterday: an operation saved today, for any day, already answers the reminder.
    $this->travelTo(Carbon::parse('2026-10-20 13:00', 'Asia/Tashkent'));
    streakSpend($this, 10_000, '2026-10-20 13:00');
    $this->travelTo(Carbon::parse('2026-10-21 21:00', 'Asia/Tashkent'));
    app(Advisor::class)->run($this->user, 'evening', now());

    $insight = $this->user->insights()->where('rule', 'forgot_to_record')->sole();
    $this->getJson('/api/insights', $this->auth)->assertJsonPath('data.0.action', 'no_spending');
    $this->postJson("/api/insights/{$insight->id}/action", [], $this->auth)->assertOk()
        ->assertJsonPath('date', '2026-10-21')
        ->assertJsonPath('insight.action', null);
    expect($this->user->dayResults()->where('no_spending', true)->count())->toBe(1);

    // Asked and answered: the next evening run does not ask again.
    $insight->delete();
    app(Advisor::class)->run($this->user, 'evening', now());
    expect($this->user->insights()->where('rule', 'forgot_to_record')->count())->toBe(0);
});

it('tells the bot which streaks end tonight unless something is done', function () {
    for ($day = 7; $day <= 20; $day++) {
        streakSpend($this, 10_000, sprintf('2026-10-%02d 09:00', $day));
    }
    // A spare covers a missed day: only a streak with no spare left is at risk.
    $this->user->transactions()->whereDate('occurred_at', '2026-10-15')->forceDelete();
    $this->user->transactions()->whereDate('occurred_at', '2026-10-16')->forceDelete();

    $id = $this->postJson('/api/habits', ['name' => 'Бег', 'type' => 'check', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]]], $this->auth)->json('id');
    $this->user->habits()->whereKey($id)->update(['started_on' => '2026-10-13']);
    // 13–19 kept earn a spare, and yesterday's miss spent it: tonight decides.
    for ($day = 13; $day <= 19; $day++) {
        $this->putJson("/api/habits/{$id}/logs/2026-10-{$day}", ['value' => 1], $this->auth)->assertOk();
    }

    $summary = app(Summary::class)->for($this->user, now());
    // Records: 7–14 kept (a spare), 15 spends it, 16 breaks; 17–20 is 4 days: too short to matter.
    expect($summary['money']['records']['current'])->toBe(4)
        ->and($summary['at_risk'])->toBe([['kind' => 'habit', 'id' => $id, 'name' => 'Бег', 'current' => 7, 'unit' => 'day']]);
});
