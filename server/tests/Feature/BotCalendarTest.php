<?php

use App\Models\CalendarReminder;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// The calendar in the bot (docs/BOT.md): reminders from bot:notify at the time the user chose, quiet hours
// or not, and «План дня» in /today and the morning summary.

const CAL_OWNER = 111222333;

beforeEach(function () {
    // Friday
    $this->travelTo(Carbon::parse('2026-10-23 14:20', 'Asia/Tashkent'));
    config([
        'telegram.bot_token' => 'test-token',
        'telegram.webhook_secret' => 'hook-secret',
        'telegram.allowed_ids' => [(string) CAL_OWNER],
        'telegram.webapp_url' => 'https://kompas.example.uz',
        'telegram.rich_messages' => true,
    ]);
    // How many calls Telegram leaves unanswered before it answers again.
    $this->telegramDown = 0;
    Http::fake(fn () => $this->telegramDown-- > 0
        ? Http::response('', 502)
        : Http::response(['ok' => true, 'result' => ['message_id' => 70]]));

    $this->user = User::factory()->create(['telegram_id' => CAL_OWNER, 'locale' => 'ru']);
    $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 3_500_000]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
});

function calEvent(array $data): array
{
    return test()->postJson('/api/events', ['title' => 'Встреча с дилером', 'starts_on' => '2026-10-23', ...$data], test()->auth)->assertCreated()->json();
}

function calRoutine(array $data): array
{
    return test()->postJson('/api/routine', ['kind' => 'other', 'days' => [1, 2, 3, 4, 5, 6, 7], ...$data], test()->auth)->assertCreated()->json();
}

function calNotifyAt(string $moment): void
{
    test()->travelTo(Carbon::parse($moment, 'Asia/Tashkent'));
    test()->artisan('bot:notify')->assertSuccessful();
}

/** @return list<array<string, mixed>> the rich messages sent, in order */
function calSent(): array
{
    return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendRichMessage'))
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

function calSay(string $text): void
{
    test()->postJson('/telegram/webhook', ['update_id' => 1, 'message' => [
        'message_id' => 10, 'text' => $text,
        'from' => ['id' => CAL_OWNER, 'first_name' => 'Elyor'],
        'chat' => ['id' => CAL_OWNER, 'type' => 'private'],
    ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
}

it('reminds about an event once, how soon it starts and with its day in the calendar', function () {
    calEvent(['start_time' => '16:00', 'end_time' => '17:00', 'remind_before' => 10]);

    calNotifyAt('2026-10-23 15:49');
    expect(calSent())->toBe([]);

    calNotifyAt('2026-10-23 15:50');
    calNotifyAt('2026-10-23 15:51');

    $sent = calSent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['chat_id'])->toBe(CAL_OWNER)
        ->and($sent[0]['rich_message']['html'])->toContain('<h3>⏰ Встреча с дилером</h3>')
        ->toContain('Сегодня, 16:00–17:00 · через 10 мин')
        ->and($sent[0]['reply_markup']['inline_keyboard'][0][0]['web_app']['url'])
        ->toBe('https://kompas.example.uz/tasks/calendar?view=day&date=2026-10-23');
});

it('says tomorrow for a day-before reminder, and the whole day for an all-day event', function () {
    calEvent(['title' => 'Техосмотр', 'starts_on' => '2026-10-24', 'start_time' => '10:00', 'end_time' => '11:00', 'remind_before' => 1440]);
    calEvent(['title' => 'День рождения мамы', 'starts_on' => '2026-10-24', 'remind_before' => 0]);

    calNotifyAt('2026-10-23 10:00');
    calNotifyAt('2026-10-24 09:00');

    [$tomorrow, $allDay] = array_map(fn (array $m) => $m['rich_message']['html'], calSent());
    expect($tomorrow)->toContain('⏰ Техосмотр')->toContain('Завтра, 10:00–11:00')->not->toContain('через')
        ->and($allDay)->toContain('⏰ День рождения мамы')->toContain('Сегодня, весь день');
});

it('reminds about a routine block as it starts, in quiet hours too', function () {
    calRoutine(['title' => 'Сон', 'kind' => 'sleep', 'start_time' => '23:30', 'end_time' => '07:00', 'remind' => true]);
    calRoutine(['title' => 'Обед', 'kind' => 'meal', 'start_time' => '13:00', 'end_time' => '14:00']);

    calNotifyAt('2026-10-23 23:30');

    $sent = calSent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['rich_message']['html'])->toContain('⏰ Сон')->toContain('23:30–07:00 · распорядок дня')
        ->and($sent[0]['reply_markup']['inline_keyboard'][0][0]['web_app']['url'])->toBe('https://kompas.example.uz/tasks/calendar/routine');
});

it('tries a reminder again the next minute when Telegram did not answer', function () {
    calEvent(['start_time' => '16:00', 'end_time' => '17:00', 'remind_before' => 0]);
    $this->telegramDown = 1;

    calNotifyAt('2026-10-23 16:00');
    expect(CalendarReminder::count())->toBe(0);

    calNotifyAt('2026-10-23 16:01');
    calNotifyAt('2026-10-23 16:02');

    $sent = calSent();
    expect($sent)->toHaveCount(2)
        ->and($sent[1]['rich_message']['html'])->toContain('Сегодня, 16:00–17:00 · уже началось')
        ->and(CalendarReminder::count())->toBe(1);
});

it('shows «План дня» in /today: the routine now and next, then the events still ahead', function () {
    calRoutine(['title' => 'Работа', 'kind' => 'work', 'start_time' => '09:00', 'end_time' => '18:00', 'days' => [1, 2, 3, 4, 5]]);
    calRoutine(['title' => 'Спорт', 'kind' => 'sport', 'start_time' => '19:00', 'end_time' => '20:00']);
    calEvent(['title' => 'Завтрак с Азизом', 'start_time' => '08:00', 'end_time' => '08:30']);
    calEvent(['title' => 'День рождения мамы']);
    calEvent(['start_time' => '16:00', 'end_time' => '17:00']);
    calEvent(['title' => 'Кино', 'starts_on' => '2026-10-24', 'start_time' => '20:00', 'end_time' => '22:00']);

    calSay('/today');

    $html = calSent()[0]['rich_message']['html'];
    expect($html)->toContain('План дня')
        ->toContain('💼 Сейчас: Работа, до 18:00')
        ->toContain('🏃 Дальше: Спорт, в 19:00')
        ->toContain('<b>16:00–17:00</b> Встреча с дилером')
        ->toContain('<b>Весь день</b> День рождения мамы')
        ->not->toContain('Завтрак с Азизом')
        ->not->toContain('Кино')
        ->and(strpos($html, 'Встреча с дилером'))->toBeLessThan(strpos($html, 'День рождения мамы'));
});

it('leaves «План дня» out when nothing is on, and brings it in the morning summary', function () {
    calSay('/today');
    expect(calSent()[0]['rich_message']['html'])->not->toContain('План дня');

    calRoutine(['title' => 'Работа', 'kind' => 'work', 'start_time' => '09:00', 'end_time' => '18:00']);
    $this->travelTo(Carbon::parse('2026-10-24 08:00', 'Asia/Tashkent'));
    $this->artisan('bot:digest morning')->assertSuccessful();

    expect(collect(calSent())->last()['rich_message']['html'])->toContain('План дня')->toContain('💼 Дальше: Работа, в 09:00');
});
