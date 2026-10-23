<?php

use App\Calendar\Reminders;
use App\Models\CalendarReminder;
use App\Models\User;
use Illuminate\Support\Carbon;

// Calendar reminders for the bot (App\Calendar\Reminders): due within a 15-minute window, once each,
// whatever the hour.

beforeEach(function () {
    // Friday
    $this->travelTo(Carbon::parse('2026-10-23 08:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
    $this->reminders = app(Reminders::class);
});

function remindingEvent(array $data): array
{
    return test()->postJson('/api/events', ['title' => 'Встреча', 'starts_on' => '2026-10-23', ...$data], test()->auth)->assertCreated()->json();
}

/** @return list<string> keys of the reminders due at that moment */
function remindersDueAt(string $moment): array
{
    return test()->reminders->due(test()->user, Carbon::parse($moment, 'Asia/Tashkent'))->pluck('key')->all();
}

it('is due from its time for fifteen minutes', function () {
    $event = remindingEvent(['start_time' => '10:30', 'end_time' => '11:30', 'remind_before' => 10]);
    $key = "e{$event['id']}:2026-10-23T10:20";

    expect(remindersDueAt('2026-10-23 10:19'))->toBe([])
        ->and(remindersDueAt('2026-10-23 10:20'))->toBe([$key])
        ->and(remindersDueAt('2026-10-23 10:34'))->toBe([$key])
        ->and(remindersDueAt('2026-10-23 10:35'))->toBe([]);

    $reminder = $this->reminders->due($this->user, Carbon::parse('2026-10-23 10:20:40', 'Asia/Tashkent'))->first();
    expect($reminder)->toMatchArray(['key' => $key, 'kind' => 'event', 'title' => 'Встреча', 'date' => '2026-10-23', 'start' => '10:30', 'end' => '11:30', 'all_day' => false, 'remind_before' => 10])
        ->and($reminder['remind_at']->format('Y-m-d H:i'))->toBe('2026-10-23 10:20');
});

it('reminds a day ahead, the day before at 09:00 for an all-day event, and across midnight', function () {
    $tomorrow = remindingEvent(['starts_on' => '2026-10-24', 'start_time' => '10:00', 'end_time' => '11:00', 'remind_before' => 1440]);
    $holiday = remindingEvent(['title' => 'Праздник', 'starts_on' => '2026-10-24', 'remind_before' => 1440]);
    $early = remindingEvent(['title' => 'Рейс', 'starts_on' => '2026-10-24', 'start_time' => '00:05', 'end_time' => '02:00', 'remind_before' => 10]);
    $sameDay = remindingEvent(['title' => 'Вебинар', 'starts_on' => '2026-10-24', 'remind_before' => 0]);

    expect(remindersDueAt('2026-10-23 10:00'))->toBe(["e{$tomorrow['id']}:2026-10-23T10:00"])
        ->and(remindersDueAt('2026-10-23 09:00'))->toBe(["e{$holiday['id']}:2026-10-23T09:00"])
        ->and(remindersDueAt('2026-10-23 23:55'))->toBe(["e{$early['id']}:2026-10-23T23:55"])
        ->and(remindersDueAt('2026-10-24 09:05'))->toBe(["e{$sameDay['id']}:2026-10-24T09:00"]);
});

it('reminds again at the new time of an event moved after its reminder went out', function () {
    $event = remindingEvent(['start_time' => '14:00', 'end_time' => '15:00', 'remind_before' => 0]);
    [$first] = remindersDueAt('2026-10-23 14:00');
    expect($this->reminders->claim($this->user, $first, now()))->toBeTrue();

    $this->patchJson("/api/events/{$event['id']}", ['start_time' => '14:10', 'end_time' => '15:10'], $this->auth)->assertOk();

    expect(remindersDueAt('2026-10-23 14:05'))->toBe([])
        ->and(remindersDueAt('2026-10-23 14:10'))->toBe(["e{$event['id']}:2026-10-23T14:10"]);
});

it('never reminds of a skipped occurrence', function () {
    $daily = remindingEvent(['starts_on' => '2026-10-19', 'start_time' => '09:00', 'end_time' => '09:30', 'repeat' => ['unit' => 'day'], 'remind_before' => 0]);
    $this->deleteJson("/api/events/{$daily['id']}?scope=one&date=2026-10-23", [], $this->auth)->assertOk();

    expect(remindersDueAt('2026-10-23 09:00'))->toBe([])
        ->and(remindersDueAt('2026-10-24 09:00'))->toBe(["e{$daily['id']}:2026-10-24T09:00"]);
});

it('reminds of a routine block as it starts on its weekdays', function () {
    $post = fn (array $data) => $this->postJson('/api/routine', ['kind' => 'work', 'days' => [1, 2, 3, 4, 5], ...$data], $this->auth)->assertCreated()->json();
    $work = $post(['title' => 'Работа', 'start_time' => '09:00', 'end_time' => '18:00', 'remind' => true]);
    $post(['title' => 'Обед', 'start_time' => '13:00', 'end_time' => '14:00']);
    $night = $post(['title' => 'Смена', 'start_time' => '23:58', 'end_time' => '06:00', 'remind' => true]);

    $due = $this->reminders->due($this->user, Carbon::parse('2026-10-23 09:01', 'Asia/Tashkent'));
    expect($due->pluck('key')->all())->toBe(["r{$work['id']}:2026-10-23T09:00"])
        ->and($due->first())->toMatchArray(['kind' => 'routine', 'title' => 'Работа', 'date' => '2026-10-23', 'start' => '09:00', 'end' => '18:00', 'all_day' => false])
        ->and(remindersDueAt('2026-10-23 13:00'))->toBe([])
        // Saturday: not one of its days. Just after midnight: Friday's night shift.
        ->and(remindersDueAt('2026-10-24 09:00'))->toBe([])
        ->and(remindersDueAt('2026-10-24 00:05'))->toBe(["r{$night['id']}:2026-10-23T23:58"]);
});

it('hands each reminder out once, and again when given back', function () {
    remindingEvent(['start_time' => '10:30', 'end_time' => '11:00', 'remind_before' => 30]);
    [$key] = remindersDueAt('2026-10-23 10:00');

    expect($this->reminders->claim($this->user, $key, now()))->toBeTrue()
        ->and($this->reminders->claim($this->user, $key, now()))->toBeFalse()
        ->and(remindersDueAt('2026-10-23 10:01'))->toBe([]);

    $this->reminders->release($this->user, $key);
    expect(remindersDueAt('2026-10-23 10:02'))->toBe([$key])
        ->and($this->reminders->claim($this->user, $key, now()))->toBeTrue();
});

it('reminds late in the evening too: quiet hours are not for reminders asked for', function () {
    $event = remindingEvent(['title' => 'Таблетка', 'start_time' => '23:00', 'end_time' => '23:15', 'remind_before' => 0]);

    expect(remindersDueAt('2026-10-23 23:00'))->toBe(["e{$event['id']}:2026-10-23T23:00"]);
});

it('forgets handed-out reminders after a few days', function () {
    $this->reminders->claim($this->user, 'e1:2026-10-23T10:00', now());
    $this->travel(4)->days();
    $this->artisan('model:prune', ['--model' => [CalendarReminder::class]])->assertSuccessful();

    expect(CalendarReminder::count())->toBe(0);
});

it('does not remind again when only the title of a reminded occurrence changes', function () {
    $event = $this->postJson('/api/events', ['title' => 'Зарядка', 'starts_on' => '2026-10-23', 'start_time' => '10:30', 'end_time' => '11:00',
        'repeat' => ['unit' => 'day'], 'remind_before' => 10], $this->auth)->json();
    [$key] = remindersDueAt('2026-10-23 10:20');
    $this->reminders->claim($this->user, $key, now());

    $one = $this->patchJson("/api/events/{$event['id']}?scope=one&date=2026-10-23", ['title' => 'Зарядка на улице'], $this->auth)->assertOk()->json('event');
    expect(remindersDueAt('2026-10-23 10:22'))->toBe([]);

    // Moved to a later time, it reminds again.
    $this->patchJson("/api/events/{$one['id']}", ['start_time' => '10:40', 'end_time' => '11:10'], $this->auth)->assertOk();
    expect(remindersDueAt('2026-10-23 10:30'))->toBe(["e{$event['id']}:2026-10-23T10:30"]);
});

it('does not remind again for occurrences taken over by «это и следующие»', function () {
    $event = $this->postJson('/api/events', ['title' => 'Зарядка', 'starts_on' => '2026-10-20', 'start_time' => '10:30', 'end_time' => '11:00',
        'repeat' => ['unit' => 'day'], 'remind_before' => 10], $this->auth)->json();
    [$key] = remindersDueAt('2026-10-23 10:20');
    $this->reminders->claim($this->user, $key, now());

    $next = $this->patchJson("/api/events/{$event['id']}?scope=following&date=2026-10-23", ['notes' => 'Во дворе'], $this->auth)->assertOk()->json('event');

    expect($next['id'])->not->toBe($event['id'])
        ->and(remindersDueAt('2026-10-23 10:22'))->toBe([])
        ->and(remindersDueAt('2026-10-24 10:20'))->toBe(["e{$next['id']}:2026-10-24T10:20"]);
});
