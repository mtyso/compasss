<?php

use App\Models\PrayerLog;
use App\Models\User;
use App\Prayer\PrayerTimes;
use Illuminate\Support\Carbon;

// The five daily prayers (docs/PLAN.md, D40): today's times and marks, qaza, the missed ones to make up and
// the streak of days with all five prayed in their time.

beforeEach(function () {
    // Friday, between Asr (15:39) and Maghrib (17:25) in Tashkent
    $this->travelTo(Carbon::parse('2026-10-30 16:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
    $this->patchJson('/api/me', ['settings' => ['prayer_on' => true]], $this->auth)->assertOk();
});

function prayerState(): array
{
    return test()->getJson('/api/prayer', test()->auth)->assertOk()->json();
}

/** @return array<string, string> state by prayer */
function prayerStates(?array $state = null): array
{
    return collect(($state ?? prayerState())['prayers'])->pluck('state', 'name')->all();
}

/** All five prayed in their time on each date. */
function prayedDays(string ...$dates): void
{
    foreach ($dates as $date) {
        foreach (PrayerTimes::NAMES as $name) {
            PrayerLog::create(['user_id' => test()->user->id, 'date' => $date, 'prayer' => $name, 'status' => 'on_time']);
        }
    }
}

it('is off until turned on, and gives the day\'s times, states and the next prayer', function () {
    $other = User::factory()->create(['telegram_id' => 111222334]);
    config(['telegram.allowed_ids' => ['111222333', '111222334']]);
    app('auth')->forgetGuards();
    $this->getJson('/api/prayer', ['Authorization' => 'Bearer '.$other->createToken('t')->plainTextToken])->assertOk()->assertJsonPath('on', false);
    app('auth')->forgetGuards();

    $state = prayerState();
    expect($state['on'])->toBeTrue()
        ->and($state['city'])->toMatchArray(['slug' => 'tashkent', 'name' => 'Ташкент'])
        ->and($state['remind'])->toBe('start_end')
        ->and($state['times'])->toMatchArray(['fajr' => '05:32', 'sunrise' => '06:52', 'dhuhr' => '12:07', 'asr' => '15:39', 'maghrib' => '17:25', 'isha' => '18:41', 'midnight' => '23:27'])
        // Nothing marked yet: the morning is not judged.
        ->and(prayerStates($state))->toBe(['fajr' => 'skipped', 'dhuhr' => 'skipped', 'asr' => 'now', 'maghrib' => 'later', 'isha' => 'later'])
        ->and($state['prayers'][2])->toMatchArray(['name' => 'asr', 'start' => '15:39', 'end' => '17:25'])
        ->and($state['moment']['current'])->toBe(['name' => 'asr', 'date' => '2026-10-30', 'end' => '17:25', 'marked' => false])
        ->and($state['moment']['next'])->toBe(['name' => 'maghrib', 'date' => '2026-10-30', 'start' => '17:25', 'in_minutes' => 85])
        ->and($state['week'])->toHaveCount(7)
        ->and($state['week'][6]['date'])->toBe('2026-10-30')
        ->and($state['streak']['current'])->toBe(0)
        ->and($state['missed'])->toBe([]);
});

it('keeps yesterday\'s Isha on until Fajr', function () {
    $this->travelTo(Carbon::parse('2026-10-31 01:00', 'Asia/Tashkent'));

    expect(prayerState()['moment'])->toMatchArray([
        'current' => ['name' => 'isha', 'date' => '2026-10-30', 'end' => '05:33', 'marked' => false],
        'next' => ['name' => 'fajr', 'date' => '2026-10-31', 'start' => '05:33', 'in_minutes' => 273],
    ]);
});

it('marks a prayer in its time, a past one as qaza unless told, and refuses one not begun', function () {
    expect(prayerStates($this->putJson('/api/prayer/2026-10-30/asr', [], $this->auth)->assertOk()->json())['asr'])->toBe('done')
        ->and(prayerStates($this->putJson('/api/prayer/2026-10-30/dhuhr', [], $this->auth)->assertOk()->json())['dhuhr'])->toBe('qaza')
        ->and(prayerStates($this->putJson('/api/prayer/2026-10-30/fajr', ['status' => 'on_time'], $this->auth)->assertOk()->json())['fajr'])->toBe('done')
        ->and(PrayerLog::where('date', '2026-10-30')->orderBy('id')->pluck('status', 'prayer')->all())->toBe(['asr' => 'on_time', 'dhuhr' => 'qaza', 'fajr' => 'on_time']);

    $this->putJson('/api/prayer/2026-10-30/maghrib', [], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('prayer');
    $this->putJson('/api/prayer/2026-10-31/fajr', [], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('date');
    $this->putJson('/api/prayer/2026-09-29/fajr', [], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('date');
    $this->putJson('/api/prayer/2026-10-30/asr', ['status' => 'late'], $this->auth)->assertUnprocessable();
    $this->putJson('/api/prayer/2026-10-30/witr', [], $this->auth)->assertClientError();

    expect(prayerStates($this->deleteJson('/api/prayer/2026-10-30/asr', [], $this->auth)->assertOk()->json())['asr'])->toBe('now');
});

it('lists the missed prayers after the first day and counts the days with all five in time', function () {
    PrayerLog::create(['user_id' => $this->user->id, 'date' => '2026-10-27', 'prayer' => 'isha', 'status' => 'on_time']);
    prayedDays('2026-10-28', '2026-10-29');
    PrayerLog::where('date', '2026-10-29')->where('prayer', 'isha')->delete();
    $this->putJson('/api/prayer/2026-10-30/fajr', ['status' => 'on_time'], $this->auth)->assertOk();

    // The 27th is the first day: its morning is not judged. The 29th lost its Isha, today Dhuhr is over.
    $state = prayerState();
    expect($state['missed'])->toBe([['date' => '2026-10-30', 'name' => 'dhuhr'], ['date' => '2026-10-29', 'name' => 'isha']])
        ->and($state['streak']['current'])->toBe(0)
        ->and($state['streak']['best'])->toBe(1)
        ->and(collect($state['week'][3]['prayers'])->pluck('state')->all())->toBe(['skipped', 'skipped', 'skipped', 'skipped', 'done']);

    // Making it up takes it off the list, but a qaza does not mend the streak.
    $this->putJson('/api/prayer/2026-10-29/isha', [], $this->auth)->assertOk();
    $this->putJson('/api/prayer/2026-10-30/dhuhr', ['status' => 'on_time'], $this->auth)->assertOk();
    $state = prayerState();
    expect($state['missed'])->toBe([])
        ->and($state['streak']['current'])->toBe(0);

    // A habit pause counts neither way: the 28th carries on through it, and today is still open.
    $this->postJson('/api/habit-pauses', ['starts_on' => '2026-10-29', 'ends_on' => '2026-10-29'], $this->auth)->assertCreated();
    expect(prayerState()['streak'])->toMatchArray(['current' => 1, 'best' => 1, 'next' => 7]);
});

it('loses today as soon as a prayer is past its time without being prayed in it', function () {
    prayedDays('2026-10-28', '2026-10-29');
    $this->putJson('/api/prayer/2026-10-30/fajr', ['status' => 'on_time'], $this->auth)->assertOk();
    expect(prayerState()['streak']['current'])->toBe(0);

    $this->putJson('/api/prayer/2026-10-30/dhuhr', ['status' => 'on_time'], $this->auth)->assertOk();
    expect(prayerState()['streak']['current'])->toBe(2);
});

it('moves the times with the city and checks the settings', function () {
    $this->patchJson('/api/me', ['settings' => ['prayer_city' => 'samarkand', 'prayer_remind' => 'end', 'prayer_mute' => ['fajr']]], $this->auth)->assertOk();
    $state = prayerState();
    expect($state['times']['asr'])->toBe('15:48')
        ->and($state['city']['name'])->toBe('Самарканд')
        ->and($state['remind'])->toBe('end')
        ->and($state['mute'])->toBe(['fajr']);

    $this->patchJson('/api/me', ['settings' => ['prayer_city' => 'moscow']], $this->auth)->assertUnprocessable();
    $this->patchJson('/api/me', ['settings' => ['prayer_remind' => 'often']], $this->auth)->assertUnprocessable();
    $this->patchJson('/api/me', ['settings' => ['prayer_mute' => ['tea']]], $this->auth)->assertUnprocessable();

    $cities = $this->getJson('/api/prayer/cities', $this->auth)->assertOk()->json();
    expect($cities)->toHaveCount(37)
        ->and($cities[0])->toMatchArray(['slug' => 'tashkent', 'name' => 'Ташкент', 'offset' => 0])
        ->and($this->getJson('/api/prayer/cities', [...$this->auth, 'Accept-Language' => 'uz'])->json('0.name'))->toBe('Toshkent');
});

it('gives the year by days and writes the streak milestones into the journal', function () {
    $days = [];
    for ($day = Carbon::parse('2026-09-29'); $day->lt(Carbon::parse('2026-10-30')); $day->addDay()) {
        $days[] = $day->toDateString();
    }
    prayedDays(...$days);

    $this->putJson('/api/prayer/2026-10-30/fajr', ['status' => 'on_time'], $this->auth)->assertOk();
    $this->putJson('/api/prayer/2026-10-30/dhuhr', ['status' => 'on_time'], $this->auth)->assertOk();
    $this->putJson('/api/prayer/2026-10-30/asr', [], $this->auth)->assertOk();

    $year = $this->getJson('/api/prayer/year', $this->auth)->assertOk()->json();
    expect($year['days'])->toHaveCount(32)
        ->and($year['days'][0])->toBe(['date' => '2026-09-29', 'value' => 5, 'qaza' => 0, 'state' => 'kept'])
        ->and(end($year['days']))->toBe(['date' => '2026-10-30', 'value' => 3, 'qaza' => 0, 'state' => 'open'])
        ->and($year['stats'])->toMatchArray(['kept_days' => 31, 'prayed' => 158, 'rate' => 1]);

    $this->getJson('/api/records', $this->auth)->assertOk()
        ->assertJsonPath('data.0.text', 'Намаз вовремя: 30 дней подряд')
        ->assertJsonPath('data.0.date', '2026-10-28')
        ->assertJsonPath('data.0.ref', null);
});
