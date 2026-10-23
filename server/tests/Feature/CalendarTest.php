<?php

use App\Models\Habit;
use App\Models\User;
use Illuminate\Support\Carbon;

// The calendar feed (App\Calendar\Agenda): own events and everything else with a date, the routine
// underneath, and «План дня» on «Сегодня».

beforeEach(function () {
    // Friday
    $this->travelTo(Carbon::parse('2026-10-23 10:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
});

/** @return array<string, mixed> */
function calendarFeed(string $from, string $to): array
{
    return test()->getJson("/api/calendar?from={$from}&to={$to}", test()->auth)->assertOk()->json();
}

/** @return array<string, array<string, mixed>> the feed's items of one type, by key */
function calendarItems(string $type, string $from, string $to): array
{
    return collect(calendarFeed($from, $to)['items'])->where('type', $type)->keyBy('key')->all();
}

function routineBlock(array $data): array
{
    return test()->postJson('/api/routine', ['kind' => 'other', 'days' => [1, 2, 3, 4, 5, 6, 7], ...$data], test()->auth)->assertCreated()->json();
}

function calendarHabit(array $data): Habit
{
    return test()->user->habits()->create([
        'type' => 'check', 'target' => 1, 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]], 'color' => 'amber', 'icon' => 'dumbbell',
        'started_on' => '2026-10-01', ...$data,
    ]);
}

it('answers with the range, today and now', function () {
    $feed = calendarFeed('2026-10-19', '2026-10-25');

    expect($feed)->toMatchArray(['from' => '2026-10-19', 'to' => '2026-10-25', 'today' => '2026-10-23', 'now' => '10:00', 'items' => [], 'routine' => []]);
    expect($this->getJson('/api/calendar?from=2026-10-19&to=2026-10-25', $this->auth)->getContent())->toContain('"series":{}');
});

it('checks the range', function () {
    $this->getJson('/api/calendar?from=2026-10-01&to=2026-12-31', $this->auth)->assertOk();
    $this->getJson('/api/calendar?from=2026-10-01&to=2027-01-01', $this->auth)
        ->assertJsonValidationErrors(['to' => 'Можно показать не больше 92 дней.']);
    $this->getJson('/api/calendar?from=2026-10-25&to=2026-10-19', $this->auth)->assertJsonValidationErrors('to');
    $this->getJson('/api/calendar?from=23.10.2026', $this->auth)->assertJsonValidationErrors(['from', 'to']);
});

it('shows tasks where they are due and where a repeating one comes round again', function () {
    $daily = $this->postJson('/api/tasks', ['title' => 'Зарядка', 'due_on' => '2026-10-20', 'repeat' => ['unit' => 'day']], $this->auth)->json();
    $call = $this->postJson('/api/tasks', ['title' => 'Позвонить', 'due_on' => '2026-10-22', 'due_time' => '18:00', 'priority' => 2], $this->auth)->json();
    $this->postJson("/api/tasks/{$call['id']}/complete", [], $this->auth)->assertOk();
    $this->postJson('/api/tasks', ['title' => 'Когда-нибудь'], $this->auth);
    $this->postJson('/api/tasks', ['title' => 'Позже', 'due_on' => '2026-10-30', 'repeat' => ['unit' => 'week']], $this->auth);

    $tasks = calendarItems('task', '2026-10-19', '2026-10-25');

    // Overdue on the 20th, and once ticked off it comes round from tomorrow: not today, not before.
    expect(array_keys($tasks))->toBe(["t{$daily['id']}:2026-10-20", "t{$call['id']}:2026-10-22", "t{$daily['id']}:2026-10-24", "t{$daily['id']}:2026-10-25"])
        ->and($tasks["t{$daily['id']}:2026-10-20"])->toMatchArray(['task_id' => $daily['id'], 'title' => 'Зарядка', 'start' => null, 'overdue' => true, 'projected' => false, 'done' => false, 'repeating' => true])
        ->and($tasks["t{$daily['id']}:2026-10-24"])->toMatchArray(['overdue' => false, 'projected' => true])
        ->and($tasks["t{$call['id']}:2026-10-22"])->toMatchArray(['start' => '18:00', 'priority' => 2, 'done' => true, 'overdue' => false, 'repeating' => false]);
});

it('shows payments due, as they will come, and paid', function () {
    $card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 4_000_000]);
    $internet = $this->user->categories()->firstWhere('system_key', 'home_internet');
    $pay = fn (array $data) => $this->postJson('/api/recurring-payments', [
        'amount' => 150_000, 'currency' => 'UZS', 'category_id' => $internet->id, 'account_id' => $card->id, ...$data,
    ], $this->auth)->assertCreated()->json();
    $net = $pay(['name' => 'Интернет', 'day_of_month' => 21, 'next_due_on' => '2026-10-21']);
    $rent = $pay(['name' => 'Аренда', 'amount' => 3_000_000, 'day_of_month' => 5, 'next_due_on' => '2026-10-05']);
    $paid = $this->postJson("/api/recurring-payments/{$net['id']}/pay", ['amount' => 155_000], $this->auth)->assertOk()->json('transaction');

    $payments = calendarItems('payment', '2026-10-01', '2026-11-30');

    expect(array_keys($payments))->toBe(["p{$rent['id']}:2026-10-05", "px{$paid['id']}", "p{$rent['id']}:2026-11-05", "p{$net['id']}:2026-11-21"])
        ->and($payments["p{$rent['id']}:2026-10-05"])->toMatchArray(['payment_id' => $rent['id'], 'title' => 'Аренда', 'amount' => 3_000_000, 'overdue' => true, 'projected' => false, 'paid' => false])
        ->and($payments["p{$rent['id']}:2026-11-05"])->toMatchArray(['overdue' => false, 'projected' => true])
        ->and($payments["px{$paid['id']}"])->toMatchArray(['date' => '2026-10-23', 'payment_id' => $net['id'], 'amount' => 155_000, 'paid' => true, 'transaction_id' => $paid['id']])
        ->and($payments["p{$net['id']}:2026-11-21"])->toMatchArray(['projected' => false, 'overdue' => false]);
});

it('shows paydays, goal deadlines and money promised back', function () {
    $salary = $this->user->incomeSchedules()->create(['name' => 'Зарплата', 'day_of_month' => 31, 'expected_amount' => 12_000_000, 'currency' => 'UZS']);
    $this->user->incomeSchedules()->create(['name' => 'Старая', 'day_of_month' => 10, 'expected_amount' => 1, 'currency' => 'UZS', 'active' => false]);
    $car = $this->user->goals()->create(['type' => 'other', 'title' => 'Dongfeng 008', 'icon' => 'car', 'deadline' => '2026-11-15', 'started_on' => '2026-10-01']);
    $this->user->goals()->create(['type' => 'other', 'title' => 'Done', 'deadline' => '2026-11-01', 'started_on' => '2026-10-01', 'done_at' => now()]);
    $this->user->goals()->create(['type' => 'other', 'title' => 'Archived', 'deadline' => '2026-11-01', 'started_on' => '2026-10-01', 'archived_at' => now()]);
    $aziz = $this->user->accounts()->create(['name' => 'Азиз', 'type' => 'lent', 'currency' => 'USD', 'opening_balance' => 20_000, 'spendable' => false, 'due_on' => '2026-10-20']);
    $this->user->accounts()->create(['name' => 'Вернул', 'type' => 'lent', 'currency' => 'UZS', 'opening_balance' => 0, 'spendable' => false, 'due_on' => '2026-10-24']);

    $items = collect(calendarFeed('2026-10-01', '2026-11-30')['items'])->keyBy('key');

    expect($items->where('type', 'income')->keys()->all())->toBe(["i{$salary->id}:2026-10-31", "i{$salary->id}:2026-11-30"])
        ->and($items["i{$salary->id}:2026-11-30"])->toMatchArray(['title' => 'Зарплата', 'income_id' => $salary->id, 'amount' => 12_000_000, 'currency' => 'UZS'])
        ->and($items->where('type', 'goal')->values()->all())->toBe([[
            'type' => 'goal', 'key' => "g{$car->id}", 'date' => '2026-11-15', 'title' => 'Dongfeng 008', 'goal_id' => $car->id, 'goal_type' => 'other', 'icon' => 'car',
        ]])
        ->and($items->where('type', 'lent')->values()->all())->toBe([[
            'type' => 'lent', 'key' => "l{$aziz->id}", 'date' => '2026-10-20', 'title' => 'Азиз', 'account_id' => $aziz->id, 'amount' => 20_000, 'currency' => 'USD', 'overdue' => true,
        ]]);
});

it('shows habits day by day in a short range', function () {
    $gym = calendarHabit(['name' => 'Зал']);
    $coffee = calendarHabit(['name' => 'Кофе', 'type' => 'limit', 'target' => 2, 'unit' => 'чашки']);
    $read = calendarHabit(['name' => 'Чтение', 'schedule' => ['per_week' => 3]]);
    $late = calendarHabit(['name' => 'Новая', 'started_on' => '2026-10-24']);
    calendarHabit(['name' => 'В архиве', 'archived_at' => now()]);
    foreach ([[$gym, '2026-10-20', 1], [$gym, '2026-10-23', 1], [$coffee, '2026-10-22', 3], [$read, '2026-10-20', 1]] as [$habit, $date, $value]) {
        $habit->logs()->create(['user_id' => $this->user->id, 'date' => $date, 'value' => $value]);
    }
    routineBlock(['title' => 'Спорт', 'kind' => 'sport', 'start_time' => '07:00', 'end_time' => '08:00', 'days' => [1, 3, 5], 'habit_id' => $gym->id]);

    $habits = collect(calendarItems('habit', '2026-10-19', '2026-10-25'));
    $states = fn (Habit $h) => $habits->where('habit_id', $h->id)->mapWithKeys(fn ($i) => [$i['date'] => $i['state']])->all();

    expect($states($gym))->toBe([
        '2026-10-19' => 'missed', '2026-10-20' => 'done', '2026-10-21' => 'missed', '2026-10-22' => 'missed',
        '2026-10-23' => 'done', '2026-10-24' => 'future', '2026-10-25' => 'future',
    ])
        // A limit is kept on a day nothing was logged.
        ->and($states($coffee))->toMatchArray(['2026-10-21' => 'done', '2026-10-22' => 'over', '2026-10-23' => 'done', '2026-10-24' => 'future'])
        // «3 раза в неделю»: on the day it was done, and today while the week still needs it.
        ->and($states($read))->toBe(['2026-10-20' => 'done', '2026-10-23' => 'open'])
        ->and($states($late))->toBe(['2026-10-24' => 'future', '2026-10-25' => 'future'])
        ->and($habits->pluck('title')->unique()->values()->all())->not->toContain('В архиве');
    expect($habits["h{$gym->id}:2026-10-21"])->toMatchArray(['start' => '07:00', 'value' => 0, 'target' => 1, 'habit_type' => 'check', 'icon' => 'dumbbell', 'color' => 'amber'])
        ->and($habits["h{$gym->id}:2026-10-22"]['start'])->toBeNull()
        ->and($habits["h{$coffee->id}:2026-10-22"])->toMatchArray(['value' => 3, 'unit' => 'чашки']);

    // Not in a month.
    expect(calendarItems('habit', '2026-10-01', '2026-10-30'))->toBe([]);
});

it('lays the routine under the days, with the night from the day before', function () {
    $sleep = routineBlock(['title' => 'Сон', 'kind' => 'sleep', 'start_time' => '23:00', 'end_time' => '07:00']);
    $evening = routineBlock(['title' => 'Вечер', 'kind' => 'family', 'start_time' => '21:00', 'end_time' => '00:00']);
    $lunch = routineBlock(['title' => 'Обед', 'kind' => 'meal', 'start_time' => '13:00', 'end_time' => '14:00', 'days' => [1, 2, 3, 4, 5], 'remind' => true]);

    $routine = collect(calendarFeed('2026-10-23', '2026-10-24')['routine']);

    expect($routine->pluck('key')->all())->toBe([
        "r{$sleep['id']}:2026-10-22",
        "r{$lunch['id']}:2026-10-23", "r{$evening['id']}:2026-10-23", "r{$sleep['id']}:2026-10-23",
        "r{$evening['id']}:2026-10-24", "r{$sleep['id']}:2026-10-24",
    ])
        ->and($routine->first())->toBe([
            'block_id' => $sleep['id'], 'key' => "r{$sleep['id']}:2026-10-22", 'date' => '2026-10-22', 'end_date' => '2026-10-23',
            'start' => '23:00', 'end' => '07:00', 'title' => 'Сон', 'kind' => 'sleep', 'habit_id' => null, 'remind' => false,
        ])
        ->and($routine->firstWhere('key', "r{$evening['id']}:2026-10-23")['end_date'])->toBe('2026-10-23')
        ->and($routine->firstWhere('key', "r{$lunch['id']}:2026-10-23")['remind'])->toBeTrue();
});

it('gives the series of an exception even when the series has nothing in the range', function () {
    $series = $this->postJson('/api/events', [
        'title' => 'Курсы', 'starts_on' => '2026-08-01', 'start_time' => '18:00', 'end_time' => '19:00',
        'repeat' => ['unit' => 'day'], 'repeat_until' => '2026-08-31',
    ], $this->auth)->json();
    $moved = $this->patchJson("/api/events/{$series['id']}?scope=one&date=2026-08-20", ['starts_on' => '2026-10-24'], $this->auth)->json('event');

    $feed = calendarFeed('2026-10-19', '2026-10-25');

    expect(collect($feed['items'])->pluck('key')->all())->toBe(["e{$moved['id']}:2026-10-24"])
        ->and($feed['items'][0])->toMatchArray([
            'event_id' => $moved['id'], 'series_id' => $series['id'], 'end_date' => '2026-10-24', 'start' => '18:00', 'end' => '19:00',
            'all_day' => false, 'color' => 'indigo', 'repeating' => true, 'remind_before' => null, 'notes' => null,
        ])
        ->and($feed['series'][$series['id']]['repeat_until'])->toBe('2026-08-31')
        ->and($feed['series'][$moved['id']]['series_date'])->toBe('2026-08-20');
});

it('keeps the feed to its owner', function () {
    $other = User::factory()->create();
    $other->events()->create(['title' => 'Чужое', 'starts_on' => '2026-10-23', 'ends_on' => '2026-10-23']);
    $other->tasks()->create(['title' => 'Чужая', 'due_on' => '2026-10-23']);
    $other->routineBlocks()->create(['title' => 'Сон', 'kind' => 'sleep', 'start_time' => '23:00', 'end_time' => '07:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]);
    $other->habits()->create(['name' => 'Чужая', 'type' => 'check', 'schedule' => ['days' => [5]], 'started_on' => '2026-10-01']);

    expect(calendarFeed('2026-10-19', '2026-10-25'))->toMatchArray(['items' => [], 'routine' => []]);
});

it('puts the plan of the day on «Сегодня»', function () {
    $this->getJson('/api/today', $this->auth)->assertOk()
        ->assertJsonPath('plan', ['routine' => [], 'events' => []])->assertJsonPath('routine_blocks', 0);

    $sleep = routineBlock(['title' => 'Сон', 'kind' => 'sleep', 'start_time' => '23:00', 'end_time' => '07:00']);
    $work = routineBlock(['title' => 'Работа', 'kind' => 'work', 'start_time' => '09:00', 'end_time' => '18:00', 'days' => [1, 2, 3, 4, 5]]);
    $meeting = $this->postJson('/api/events', ['title' => 'Встреча', 'starts_on' => '2026-10-23', 'start_time' => '14:00', 'end_time' => '15:00'], $this->auth)->json();
    $holiday = $this->postJson('/api/events', ['title' => 'Праздник', 'starts_on' => '2026-10-23'], $this->auth)->json();
    $night = $this->postJson('/api/events', ['title' => 'Поезд', 'starts_on' => '2026-10-22', 'ends_on' => '2026-10-23', 'start_time' => '22:00', 'end_time' => '06:00'], $this->auth)->json();
    $this->postJson('/api/events', ['title' => 'Завтра', 'starts_on' => '2026-10-24'], $this->auth);
    $this->postJson('/api/tasks', ['title' => 'Не событие', 'due_on' => '2026-10-23'], $this->auth);

    $today = $this->getJson('/api/today', $this->auth)->assertOk()->json();

    expect(array_slice(array_keys($today), -2))->toBe(['plan', 'routine_blocks'])
        ->and($today['routine_blocks'])->toBe(2)
        ->and(array_column($today['plan']['routine'], 'key'))->toBe(["r{$sleep['id']}:2026-10-22", "r{$work['id']}:2026-10-23", "r{$sleep['id']}:2026-10-23"])
        // All day first, then by the time each starts today: the night train from yesterday at 00:00.
        ->and(array_column($today['plan']['events'], 'event_id'))->toBe([$holiday['id'], $night['id'], $meeting['id']]);
});
