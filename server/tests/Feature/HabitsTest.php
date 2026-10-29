<?php

use App\Models\Habit;
use App\Models\User;
use Illuminate\Support\Carbon;

// Habits (docs/PLAN.md, step 1.6): three types, schedules, one-tap logs and streaks.

beforeEach(function () {
    // Wednesday
    $this->travelTo(Carbon::parse('2026-10-21 15:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
});

function newHabit(object $test, array $data, string $startedOn = '2026-10-01'): Habit
{
    $id = $test->postJson('/api/habits', $data, $test->auth)->assertCreated()->json('id');
    $habit = Habit::findOrFail($id);
    $habit->update(['started_on' => $startedOn]);

    return $habit;
}

function logHabit(object $test, Habit $habit, string $date, int $value = 1)
{
    return $test->putJson("/api/habits/{$habit->id}/logs/{$date}", ['value' => $value], $test->auth)->assertOk();
}

it('validates the three types and their schedules', function () {
    $this->postJson('/api/habits', ['name' => 'Вода', 'type' => 'count', 'target' => 0, 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]]], $this->auth)
        ->assertJsonValidationErrors('target');
    $this->postJson('/api/habits', ['name' => 'Кофе', 'type' => 'limit', 'target' => 2, 'schedule' => ['per_week' => 3]], $this->auth)
        ->assertJsonValidationErrors('schedule');
    $this->postJson('/api/habits', ['name' => 'X', 'type' => 'nope', 'schedule' => []], $this->auth)
        ->assertJsonValidationErrors(['type', 'schedule.days', 'schedule.per_week']);

    $read = $this->postJson('/api/habits', ['name' => ' Чтение ', 'type' => 'check', 'target' => 30, 'schedule' => ['days' => [5, 1, 3]], 'color' => 'blue'], $this->auth)
        ->assertCreated()->json();
    expect($read)->toMatchArray(['name' => 'Чтение', 'target' => 1, 'schedule' => ['days' => [1, 3, 5]], 'started_on' => '2026-10-21', 'color' => 'blue'])
        ->and($read['today'])->toBe(['value' => 0, 'state' => 'open']);
});

it('counts a daily streak that today does not break until it is over', function () {
    $habit = newHabit($this, ['name' => 'Зарядка', 'type' => 'check', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]]]);
    foreach (['2026-10-10', '2026-10-11', '2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20'] as $date) {
        logHabit($this, $habit, $date);
    }

    $today = $this->getJson('/api/habits', $this->auth)->json('0');
    expect($today['streak'])->toBe(['current' => 4, 'best' => 4, 'unit' => 'day', 'spare' => 0, 'next' => 7])
        ->and($today['today']['state'])->toBe('open')
        ->and(collect($today['days'])->pluck('kept')->all())->toBe([false, false, true, true, true, true, false]);

    logHabit($this, $habit, '2026-10-21')->assertJsonPath('streak.current', 5)->assertJsonPath('today.state', 'done');
    // Tapping again takes the mark back.
    logHabit($this, $habit, '2026-10-21', 0)->assertJsonPath('streak.current', 4);
    // A check habit never stores more than 1.
    logHabit($this, $habit, '2026-10-21', 7)->assertJsonPath('today.value', 1);
});

it('skips days off the schedule', function () {
    // Mon, Wed, Fri
    $habit = newHabit($this, ['name' => 'Бег', 'type' => 'check', 'schedule' => ['days' => [1, 3, 5]]]);
    foreach (['2026-10-12', '2026-10-14', '2026-10-16', '2026-10-19'] as $date) {
        logHabit($this, $habit, $date);
    }

    expect($habit->toApi()['streak']['current'])->toBe(4);
    // On a Tuesday it is not part of the day at all.
    expect($habit->toApi(Carbon::parse('2026-10-20', 'Asia/Tashkent'))['today']['state'])->toBe('off');
});

it('needs the target for a count habit and the limit for a limit habit', function () {
    $water = newHabit($this, ['name' => 'Вода', 'type' => 'count', 'target' => 8, 'unit' => 'стаканов', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]]], '2026-10-19');
    logHabit($this, $water, '2026-10-19', 8);
    logHabit($this, $water, '2026-10-20', 5);
    logHabit($this, $water, '2026-10-21', 3)->assertJsonPath('today.state', 'open')->assertJsonPath('streak.current', 0)->assertJsonPath('streak.best', 1);

    // A limit is kept on days nothing was logged, and today breaks only once it goes over.
    $coffee = newHabit($this, ['name' => 'Кофе', 'type' => 'limit', 'target' => 2, 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]]], '2026-10-15');
    logHabit($this, $coffee, '2026-10-17', 3);
    logHabit($this, $coffee, '2026-10-21', 2)->assertJsonPath('today.state', 'done')->assertJsonPath('streak.current', 4);
    logHabit($this, $coffee, '2026-10-21', 3)->assertJsonPath('today.state', 'over')->assertJsonPath('streak.current', 0)->assertJsonPath('streak.best', 3);
});

it('counts weeks for a times-a-week habit', function () {
    // Weeks from Monday: 5 Oct (2 times, missed), 12 Oct (3, kept), this week so far 1.
    $habit = newHabit($this, ['name' => 'Зал', 'type' => 'check', 'schedule' => ['per_week' => 3]], '2026-10-05');
    foreach (['2026-10-06', '2026-10-08', '2026-10-12', '2026-10-14', '2026-10-16', '2026-10-20'] as $date) {
        logHabit($this, $habit, $date);
    }

    $api = $habit->fresh()->toApi();
    expect($api['streak'])->toBe(['current' => 1, 'best' => 1, 'unit' => 'week', 'spare' => 0, 'next' => 4])
        ->and($api['week_count'])->toBe(1)->and($api['today']['state'])->toBe('open');

    logHabit($this, $habit, '2026-10-21');
    logHabit($this, $habit, '2026-10-19')->assertJsonPath('streak.current', 2)->assertJsonPath('week_count', 3);
    // Three done this week: nothing left for today, but today's mark still shows as done.
    expect($habit->fresh()->toApi()['today']['state'])->toBe('done');
});

it('refuses future days and moves the start back for an earlier day', function () {
    $habit = newHabit($this, ['name' => 'Чтение', 'type' => 'check', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]]], '2026-10-21');
    $this->putJson("/api/habits/{$habit->id}/logs/2026-10-22", ['value' => 1], $this->auth)->assertJsonValidationErrors('date');
    $this->putJson("/api/habits/{$habit->id}/logs/2026-13-01", ['value' => 1], $this->auth)->assertJsonValidationErrors('date');

    logHabit($this, $habit, '2026-10-20')->assertJsonPath('started_on', '2026-10-20')->assertJsonPath('streak.current', 1);
});

it('archives, deletes softly and restores', function () {
    $habit = newHabit($this, ['name' => 'Old', 'type' => 'check', 'schedule' => ['days' => [1]]]);
    $this->patchJson("/api/habits/{$habit->id}", ['archived' => true], $this->auth)->assertJsonPath('archived', true);
    $this->deleteJson("/api/habits/{$habit->id}", [], $this->auth)->assertNoContent();
    $this->getJson('/api/habits', $this->auth)->assertJsonCount(0);
    $this->postJson("/api/habits/{$habit->id}/restore", [], $this->auth)->assertOk();
});

it('puts today\'s tasks and habits on «Сегодня»', function () {
    $this->user->tasks()->create(['title' => 'Later', 'due_on' => '2026-10-25']);
    $this->user->tasks()->create(['title' => 'Someday']);
    $this->user->tasks()->create(['title' => 'Today', 'due_on' => '2026-10-21']);
    $this->user->tasks()->create(['title' => 'Urgent today', 'due_on' => '2026-10-21', 'priority' => 2]);
    $this->user->tasks()->create(['title' => 'Overdue', 'due_on' => '2026-10-20']);
    $this->user->tasks()->create(['title' => 'Done', 'due_on' => '2026-10-21', 'done_at' => now()]);
    newHabit($this, ['name' => 'Every day', 'type' => 'check', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]]]);
    newHabit($this, ['name' => 'Weekends', 'type' => 'check', 'schedule' => ['days' => [6, 7]]]);

    $today = $this->getJson('/api/today', $this->auth)->assertOk();
    expect(collect($today->json('tasks'))->pluck('title')->all())->toBe(['Overdue', 'Urgent today', 'Today'])
        ->and($today->json('tasks_later'))->toBe(2)
        ->and(collect($today->json('habits'))->pluck('name')->all())->toBe(['Every day']);
});

// Spares, pauses and the habit's screen (docs/PLAN.md, D37).

it('covers one missed day with a spare earned by seven kept days', function () {
    $habit = newHabit($this, ['name' => 'Зарядка', 'type' => 'check', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]]], '2026-10-10');
    // 10–16 kept (7 days, a spare), 17 missed, 18–20 kept.
    foreach (['10', '11', '12', '13', '14', '15', '16', '18', '19', '20'] as $day) {
        logHabit($this, $habit, "2026-10-{$day}");
    }

    $api = $habit->fresh()->toApi();
    expect($api['streak'])->toBe(['current' => 10, 'best' => 10, 'unit' => 'day', 'spare' => 0, 'next' => 14])
        ->and(collect($api['days'])->firstWhere('date', '2026-10-17'))->toMatchArray(['kept' => false, 'spared' => true]);

    // A second miss with no spare left breaks it.
    $this->travelTo(Carbon::parse('2026-10-23 09:00', 'Asia/Tashkent'));
    expect($habit->fresh()->toApi()['streak']['current'])->toBe(0);
});

it('lets a pause hold a streak and takes paused habits off today', function () {
    $run = newHabit($this, ['name' => 'Бег', 'type' => 'check', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]]], '2026-10-14');
    $coffee = newHabit($this, ['name' => 'Кофе', 'type' => 'limit', 'target' => 2, 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]]], '2026-10-14');
    foreach (['14', '15', '16', '19'] as $day) {
        logHabit($this, $run, "2026-10-{$day}");
    }
    // Ill from the 17th: the 19th was kept anyway; nothing to do today.
    $this->postJson('/api/habit-pauses', ['starts_on' => '2026-10-17', 'ends_on' => '2026-10-22'], $this->auth)->assertCreated();
    logHabit($this, $coffee, '2026-10-18', 5);

    $api = $run->fresh()->toApi();
    expect($api['streak']['current'])->toBe(4)
        ->and($api['today']['state'])->toBe('off')
        ->and(collect($api['days'])->firstWhere('date', '2026-10-18'))->toMatchArray(['paused' => true, 'kept' => false]);
    // A limit broken in a pause does not break it; days with nothing logged in it count neither way.
    expect($coffee->fresh()->toApi()['streak']['current'])->toBe(3);

    $this->getJson('/api/today', $this->auth)->assertJsonCount(1, 'habits')->assertJsonPath('habits.0.name', 'Кофе');
});

it('asks fewer times of a paused week and gives a spare week for four kept ones', function () {
    // Twice a week from Monday 14 Sep; weeks of 14, 21, 28 Sep and 5 Oct kept, 12 Oct missed.
    $habit = newHabit($this, ['name' => 'Зал', 'type' => 'check', 'schedule' => ['per_week' => 2]], '2026-09-14');
    foreach (['2026-09-14', '2026-09-15', '2026-09-21', '2026-09-22', '2026-09-28', '2026-09-29', '2026-10-05', '2026-10-06'] as $date) {
        logHabit($this, $habit, $date);
    }
    expect($habit->fresh()->toApi()['streak'])->toMatchArray(['current' => 4, 'spare' => 0]);

    // This week is away from Tuesday: Monday alone is enough.
    $this->postJson('/api/habit-pauses', ['starts_on' => '2026-10-20', 'ends_on' => '2026-10-25'], $this->auth)->assertCreated();
    logHabit($this, $habit, '2026-10-19')->assertJsonPath('streak.current', 5)->assertJsonPath('today.state', 'off');
});

it('shows a habit\'s year, its numbers and the days of the main goal it saved', function () {
    $habit = newHabit($this, ['name' => 'Без доставки', 'type' => 'check', 'saves_uzs' => 40000, 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]]], '2026-10-12');
    foreach (['12', '13', '14', '16', '17', '18', '19', '20'] as $day) {
        logHabit($this, $habit, "2026-10-{$day}");
    }
    // The main goal puts aside 3 044 000 a month, about 100 000 a day.
    $this->postJson('/api/goals', ['type' => 'save', 'title' => 'Машина', 'target_amount' => 100_000_000, 'currency' => 'UZS', 'monthly_amount' => 3_043_750], $this->auth)->assertCreated();

    $history = $this->getJson("/api/habits/{$habit->id}/history", $this->auth)->assertOk();
    $days = collect($history->json('days'));
    expect($days)->toHaveCount(52 * 7 + 3)
        ->and($days->first()['date'])->toBe('2025-10-20')
        ->and($days->last())->toBe(['date' => '2026-10-21', 'value' => 0, 'state' => 'open'])
        ->and($days->firstWhere('date', '2026-10-11')['state'])->toBe('none')
        ->and($days->firstWhere('date', '2026-10-15')['state'])->toBe('missed')
        ->and($history->json('habit.streak.current'))->toBe(5)
        ->and($history->json('stats'))->toMatchArray([
            'rate' => round(8 / 9, 3), 'rate_over' => '30d', 'kept_days' => 8, 'total_value' => null,
            'saved_uzs' => 320000, 'streak_saved_uzs' => 200000,
        ])
        ->and($history->json('goal'))->toMatchArray(['title' => 'Машина', 'days' => 3, 'streak_days' => 2]);
});

it('checks the dates of a pause and ends one early', function () {
    $this->postJson('/api/habit-pauses', ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-10'], $this->auth)->assertJsonValidationErrors('starts_on');
    $this->postJson('/api/habit-pauses', ['starts_on' => '2026-10-25', 'ends_on' => '2026-10-24'], $this->auth)->assertJsonValidationErrors('ends_on');
    $this->postJson('/api/habit-pauses', ['starts_on' => '2026-10-21', 'ends_on' => '2027-02-01'], $this->auth)->assertJsonValidationErrors('ends_on');

    $id = $this->postJson('/api/habit-pauses', ['starts_on' => '2026-10-20', 'ends_on' => '2026-10-30'], $this->auth)->assertCreated()->json('id');
    $this->postJson('/api/habit-pauses', ['starts_on' => '2026-10-30', 'ends_on' => '2026-11-02'], $this->auth)->assertJsonValidationErrors('starts_on');

    $this->patchJson("/api/habit-pauses/{$id}", ['ends_on' => '2026-10-21'], $this->auth)->assertOk()->assertJsonPath('ends_on', '2026-10-21');
    $this->getJson('/api/habit-pauses', $this->auth)->assertJsonCount(1)->assertJsonPath('0.starts_on', '2026-10-20');
    $this->deleteJson("/api/habit-pauses/{$id}", [], $this->auth)->assertNoContent();
    $this->getJson('/api/habit-pauses', $this->auth)->assertJsonCount(0);
});
