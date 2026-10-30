<?php

use App\Models\PrayerLog;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// Prayers in the bot (docs/PLAN.md, D40, docs/BOT.md): a reminder when a prayer's time begins and another
// 20 minutes before its end if it is not marked, «Прочитал» under them, /namaz and the line in /today.

const PRAYER_OWNER = 111222333;

beforeEach(function () {
    // Friday 30.10.2026 in Tashkent: Asr 15:39–17:25, Isha from 18:41, the middle of the night 23:27.
    $this->travelTo(Carbon::parse('2026-10-30 15:00', 'Asia/Tashkent'));
    config([
        'telegram.bot_token' => 'test-token',
        'telegram.webhook_secret' => 'hook-secret',
        'telegram.allowed_ids' => [(string) PRAYER_OWNER],
        'telegram.webapp_url' => 'https://kompas.example.uz',
        'telegram.rich_messages' => true,
    ]);
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 70]]));

    $this->user = User::factory()->create(['telegram_id' => PRAYER_OWNER, 'locale' => 'ru', 'settings' => ['prayer_on' => true]]);
    $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 3_500_000]);
});

function prayerNotifyAt(string $moment): void
{
    test()->travelTo(Carbon::parse($moment, 'Asia/Tashkent'));
    test()->artisan('bot:notify')->assertSuccessful();
}

/** @return list<array<string, mixed>> */
function prayerCalls(string $method): array
{
    return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), "/{$method}"))
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

/** @return list<string> */
function prayerSent(): array
{
    return array_map(fn (array $m) => $m['rich_message']['html'], prayerCalls('sendRichMessage'));
}

/** @return list<string> callback data, or the app address of a web app button */
function prayerButtons(array $call): array
{
    return collect($call['reply_markup']['inline_keyboard'] ?? [])->flatten(1)
        ->map(fn ($b) => $b['callback_data'] ?? $b['web_app']['url'])->all();
}

function prayerTap(string $data): void
{
    test()->postJson('/telegram/webhook', ['update_id' => 2, 'callback_query' => [
        'id' => 'cb', 'data' => $data,
        'from' => ['id' => PRAYER_OWNER, 'first_name' => 'Elyor'],
        'message' => ['message_id' => 80, 'chat' => ['id' => PRAYER_OWNER, 'type' => 'private']],
    ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
}

function prayerSay(string $text): void
{
    test()->postJson('/telegram/webhook', ['update_id' => 1, 'message' => [
        'message_id' => 10, 'text' => $text,
        'from' => ['id' => PRAYER_OWNER, 'first_name' => 'Elyor'],
        'chat' => ['id' => PRAYER_OWNER, 'type' => 'private'],
    ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
}

function prayerToast(): ?string
{
    return collect(prayerCalls('answerCallbackQuery'))->last()['text'] ?? null;
}

it('reminds when the time begins and 20 minutes before its end, once each', function () {
    prayerNotifyAt('2026-10-30 15:38');
    expect(prayerSent())->toBe([]);

    prayerNotifyAt('2026-10-30 15:39');
    prayerNotifyAt('2026-10-30 15:40');
    expect(prayerSent())->toHaveCount(1)
        ->and(prayerSent()[0])->toContain('<h3>🕌 Аср</h3>')->toContain('Время намаза: 15:39–17:25')
        ->and(prayerButtons(prayerCalls('sendRichMessage')[0]))->toBe(['prayer:r:2026-10-30:asr', 'https://kompas.example.uz/tasks/prayer']);

    prayerNotifyAt('2026-10-30 17:05');
    expect(prayerSent())->toHaveCount(2)
        ->and(prayerSent()[1])->toContain('<h3>⏳ Аср: осталось 20 мин</h3>')->toContain('Время до 17:25, а намаз ещё не отмечен.');

    // Isha's last call is before the middle of the night, in quiet hours too: the user asked for it.
    prayerNotifyAt('2026-10-30 17:25');
    prayerNotifyAt('2026-10-30 18:41');
    prayerNotifyAt('2026-10-30 23:07');
    expect(array_slice(prayerSent(), 2))->sequence(
        fn ($html) => $html->toContain('<h3>🕌 Шом</h3>'),
        fn ($html) => $html->toContain('<h3>🕌 Хуфтон</h3>')->toContain('Время намаза: с 18:41, желательно до 23:27'),
        fn ($html) => $html->toContain('<h3>⏳ Хуфтон: до середины ночи 20 мин</h3>')->toContain('Желательно прочитать до 23:27'),
    );
});

it('says nothing before the end of a prayer already marked, nor of muted prayers, nor when switched off', function () {
    PrayerLog::create(['user_id' => $this->user->id, 'date' => '2026-10-30', 'prayer' => 'asr', 'status' => 'on_time']);
    prayerNotifyAt('2026-10-30 17:05');
    expect(prayerSent())->toBe([]);

    $this->user->update(['settings' => ['prayer_on' => true, 'prayer_remind' => 'start', 'prayer_mute' => ['maghrib']]]);
    prayerNotifyAt('2026-10-30 17:25');
    prayerNotifyAt('2026-10-30 18:21');
    prayerNotifyAt('2026-10-30 18:41');
    expect(prayerSent())->toHaveCount(1)->and(prayerSent()[0])->toContain('Хуфтон');

    $this->user->update(['settings' => ['prayer_on' => false]]);
    prayerNotifyAt('2026-10-31 05:33');
    expect(prayerSent())->toHaveCount(1);
});

it('marks the prayer from the reminder and turns it into «прочитан»', function () {
    $this->travelTo(Carbon::parse('2026-10-30 15:52', 'Asia/Tashkent'));
    prayerTap('prayer:r:2026-10-30:asr');

    expect(PrayerLog::where('date', '2026-10-30')->pluck('status', 'prayer')->all())->toBe(['asr' => 'on_time'])
        ->and(prayerToast())->toBe('Аср отмечен')
        ->and(prayerCalls('editMessageText')[0]['rich_message']['html'])->toContain('<h3>✅ Аср прочитан</h3>')->toContain('Отмечено в 15:52')
        ->and(prayerButtons(prayerCalls('editMessageText')[0]))->toBe(['https://kompas.example.uz/tasks/prayer']);

    prayerTap('prayer:r:2026-10-30:asr');
    expect(prayerToast())->toBe('Аср уже отмечен');

    // After its time: made up. Before its time: nothing.
    prayerTap('prayer:r:2026-10-30:dhuhr');
    prayerTap('prayer:r:2026-10-30:maghrib');
    expect(PrayerLog::where('date', '2026-10-30')->orderBy('id')->pluck('status', 'prayer')->all())->toBe(['asr' => 'on_time', 'dhuhr' => 'qaza'])
        ->and(collect(prayerCalls('answerCallbackQuery'))->pluck('text')->slice(-2)->values()->all())->toBe(['Пешин записан как каза', 'Время этого намаза ещё не наступило']);
});

it('shows the day in /namaz with buttons for what can be marked, and one line in /today', function () {
    PrayerLog::create(['user_id' => $this->user->id, 'date' => '2026-10-29', 'prayer' => 'isha', 'status' => 'on_time']);
    PrayerLog::create(['user_id' => $this->user->id, 'date' => '2026-10-30', 'prayer' => 'fajr', 'status' => 'on_time']);
    $this->travelTo(Carbon::parse('2026-10-30 16:00', 'Asia/Tashkent'));

    prayerSay('/namaz');
    $card = prayerCalls('sendRichMessage')[0];
    expect($card['rich_message']['html'])->toContain('<h3>🕌 Намаз · Ташкент</h3>')
        ->toContain('<li>✅ Бомдод 05:32</li>')
        ->toContain('<li>⚠️ Пешин 12:07 — пропущен</li>')
        ->toContain('<li>⏳ Аср 15:39 — сейчас, до 17:25</li>')
        ->toContain('<li>▫️ Хуфтон 18:41</li>')
        ->toContain('Пропущено за 30 дней: 1')
        ->and(prayerButtons($card))->toBe(['prayer:d:2026-10-30:dhuhr', 'prayer:d:2026-10-30:asr', 'https://kompas.example.uz/tasks/prayer']);

    prayerTap('prayer:d:2026-10-30:asr');
    expect(prayerCalls('editMessageText')[0]['rich_message']['html'])->toContain('<li>✅ Аср 15:39</li>');

    prayerSay('/today');
    $today = collect(prayerCalls('sendRichMessage'))->last();
    expect($today['rich_message']['html'])->toContain('<h4>🕌 Намаз</h4>')->toContain('✅ Бомдод · ⚠️ Пешин · ✅ Аср · Шом 17:25 · Хуфтон 18:41');

    $this->travelTo(Carbon::parse('2026-10-30 17:40', 'Asia/Tashkent'));
    prayerSay('/today');
    $today = collect(prayerCalls('sendRichMessage'))->last();
    expect($today['rich_message']['html'])->toContain('⏳ Шом сейчас, до 18:41')
        ->and(prayerButtons($today))->toContain('prayer:t:2026-10-30:maghrib');
    prayerTap('prayer:t:2026-10-30:maghrib');
    expect(collect(prayerCalls('editMessageText'))->last()['rich_message']['html'])->toContain('✅ Шом');
});

it('offers yesterday\'s Isha after midnight, while its time lasts until Fajr', function () {
    foreach (['fajr', 'dhuhr', 'asr', 'maghrib'] as $name) {
        PrayerLog::create(['user_id' => $this->user->id, 'date' => '2026-10-30', 'prayer' => $name, 'status' => 'on_time']);
    }
    $this->travelTo(Carbon::parse('2026-10-31 00:20', 'Asia/Tashkent'));

    prayerSay('/namaz');
    $card = prayerCalls('sendRichMessage')[0];
    expect($card['rich_message']['html'])->toContain('<li>⏳ Хуфтон 18:41 — сейчас, до 05:33</li><li>▫️ Бомдод 05:33</li>')
        ->and(prayerButtons($card))->toBe(['prayer:d:2026-10-30:isha', 'https://kompas.example.uz/tasks/prayer']);

    prayerSay('/today');
    $today = collect(prayerCalls('sendRichMessage'))->last();
    expect($today['rich_message']['html'])->toContain('⏳ Хуфтон сейчас, до 05:33 · Бомдод 05:33')
        ->and(prayerButtons($today))->toContain('prayer:t:2026-10-30:isha');

    prayerTap('prayer:d:2026-10-30:isha');
    expect(PrayerLog::where('date', '2026-10-30')->where('prayer', 'isha')->value('status'))->toBe('on_time')
        ->and(collect(prayerCalls('editMessageText'))->last()['rich_message']['html'])->not->toContain('Хуфтон 18:41 — сейчас');
});

it('tells how to turn prayers on, and marks nothing, while they are off', function () {
    $this->user->update(['settings' => ['prayer_on' => false]]);

    prayerSay('/namaz');
    $card = prayerCalls('sendRichMessage')[0];
    expect($card['rich_message']['html'])->toContain('Намаз выключен')
        ->and(prayerButtons($card))->toBe(['https://kompas.example.uz/tasks/prayer']);

    prayerTap('prayer:d:2026-10-30:dhuhr');
    expect(prayerToast())->toContain('Намаз выключен')
        ->and(PrayerLog::count())->toBe(0);
});

it('counts the minutes left in a late last call, and keeps the time a prayer was marked', function () {
    // The first run of the evening, six minutes before the end of Asr.
    prayerNotifyAt('2026-10-30 17:19');
    expect(prayerSent())->toHaveCount(1)
        ->and(prayerSent()[0])->toContain('<h3>⏳ Аср: осталось 6 мин</h3>');

    $this->travelTo(Carbon::parse('2026-10-30 15:45', 'Asia/Tashkent'));
    PrayerLog::create(['user_id' => $this->user->id, 'date' => '2026-10-30', 'prayer' => 'asr', 'status' => 'on_time']);
    $this->travelTo(Carbon::parse('2026-10-30 17:00', 'Asia/Tashkent'));
    prayerTap('prayer:r:2026-10-30:asr');
    expect(prayerToast())->toBe('Аср уже отмечен')
        ->and(collect(prayerCalls('editMessageText'))->last()['rich_message']['html'])->toContain('Отмечено в 15:45');
});
