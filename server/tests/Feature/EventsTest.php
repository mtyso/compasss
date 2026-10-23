<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;

// The calendar's own events: fields checked on the whole event, repeat rules fitted to the start, and
// changes or deletions of one occurrence, the following ones or the whole series.

beforeEach(function () {
    // Friday
    $this->travelTo(Carbon::parse('2026-10-23 10:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
});

function newEvent(array $data = []): array
{
    return test()->postJson('/api/events', ['title' => 'Встреча', 'starts_on' => '2026-10-23', ...$data], test()->auth)->assertCreated()->json();
}

/** @return list<string> the days the event shows on in the calendar */
function eventDays(int $id, string $from, string $to): array
{
    return collect(test()->getJson("/api/calendar?from={$from}&to={$to}", test()->auth)->assertOk()->json('items'))
        ->where('type', 'event')->where('event_id', $id)->pluck('date')->values()->all();
}

function dailyEvent(array $data = []): array
{
    return newEvent(['title' => 'Зарядка', 'starts_on' => '2026-10-19', 'start_time' => '07:00', 'end_time' => '07:30', 'repeat' => ['unit' => 'day'], ...$data]);
}

it('creates, shows, edits, deletes and restores an event', function () {
    $event = newEvent(['title' => '  Стоматолог  ', 'start_time' => '14:00', 'end_time' => '15:00', 'remind_before' => 60, 'notes' => 'Взять полис']);
    expect($event)->toBe([
        'id' => $event['id'], 'series_id' => null, 'series_date' => null, 'title' => 'Стоматолог', 'notes' => 'Взять полис',
        'starts_on' => '2026-10-23', 'ends_on' => '2026-10-23', 'start_time' => '14:00', 'end_time' => '15:00', 'all_day' => false,
        'color' => 'indigo', 'repeat' => null, 'repeat_until' => null, 'skip_dates' => [], 'remind_before' => 60,
    ]);
    $this->getJson("/api/events/{$event['id']}", $this->auth)->assertOk()->assertJsonPath('title', 'Стоматолог');

    $this->patchJson("/api/events/{$event['id']}", ['title' => 'Зубной', 'color' => 'teal', 'start_time' => null, 'end_time' => null], $this->auth)->assertOk()
        ->assertJsonPath('event.title', 'Зубной')->assertJsonPath('event.color', 'teal')->assertJsonPath('event.all_day', true);

    $this->deleteJson("/api/events/{$event['id']}", [], $this->auth)->assertOk()->assertJsonPath('event', null);
    $this->getJson("/api/events/{$event['id']}", $this->auth)->assertNotFound();
    $this->postJson("/api/events/{$event['id']}/restore", [], $this->auth)->assertOk()->assertJsonPath('title', 'Зубной');
    expect(eventDays($event['id'], '2026-10-19', '2026-10-25'))->toBe(['2026-10-23']);

    $this->postJson('/api/events', ['starts_on' => '23.10.2026', 'color' => 'neon', 'start_time' => '25:00', 'end_time' => '10:00', 'remind_before' => 50000], $this->auth)
        ->assertJsonValidationErrors(['title', 'starts_on', 'color', 'start_time', 'remind_before']);
});

it('checks the event as a whole, with what is stored', function () {
    $this->postJson('/api/events', ['title' => 'X', 'starts_on' => '2026-10-23', 'start_time' => '10:00'], $this->auth)
        ->assertJsonValidationErrors(['end_time' => 'Укажи и начало, и конец.']);
    $this->postJson('/api/events', ['title' => 'X', 'starts_on' => '2026-10-23', 'start_time' => '10:00', 'end_time' => '10:00'], $this->auth)
        ->assertJsonValidationErrors(['end_time' => 'Конец должен быть позже начала.']);
    $this->postJson('/api/events', ['title' => 'X', 'starts_on' => '2026-10-01', 'ends_on' => '2026-11-02'], $this->auth)
        ->assertJsonValidationErrors(['ends_on' => 'Событие может длиться не больше 31 дня.']);
    newEvent(['starts_on' => '2026-10-01', 'ends_on' => '2026-11-01']);
    $this->postJson('/api/events', ['title' => 'X', 'starts_on' => '2026-10-23', 'repeat' => ['unit' => 'day'], 'repeat_until' => '2026-10-22'], $this->auth)
        ->assertJsonValidationErrors('repeat_until');
    $this->postJson('/api/events', ['title' => 'X', 'starts_on' => '2026-10-23', 'start_time' => '10:00'], [...$this->auth, 'Accept-Language' => 'uz'])
        ->assertJsonValidationErrors(['end_time' => 'Boshlanish va tugash vaqtini kiriting.']);

    $event = newEvent(['start_time' => '10:00', 'end_time' => '11:00']);
    // A start sent alone is held against the stored end, an end date against the stored start.
    $this->patchJson("/api/events/{$event['id']}", ['start_time' => '12:00'], $this->auth)->assertJsonValidationErrors('end_time');
    $this->patchJson("/api/events/{$event['id']}", ['ends_on' => '2026-10-22'], $this->auth)->assertJsonValidationErrors('ends_on');
    $this->patchJson("/api/events/{$event['id']}", ['end_time' => null], $this->auth)->assertJsonValidationErrors('end_time');
    // 23:00 to 01:00 is fine when it ends the next day.
    $this->patchJson("/api/events/{$event['id']}", ['start_time' => '23:00', 'end_time' => '01:00', 'ends_on' => '2026-10-24'], $this->auth)->assertOk();
    $this->patchJson("/api/events/{$event['id']}?scope=nope", ['title' => 'Y'], $this->auth)->assertJsonValidationErrors('scope');
    $this->patchJson("/api/events/{$event['id']}?scope=one", ['title' => 'Y'], $this->auth)->assertJsonValidationErrors('date');
    $this->patchJson("/api/events/{$event['id']}?scope=all&date=2026-10-24", ['title' => 'Y'], $this->auth)
        ->assertJsonValidationErrors(['date' => 'В этот день события нет.']);
});

it('keeps the length when only the start moves', function () {
    $trip = newEvent(['title' => 'Самарканд', 'starts_on' => '2026-10-23', 'ends_on' => '2026-10-25']);
    $this->patchJson("/api/events/{$trip['id']}", ['starts_on' => '2026-10-30'], $this->auth)
        ->assertJsonPath('event.starts_on', '2026-10-30')->assertJsonPath('event.ends_on', '2026-11-01');

    $night = newEvent(['start_time' => '23:00', 'end_time' => '01:00', 'ends_on' => '2026-10-24']);
    $this->patchJson("/api/events/{$night['id']}", ['starts_on' => '2026-10-27'], $this->auth)->assertJsonPath('event.ends_on', '2026-10-28');
});

it('fits the repeat rule to the start', function () {
    // Mon and Fri created on a Wednesday: it starts that Friday.
    $gym = newEvent(['starts_on' => '2026-10-28', 'start_time' => '19:00', 'end_time' => '20:00', 'repeat' => ['unit' => 'week', 'days' => [5, 1]]]);
    expect($gym)->toMatchArray(['starts_on' => '2026-10-30', 'ends_on' => '2026-10-30', 'repeat' => ['unit' => 'week', 'every' => 1, 'days' => [1, 5]]]);

    // Monthly on the 15th: the occurrence of 15 November moved to the 17th moves the series to the 17th.
    $rent = newEvent(['starts_on' => '2026-10-15', 'repeat' => ['unit' => 'month']]);
    expect($rent['repeat'])->toBe(['unit' => 'month', 'every' => 1, 'day' => 15]);
    $this->patchJson("/api/events/{$rent['id']}?scope=all&date=2026-11-15", ['starts_on' => '2026-11-17', 'ends_on' => '2026-11-17'], $this->auth)->assertOk()
        ->assertJsonPath('event.starts_on', '2026-10-17')->assertJsonPath('event.repeat.day', 17);
    expect(eventDays($rent['id'], '2026-12-01', '2026-12-31'))->toBe(['2026-12-17']);

    // Weekly on Mondays moved to a Tuesday repeats on Tuesdays.
    $mondays = newEvent(['starts_on' => '2026-10-19', 'repeat' => ['unit' => 'week', 'days' => [1]]]);
    $this->patchJson("/api/events/{$mondays['id']}", ['starts_on' => '2026-10-20'], $this->auth)
        ->assertJsonPath('event.repeat.days', [2])->assertJsonPath('event.starts_on', '2026-10-20');

    // Mon, Wed, Fri from Wednesday 28 October on, moved to the Thursday: Tue, Thu, Sat from then.
    $run = newEvent(['starts_on' => '2026-10-19', 'repeat' => ['unit' => 'week', 'days' => [1, 3, 5]]]);
    $next = $this->patchJson("/api/events/{$run['id']}?scope=following&date=2026-10-28", ['starts_on' => '2026-10-29'], $this->auth)->assertOk()->json('event');
    expect($next)->toMatchArray(['starts_on' => '2026-10-29', 'repeat' => ['unit' => 'week', 'every' => 1, 'days' => [2, 4, 6]]])
        ->and(Event::find($run['id'])->toApi())->toMatchArray(['repeat_until' => '2026-10-27', 'repeat' => ['unit' => 'week', 'every' => 1, 'days' => [1, 3, 5]]])
        ->and(eventDays($run['id'], '2026-10-19', '2026-11-08'))->toBe(['2026-10-19', '2026-10-21', '2026-10-23', '2026-10-26'])
        ->and(eventDays($next['id'], '2026-10-19', '2026-11-08'))->toBe(['2026-10-29', '2026-10-31', '2026-11-03', '2026-11-05', '2026-11-07']);
});

it('changes one occurrence, the following ones or all of them', function () {
    $series = dailyEvent();

    // Only this one: an exception of its own, skipped in the series.
    $one = $this->patchJson("/api/events/{$series['id']}?scope=one&date=2026-10-22", ['title' => 'Зарядка с тренером', 'start_time' => '08:00', 'end_time' => '09:00', 'repeat' => ['unit' => 'week']], $this->auth)
        ->assertOk()->json('event');
    expect($one)->toMatchArray([
        'series_id' => $series['id'], 'series_date' => '2026-10-22', 'title' => 'Зарядка с тренером', 'starts_on' => '2026-10-22',
        'start_time' => '08:00', 'repeat' => null, 'skip_dates' => [],
    ])->and(Event::find($series['id'])->skip_dates)->toBe(['2026-10-22']);
    $feed = $this->getJson('/api/calendar?from=2026-10-19&to=2026-10-25', $this->auth)->json();
    expect(collect($feed['items'])->where('type', 'event')->pluck('key')->all())->toBe([
        "e{$series['id']}:2026-10-19", "e{$series['id']}:2026-10-20", "e{$series['id']}:2026-10-21", "e{$one['id']}:2026-10-22",
        "e{$series['id']}:2026-10-23", "e{$series['id']}:2026-10-24", "e{$series['id']}:2026-10-25",
    ])->and(collect($feed['items'])->firstWhere('event_id', $one['id']))->toMatchArray(['series_id' => $series['id'], 'repeating' => true, 'start' => '08:00'])
        ->and(array_keys($feed['series']))->toEqualCanonicalizing([$series['id'], $one['id']]);
    // An exception edited is only ever itself.
    $this->patchJson("/api/events/{$one['id']}?scope=following&date=2026-10-22", ['title' => 'С тренером'], $this->auth)
        ->assertJsonPath('event.id', $one['id'])->assertJsonPath('event.title', 'С тренером');

    // All of them, from the occurrence of the 23rd moved half an hour and a day: the series moves a day, the
    // skipped day and the exception's place in the series with it.
    $this->patchJson("/api/events/{$series['id']}?scope=all&date=2026-10-23", ['starts_on' => '2026-10-24', 'start_time' => '07:30', 'end_time' => '08:00'], $this->auth)
        ->assertOk()->assertJsonPath('event.starts_on', '2026-10-20')->assertJsonPath('event.skip_dates', ['2026-10-23']);
    expect(Event::find($one['id'])->toApi())->toMatchArray(['series_date' => '2026-10-23', 'starts_on' => '2026-10-22']);

    // The following ones: the series ends the day before, a new one takes the exceptions after.
    $later = $this->patchJson("/api/events/{$series['id']}?scope=following&date=2026-10-22", ['title' => 'Бег'], $this->auth)->assertOk()->json('event');
    expect($later)->toMatchArray(['title' => 'Бег', 'starts_on' => '2026-10-22', 'start_time' => '07:30', 'skip_dates' => ['2026-10-23'], 'repeat_until' => null])
        ->and(Event::find($series['id'])->toApi())->toMatchArray(['repeat_until' => '2026-10-21', 'skip_dates' => []])
        ->and(Event::find($one['id'])->series_id)->toBe($later['id'])
        ->and(eventDays($series['id'], '2026-10-19', '2026-10-25'))->toBe(['2026-10-20', '2026-10-21'])
        ->and(eventDays($later['id'], '2026-10-19', '2026-10-25'))->toBe(['2026-10-22', '2026-10-24', '2026-10-25']);

    // From the first occurrence the following ones are all of them.
    $this->patchJson("/api/events/{$later['id']}?scope=following&date=2026-10-22", ['title' => 'Утренний бег'], $this->auth)
        ->assertJsonPath('event.id', $later['id']);
});

it('deletes one occurrence, the following ones or the whole series, and brings them back', function () {
    $series = dailyEvent(['repeat_until' => '2026-10-31']);
    $exception = $this->patchJson("/api/events/{$series['id']}?scope=one&date=2026-10-27", ['title' => 'Зарядка в парке'], $this->auth)->json('event');
    $lone = $this->patchJson("/api/events/{$series['id']}?scope=one&date=2026-10-29", ['title' => 'Без меня'], $this->auth)->json('event');

    // One: skipped in the series, which comes back as it is now.
    $this->deleteJson("/api/events/{$series['id']}?scope=one&date=2026-10-20", [], $this->auth)->assertOk()
        ->assertJsonPath('event.skip_dates', ['2026-10-20', '2026-10-27', '2026-10-29']);
    // An exception deleted is gone on its own; the series still skips its day.
    $this->deleteJson("/api/events/{$lone['id']}?scope=all&date=2026-10-29", [], $this->auth)->assertOk()->assertJsonPath('event', null);
    expect(eventDays($series['id'], '2026-10-19', '2026-10-25'))->toBe(['2026-10-19', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25'])
        ->and(eventDays($lone['id'], '2026-10-26', '2026-11-01'))->toBe([]);

    // All: the series and its exceptions go together and come back together; the one deleted before stays deleted.
    $this->travel(5)->minutes();
    $this->deleteJson("/api/events/{$series['id']}?scope=all&date=2026-10-23", [], $this->auth)->assertOk()->assertJsonPath('event', null);
    expect(Event::find($exception['id']))->toBeNull();
    $this->postJson("/api/events/{$series['id']}/restore", [], $this->auth)->assertOk()->assertJsonPath('id', $series['id']);
    expect(Event::find($exception['id']))->not->toBeNull()->and(Event::find($lone['id']))->toBeNull();

    // Following: the series ends the day before, exceptions after it are deleted.
    $this->deleteJson("/api/events/{$series['id']}?scope=following&date=2026-10-26", [], $this->auth)->assertOk()
        ->assertJsonPath('event.repeat_until', '2026-10-25');
    expect(Event::find($exception['id']))->toBeNull()
        ->and(eventDays($series['id'], '2026-10-19', '2026-11-01'))->toBe(['2026-10-19', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25']);
    // Undone by putting the old end back.
    $this->patchJson("/api/events/{$series['id']}", ['repeat_until' => '2026-10-31'], $this->auth)->assertOk();
    expect(eventDays($series['id'], '2026-10-26', '2026-11-01'))->toBe(['2026-10-26', '2026-10-28', '2026-10-30', '2026-10-31']);

    // Following from the first occurrence is the whole series.
    $this->deleteJson("/api/events/{$series['id']}?scope=following&date=2026-10-19", [], $this->auth)->assertOk()->assertJsonPath('event', null);
});

it('brings back a skipped day', function () {
    $series = dailyEvent();
    $this->deleteJson("/api/events/{$series['id']}?scope=one&date=2026-10-21", [], $this->auth)->assertOk();
    $this->deleteJson("/api/events/{$series['id']}?scope=one&date=2026-10-22", [], $this->auth)->assertOk();

    $this->patchJson("/api/events/{$series['id']}", ['unskip' => '2026-10-21'], $this->auth)->assertOk()->assertJsonPath('event.skip_dates', ['2026-10-22']);
    expect(eventDays($series['id'], '2026-10-20', '2026-10-23'))->toBe(['2026-10-20', '2026-10-21', '2026-10-23']);

    $this->patchJson("/api/events/{$series['id']}", ['unskip' => '2026-10-24'], $this->auth)->assertJsonValidationErrors('unskip');
    $this->patchJson("/api/events/{$series['id']}?scope=one&date=2026-10-23", ['unskip' => '2026-10-22'], $this->auth)->assertJsonValidationErrors('unskip');
    // Stopping the repeat forgets the skipped days and the end.
    $this->patchJson("/api/events/{$series['id']}", ['repeat' => null], $this->auth)->assertOk()
        ->assertJsonPath('event.skip_dates', [])->assertJsonPath('event.repeat_until', null);
});

it('keeps events to their owner', function () {
    $other = User::factory()->create();
    $foreign = $other->events()->create(['title' => 'Чужое', 'starts_on' => '2026-10-23', 'ends_on' => '2026-10-23']);

    $this->getJson("/api/events/{$foreign->id}", $this->auth)->assertNotFound();
    $this->patchJson("/api/events/{$foreign->id}", ['title' => 'Моё'], $this->auth)->assertNotFound();
    $this->deleteJson("/api/events/{$foreign->id}", [], $this->auth)->assertNotFound();
    $foreign->delete();
    $this->postJson("/api/events/{$foreign->id}/restore", [], $this->auth)->assertNotFound();
    $foreign->restore();
    expect(eventDays($foreign->id, '2026-10-19', '2026-10-25'))->toBe([]);
});
