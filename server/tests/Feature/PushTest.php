<?php

use App\Models\DeviceToken;
use App\Models\Insight;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// Push notifications on the phone (docs/PLAN.md, D36): Firebase HTTP v1 with a service account key, the
// phones a user signed in on, and «Куда присылать»: the bot, the phone or both, never neither.

const PUSH_OWNER = 111222333;

/** A throwaway service account key, made once per run. @return array{path: string, public: string} */
function pushKey(): array
{
    static $key = null;
    if ($key !== null && is_file($key['path'])) {
        return $key;
    }
    $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($pair, $pem);
    $path = sys_get_temp_dir().'/kompas-push-test-'.getmypid().'.json';
    file_put_contents($path, json_encode([
        'type' => 'service_account', 'project_id' => 'kompas-test', 'private_key_id' => 'key-1', 'private_key' => $pem,
        'client_email' => 'firebase-adminsdk@kompas-test.iam.gserviceaccount.com',
    ]));

    return $key = ['path' => $path, 'public' => openssl_pkey_get_details($pair)['key']];
}

beforeEach(function () {
    // Friday
    $this->travelTo(Carbon::parse('2026-10-23 14:20', 'Asia/Tashkent'));
    config([
        'telegram.bot_token' => 'test-token',
        'telegram.allowed_ids' => [(string) PUSH_OWNER],
        'telegram.webapp_url' => 'https://kompas.example.uz',
        'telegram.rich_messages' => true,
        'firebase.credentials' => pushKey()['path'],
        'firebase.project_id' => null,
    ]);
    // What Firebase answers next, one per message; then «sent». Down: no answer at all, as when unreachable.
    $this->fcmAnswers = [];
    $this->fcmDown = false;
    $this->fcmTries = 0;
    $this->fcmByToken = [];
    Http::fake(function (HttpRequest $request) {
        if (str_starts_with($request->url(), 'https://oauth2.googleapis.com/')) {
            return Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599, 'token_type' => 'Bearer']);
        }
        if (str_starts_with($request->url(), 'https://fcm.googleapis.com/')) {
            $this->fcmTries++;
            if ($answer = $this->fcmByToken[$request->data()['message']['token']] ?? null) {
                return $answer;
            }
            if ($this->fcmDown) {
                return Http::failedConnection()($request);
            }

            return array_shift($this->fcmAnswers) ?? Http::response(['name' => 'projects/kompas-test/messages/0:1']);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 70]]);
    });

    $this->user = User::factory()->create(['telegram_id' => PUSH_OWNER, 'locale' => 'ru']);
    $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 3_500_000]);
    $this->taxi = $this->user->categories()->firstWhere('system_key', 'transport_taxi');
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('Chrome, Android')->plainTextToken];
});

function pushPhone(string $token = 'phone-1'): void
{
    test()->postJson('/api/push/devices', ['token' => $token], test()->auth)->assertOk();
}

function pushVia(string $via): void
{
    test()->patchJson('/api/me', ['settings' => ['notify_via' => $via]], test()->auth)->assertOk();
}

function pushInsight(array $extra = []): Insight
{
    return test()->user->insights()->create([
        'rule' => 'category_pace', 'severity' => 'warn', 'period_key' => '2026-10', 'subject' => (string) random_int(1, 1_000_000),
        'payload' => ['category_id' => test()->taxi->id, 'percent' => 70, 'pace' => 50, 'per_day_uzs' => 10_000], 'status' => 'new', ...$extra,
    ]);
}

function pushAt(string $moment, string ...$commands): void
{
    test()->travelTo(Carbon::parse($moment, 'Asia/Tashkent'));
    foreach ($commands ?: ['bot:notify', 'push:notify'] as $command) {
        test()->artisan($command)->assertSuccessful();
    }
}

/** @return list<array<string, mixed>> the messages sent to Firebase, in order */
function pushSent(): array
{
    return Http::recorded(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://fcm.googleapis.com/'))
        ->map(fn ($pair) => $pair[0]->data()['message'])->values()->all();
}

/** @return list<array<string, mixed>> the bot's rich messages, in order */
function pushChat(): array
{
    return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendRichMessage'))
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

function pushFcmError(int $status, string $grpc, ?string $code = null, array $extra = []): mixed
{
    $details = $code ? [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $code]] : [];

    return Http::response(['error' => ['code' => $status, 'status' => $grpc, 'message' => $extra['message'] ?? 'refused', 'details' => [...$details, ...($extra['details'] ?? [])]]], $status, $extra['headers'] ?? []);
}

function pushNb(string $text): string
{
    return preg_replace('/(?<=\d) (?=\d)/u', "\u{202F}", $text);
}

it('ties a phone to its sign-in, refreshes it, and lets it go on sign-out', function () {
    $this->getJson('/api/me', $this->auth)->assertJsonPath('push', ['available' => true, 'devices' => 0]);

    $this->postJson('/api/push/devices', ['token' => 'phone-1'], $this->auth)->assertOk()->assertJsonPath('push.devices', 1);
    $device = DeviceToken::sole();
    expect($device->user_id)->toBe($this->user->id)
        ->and($device->personal_access_token_id)->toBe($this->user->tokens()->sole()->id)
        ->and($device->device_name)->toBe('Chrome, Android')
        ->and($device->platform)->toBe('android');

    // Signed in again on the same phone: one row, now tied to the new sign-in.
    $this->travel(5)->days();
    $again = ['Authorization' => 'Bearer '.$this->user->createToken('Chrome, Android')->plainTextToken];
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/push/devices', ['token' => 'phone-1'], $again)->assertOk();
    expect(DeviceToken::count())->toBe(1)
        ->and(DeviceToken::sole()->personal_access_token_id)->toBe($this->user->tokens()->latest('id')->first()->id)
        ->and(DeviceToken::sole()->last_seen_at->toDateString())->toBe('2026-10-28');

    // A new token from the same app (Firebase rotated it): it replaces the old one.
    $this->postJson('/api/push/devices', ['token' => 'phone-1b'], $again)->assertOk()->assertJsonPath('push.devices', 1);
    expect(DeviceToken::sole()->token)->toBe('phone-1b');
    $this->postJson('/api/push/devices', ['token' => 'phone-1'], $again)->assertOk();

    $this->deleteJson('/api/push/devices', ['token' => 'phone-1'], $again)->assertOk()->assertJsonPath('push.devices', 0);

    $this->postJson('/api/push/devices', ['token' => 'phone-1'], $again)->assertOk();
    $this->postJson('/api/auth/logout', [], $again)->assertNoContent();
    expect(DeviceToken::count())->toBe(0);
});

it('checks the token and the setting, and says when the server has no Firebase key', function () {
    $this->postJson('/api/push/devices', ['token' => str_repeat('x', 513)], $this->auth)->assertJsonValidationErrors('token');
    $this->postJson('/api/push/devices', ['token' => 't', 'platform' => 'ios'], $this->auth)->assertJsonValidationErrors('platform');
    $this->patchJson('/api/me', ['settings' => ['notify_via' => 'sms']], $this->auth)->assertJsonValidationErrors('settings.notify_via');

    config(['firebase.credentials' => '/nonexistent/key.json']);
    $this->getJson('/api/me', $this->auth)->assertJsonPath('push.available', false);
    $this->postJson('/api/push/test', [], $this->auth)->assertStatus(409)->assertJsonPath('message', 'На сервере ещё не настроен Firebase, поэтому уведомления пока приходят только в Telegram.');
    $this->artisan('push:notify')->assertSuccessful();
    expect(pushSent())->toBe([]);
});

it('signs in to Google with the service account and sends the advice to the phone, the token cached for the hour', function () {
    pushPhone();
    pushVia('push');
    $insight = pushInsight();

    pushAt('2026-10-23 14:21');

    $oauth = Http::recorded(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://oauth2.googleapis.com/'))->values();
    expect($oauth)->toHaveCount(1);
    $form = $oauth[0][0]->data();
    expect($form['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');
    [$header, $claims, $signature] = explode('.', $form['assertion']);
    $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
    expect(openssl_verify("{$header}.{$claims}", $decode($signature), pushKey()['public'], OPENSSL_ALGO_SHA256))->toBe(1)
        ->and(json_decode($decode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'key-1']);
    $jwt = json_decode($decode($claims), true);
    expect($jwt['iss'])->toBe('firebase-adminsdk@kompas-test.iam.gserviceaccount.com')
        ->and($jwt['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging')
        ->and($jwt['aud'])->toBe('https://oauth2.googleapis.com/token')
        ->and($jwt['exp'] - $jwt['iat'])->toBeLessThanOrEqual(3600);

    $send = Http::recorded(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://fcm.googleapis.com/'))->values()->first()[0];
    expect($send->url())->toBe('https://fcm.googleapis.com/v1/projects/kompas-test/messages:send')
        ->and($send->header('Authorization'))->toBe(['Bearer ya29.test']);
    $message = $send->data()['message'];
    expect($message['token'])->toBe('phone-1')
        ->and($message['notification'])->toBe($insight->text('ru'))
        // Strings only, and the screen a tap opens.
        ->and($message['data'])->toBe(['url' => '/advice', 'insight_id' => (string) $insight->id])
        ->and($message['android']['ttl'])->toBe('86400s')
        ->and($message['android']['notification'])->toBe(['channel_id' => 'kompas', 'tag' => "i:category_pace:{$insight->subject}"])
        ->and($insight->fresh()->sent_via)->toBe(['push'])
        ->and(pushChat())->toBe([]);

    pushInsight();
    pushAt('2026-10-23 14:22');
    expect(pushSent())->toHaveCount(2)
        ->and(Http::recorded(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://oauth2.googleapis.com/')))->toHaveCount(1);
});

it('leaves notifications to the bot until the phone is chosen, and gives them back when the phone is gone', function () {
    pushPhone();
    $first = pushInsight();
    pushAt('2026-10-23 14:21');
    expect(pushChat())->toHaveCount(1)
        ->and(pushSent())->toBe([])
        ->and($first->fresh()->sent_via)->toBe(['telegram']);

    // The phone chosen: what the bot already brought is not pushed again, the new one goes to the phone only.
    pushVia('push');
    $second = pushInsight();
    pushAt('2026-10-23 14:22');
    expect(pushChat())->toHaveCount(1)
        ->and(pushSent())->toHaveCount(1)
        ->and(pushSent()[0]['data']['insight_id'])->toBe((string) $second->id);

    // The app was removed: Firebase says the token is gone, the phone is forgotten and the bot takes over.
    $this->fcmAnswers = [pushFcmError(404, 'NOT_FOUND', 'UNREGISTERED')];
    $third = pushInsight();
    pushAt('2026-10-23 14:23');
    expect(DeviceToken::count())->toBe(0)
        ->and($third->fresh()->sent_via)->toBeNull();
    pushAt('2026-10-23 14:24');
    expect(pushChat())->toHaveCount(2)
        ->and($third->fresh()->sent_via)->toBe(['telegram']);
    $this->getJson('/api/me', $this->auth)->assertJsonPath('push.devices', 0);
});

it('sends to both when asked, each way once', function () {
    pushPhone();
    pushVia('both');
    $insight = pushInsight();
    $this->postJson('/api/events', ['title' => 'Встреча с дилером', 'starts_on' => '2026-10-23', 'start_time' => '16:00', 'end_time' => '17:00', 'remind_before' => 10], $this->auth)->assertCreated();

    pushAt('2026-10-23 14:21');
    pushAt('2026-10-23 14:22');
    expect(pushChat())->toHaveCount(1)
        ->and(pushSent())->toHaveCount(1)
        ->and($insight->fresh()->sent_via)->toBe(['telegram', 'push']);

    pushAt('2026-10-23 15:50');
    pushAt('2026-10-23 15:51');
    expect(pushChat())->toHaveCount(2)
        ->and(pushSent())->toHaveCount(2)
        ->and(pushSent()[1]['notification']['title'])->toBe('⏰ Встреча с дилером');
});

it('reminds about an event on the phone once, with its day to open, even at night', function () {
    pushPhone();
    pushVia('push');
    $this->postJson('/api/events', ['title' => 'Встреча с дилером', 'starts_on' => '2026-10-23', 'start_time' => '16:00', 'end_time' => '17:00', 'remind_before' => 10], $this->auth)->assertCreated();
    $this->postJson('/api/events', ['title' => 'Рейс в Самарканд', 'starts_on' => '2026-10-24', 'start_time' => '00:30', 'end_time' => '01:30', 'remind_before' => 60], $this->auth)->assertCreated();

    pushAt('2026-10-23 15:49');
    expect(pushSent())->toBe([]);
    pushAt('2026-10-23 15:50');
    pushAt('2026-10-23 15:51');
    expect(pushSent())->toHaveCount(1)
        ->and(pushSent()[0]['notification'])->toBe(['title' => '⏰ Встреча с дилером', 'body' => 'Сегодня, 16:00–17:00 · через 10 мин'])
        ->and(pushSent()[0]['data'])->toBe(['url' => '/tasks/calendar?view=day&date=2026-10-23'])
        ->and(pushSent()[0]['android']['ttl'])->toBe('3600s')
        ->and(pushChat())->toBe([]);

    // Quiet hours hold the advice back, not what the user asked to be reminded of.
    pushInsight();
    pushAt('2026-10-23 23:30');
    expect(pushSent())->toHaveCount(2)
        ->and(pushSent()[1]['notification']['title'])->toBe('⏰ Рейс в Самарканд');
});

it('waits while Firebase is busy, and lets the bot stand in when the key is refused', function () {
    pushPhone();
    pushVia('push');
    $this->postJson('/api/events', ['title' => 'Встреча с дилером', 'starts_on' => '2026-10-23', 'start_time' => '14:40', 'end_time' => '15:00', 'remind_before' => 10], $this->auth)->assertCreated();

    // Busy: nothing is lost, and nothing is tried before the wait Firebase asked for.
    $this->fcmAnswers = [pushFcmError(503, 'UNAVAILABLE', 'UNAVAILABLE', ['headers' => ['Retry-After' => '120']])];
    pushAt('2026-10-23 14:30');
    pushAt('2026-10-23 14:31');
    expect(pushSent())->toHaveCount(1);
    pushAt('2026-10-23 14:32');
    expect(pushSent())->toHaveCount(2)
        ->and(pushSent()[1]['notification']['title'])->toBe('⏰ Встреча с дилером')
        ->and(pushChat())->toBe([]);

    // Our own message refused: not retried every minute, the phone kept.
    $this->fcmAnswers = [pushFcmError(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', ['message' => "Invalid value at 'message.data[0].value'"])];
    $bad = pushInsight();
    pushAt('2026-10-23 14:33');
    expect($bad->fresh()->sent_via)->toBe(['push'])
        ->and(DeviceToken::count())->toBe(1);

    // The key refused (API off, key deleted): push stops for an hour and the bot brings what is waiting.
    $this->fcmAnswers = [pushFcmError(403, 'PERMISSION_DENIED')];
    $waiting = pushInsight();
    pushAt('2026-10-23 14:34');
    expect($waiting->fresh()->sent_via)->toBeNull()
        ->and(DeviceToken::count())->toBe(1);
    pushAt('2026-10-23 14:35');
    expect($waiting->fresh()->sent_via)->toBe(['telegram'])
        ->and(pushChat())->toHaveCount(1)
        ->and(pushSent())->toHaveCount(4);
    pushAt('2026-10-23 15:40');
    pushInsight();
    pushAt('2026-10-23 15:41');
    expect(pushSent())->toHaveCount(5);

    // A token Firebase calls invalid is dropped.
    $this->fcmAnswers = [pushFcmError(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', ['message' => 'The registration token is not a valid FCM registration token'])];
    pushInsight();
    pushAt('2026-10-23 15:42');
    expect(DeviceToken::count())->toBe(0);
});

it('keeps the advice quiet at night and to three a minute, as the bot does', function () {
    pushPhone();
    pushVia('push');
    pushInsight();
    pushAt('2026-10-23 22:30');
    expect(pushSent())->toBe([]);

    foreach (range(1, 4) as $i) {
        pushInsight();
    }
    pushAt('2026-10-24 08:01');
    expect(pushSent())->toHaveCount(3);
    pushAt('2026-10-24 08:02');
    expect(pushSent())->toHaveCount(5);
});

it('pushes the morning summary with the night\'s advice, the week on Sunday and the month on the 1st', function () {
    pushPhone();
    pushVia('push');
    $this->user->tasks()->create(['title' => 'Позвонить в банк', 'due_on' => '2026-10-24']);
    $advice = pushInsight();
    $quiet = pushInsight(['rule' => 'small_things', 'severity' => 'info', 'payload' => ['count' => 12, 'total_uzs' => 240_000]]);

    pushAt('2026-10-24 08:00', 'advisor:run morning', 'push:digest morning', 'bot:digest morning', 'bot:notify', 'push:notify');
    expect(pushChat())->toBe([])
        ->and(pushSent())->toHaveCount(1);
    $morning = pushSent()[0];
    expect($morning['notification']['title'])->toStartWith('Доброе утро! Сегодня можно')
        ->and($morning['notification']['body'])->toBe('1 дело на сегодня · Совет: '.$advice->text('ru')['title'].' · ещё 1 совет')
        ->and($morning['data'])->toBe(['url' => '/'])
        ->and($morning['android']['notification']['tag'])->toBe('morning')
        ->and($advice->fresh()->sent_via)->toBe(['push'])
        ->and($quiet->fresh()->sent_via)->toBe(['push']);

    $spend = fn (int $amount, string $when) => $this->user->transactions()->create([
        'type' => 'expense', 'account_id' => $this->user->accounts()->first()->id, 'amount' => $amount, 'currency' => 'UZS',
        'rate' => '1.0000', 'amount_uzs' => $amount, 'category_id' => $this->taxi->id, 'occurred_at' => Carbon::parse($when, 'Asia/Tashkent'),
    ]);
    $spend(500_000, '2026-10-15 12:00');
    $spend(300_000, '2026-10-24 12:00');

    pushAt('2026-10-25 20:00', 'push:digest weekly', 'bot:digest weekly');
    expect(pushSent())->toHaveCount(2)
        ->and(pushSent()[1]['notification'])->toBe(['title' => pushNb('Неделя: потрачено 300 000 сум'), 'body' => pushNb('На 200 000 сум меньше, чем неделей раньше.')])
        ->and(pushSent()[1]['data'])->toBe(['url' => '/money']);

    // Sunday the 1st: one notification, the month's.
    pushAt('2026-11-01 20:00', 'push:digest weekly', 'bot:digest weekly', 'push:digest monthly', 'bot:digest monthly');
    expect(pushSent())->toHaveCount(3)
        ->and(pushSent()[2]['notification']['title'])->toBe('📊 Итоги месяца: Октябрь')
        ->and(pushSent()[2]['data'])->toBe(['url' => '/money/report/2026-10'])
        ->and(pushChat())->toBe([]);
});

it('hands a digest no phone took to the bot in the same minute', function () {
    pushPhone();
    pushVia('push');
    $this->user->tasks()->create(['title' => 'Позвонить в банк', 'due_on' => '2026-10-24']);

    $this->fcmAnswers = [pushFcmError(503, 'UNAVAILABLE', 'UNAVAILABLE')];
    pushAt('2026-10-24 08:00', 'advisor:run morning', 'push:digest morning', 'bot:digest morning');
    expect(pushSent())->toHaveCount(1)
        ->and(pushChat())->toHaveCount(1)
        ->and(json_encode(pushChat()[0], JSON_UNESCAPED_UNICODE))->toContain('Позвонить в банк');

    // The next morning the phone takes it, and the bot stays quiet.
    pushAt('2026-10-25 08:00', 'advisor:run morning', 'push:digest morning', 'bot:digest morning');
    expect(pushSent())->toHaveCount(2)
        ->and(pushChat())->toHaveCount(1);
});

it('keeps each channel\'s claim on a reminder when the choice changes inside its window', function () {
    pushPhone();
    pushVia('push');
    $this->postJson('/api/events', ['title' => 'Встреча с дилером', 'starts_on' => '2026-10-23', 'start_time' => '16:00', 'end_time' => '17:00', 'remind_before' => 10], $this->auth)->assertCreated();
    $this->postJson('/api/events', ['title' => 'Звонок поставщику', 'starts_on' => '2026-10-23', 'start_time' => '17:00', 'end_time' => '17:30', 'remind_before' => 10], $this->auth)->assertCreated();

    // On the phone, then both: the bot, just added, brings it too; the phone does not ring again.
    pushAt('2026-10-23 15:50');
    pushVia('both');
    pushAt('2026-10-23 15:52');
    expect(pushSent())->toHaveCount(1)
        ->and(pushChat())->toHaveCount(1)
        ->and(json_encode(pushChat()[0], JSON_UNESCAPED_UNICODE))->toContain('Встреча с дилером');

    // On the phone, then the bot only: what the phone brought counts.
    pushVia('push');
    pushAt('2026-10-23 16:50');
    pushVia('telegram');
    pushAt('2026-10-23 16:52');
    expect(pushSent())->toHaveCount(2)
        ->and(pushSent()[1]['notification']['title'])->toBe('⏰ Звонок поставщику')
        ->and(pushChat())->toHaveCount(1);
});

it('gives up on a Firebase that does not answer for ten minutes, while the bot can still bring the reminder', function () {
    pushPhone();
    pushVia('push');
    $this->postJson('/api/events', ['title' => 'Встреча с дилером', 'starts_on' => '2026-10-23', 'start_time' => '14:45', 'end_time' => '15:00', 'remind_before' => 10], $this->auth)->assertCreated();

    // Asked to wait 20 minutes: waits five at most, a reminder lives fifteen.
    $this->fcmAnswers = [pushFcmError(429, 'RESOURCE_EXHAUSTED', 'QUOTA_EXCEEDED', ['headers' => ['Retry-After' => '1200']])];
    pushAt('2026-10-23 14:35');
    foreach (['14:36', '14:37', '14:38', '14:39'] as $minute) {
        pushAt("2026-10-23 {$minute}");
    }
    expect($this->fcmTries)->toBe(1);

    // Then no answer at all: tried every minute, until ten minutes have gone by since the first miss.
    $this->fcmDown = true;
    foreach (['14:40', '14:41', '14:42', '14:43', '14:44'] as $minute) {
        pushAt("2026-10-23 {$minute}");
    }
    expect($this->fcmTries)->toBe(6)
        ->and(pushChat())->toBe([]);
    pushAt('2026-10-23 14:45');
    pushAt('2026-10-23 14:46');
    expect(pushChat())->toHaveCount(1)
        ->and(json_encode(pushChat()[0], JSON_UNESCAPED_UNICODE))->toContain('Встреча с дилером')
        ->and($this->getJson('/api/me', $this->auth)->json('push.devices'))->toBe(1);
});

it('forgets a phone left on another Firebase project when a newer one takes the message', function () {
    pushPhone('old-build');
    $this->app['auth']->forgetGuards();
    $tablet = ['Authorization' => 'Bearer '.$this->user->createToken('Chrome, Android')->plainTextToken];
    $this->postJson('/api/push/devices', ['token' => 'new-build'], $tablet)->assertOk();
    pushVia('push');

    $this->fcmAnswers = [pushFcmError(403, 'PERMISSION_DENIED', 'SENDER_ID_MISMATCH')];
    $insight = pushInsight();
    pushAt('2026-10-23 14:21');
    expect($insight->fresh()->sent_via)->toBe(['push'])
        ->and(DeviceToken::pluck('token')->all())->toBe(['new-build'])
        ->and(pushChat())->toBe([]);
    pushInsight();
    pushAt('2026-10-23 14:22');
    expect(pushSent())->toHaveCount(3);
});

it('counts misses in a row, not two single ones far apart', function () {
    pushPhone();
    pushVia('push');
    $this->user->tasks()->create(['title' => 'Позвонить в банк', 'due_on' => '2026-10-24']);

    $this->fcmAnswers = [pushFcmError(503, 'UNAVAILABLE', 'UNAVAILABLE')];
    pushAt('2026-10-24 08:00', 'advisor:run morning', 'push:digest morning', 'bot:digest morning');
    expect(pushChat())->toHaveCount(1);

    // Half an hour later one more miss: tried again a minute after, still on the phone.
    $this->fcmAnswers = [pushFcmError(503, 'UNAVAILABLE', 'UNAVAILABLE')];
    $insight = pushInsight();
    pushAt('2026-10-24 08:30');
    pushAt('2026-10-24 08:31');
    expect($insight->fresh()->sent_via)->toBe(['push'])
        ->and(pushChat())->toHaveCount(1);
});

it('still gives up on a silent Firebase when an old phone keeps answering «another project»', function () {
    pushPhone('old-build');
    $this->app['auth']->forgetGuards();
    $tablet = ['Authorization' => 'Bearer '.$this->user->createToken('Chrome, Android')->plainTextToken];
    $this->postJson('/api/push/devices', ['token' => 'new-build'], $tablet)->assertOk();
    pushVia('push');
    $this->postJson('/api/events', ['title' => 'Встреча с дилером', 'starts_on' => '2026-10-23', 'start_time' => '14:45', 'end_time' => '15:00', 'remind_before' => 10], $this->auth)->assertCreated();

    $this->fcmByToken = ['old-build' => pushFcmError(403, 'PERMISSION_DENIED', 'SENDER_ID_MISMATCH')];
    $this->fcmDown = true;
    foreach (range(35, 46) as $minute) {
        pushAt("2026-10-23 14:{$minute}");
    }
    expect(pushChat())->toHaveCount(1)
        ->and(json_encode(pushChat()[0], JSON_UNESCAPED_UNICODE))->toContain('Встреча с дилером');
});

it('sends a test notification to see the whole way works', function () {
    pushPhone();
    $this->postJson('/api/push/test', [], $this->auth)->assertOk()->assertJsonPath('sent', true);
    expect(pushSent()[0]['notification'])->toBe(['title' => 'Уведомления работают', 'body' => 'Так будут приходить напоминания, советы и итоги.'])
        ->and(pushSent()[0]['data'])->toBe(['url' => '/more']);

    // A key from another Firebase project than the app's: the phone is kept, the bot takes over...
    pushVia('push');
    $this->fcmAnswers = [pushFcmError(403, 'PERMISSION_DENIED', 'SENDER_ID_MISMATCH')];
    $this->postJson('/api/push/test', [], $this->auth)->assertStatus(502);
    $waiting = pushInsight();
    pushAt('2026-10-23 14:21');
    expect(DeviceToken::count())->toBe(1)
        ->and($waiting->fresh()->sent_via)->toBe(['telegram']);

    // ...until the right key is in and the test goes through: the phone takes notifications again at once.
    $this->postJson('/api/push/test', [], $this->auth)->assertOk();
    $next = pushInsight();
    pushAt('2026-10-23 14:22');
    expect($next->fresh()->sent_via)->toBe(['push']);

    $this->fcmAnswers = [pushFcmError(404, 'NOT_FOUND', 'UNREGISTERED')];
    $this->postJson('/api/push/test', [], $this->auth)->assertStatus(410)->assertJsonPath('push.devices', 0);
});
