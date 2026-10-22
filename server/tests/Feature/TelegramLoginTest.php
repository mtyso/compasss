<?php

use App\Models\LoginRequest;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

const OWNER_ID = 111222333;
const STRANGER_ID = 999888777;

beforeEach(function () {
    config([
        'telegram.bot_token' => 'test-token',
        'telegram.bot_username' => 'kompas_test_bot',
        'telegram.webhook_secret' => 'hook-secret',
        'telegram.allowed_ids' => [(string) OWNER_ID],
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
});

function startLogin(): array
{
    $response = test()->postJson('/api/auth/telegram/start', ['device_name' => 'Chrome, Windows'])->assertCreated();
    preg_match('/start=login_([A-Za-z0-9]{32})$/', $response->json('link'), $m);

    return [$response->json('id'), $response->json('secret'), $m[1]];
}

function pollStatus(string $id, ?string $secret)
{
    return test()->getJson("/api/auth/telegram/status/{$id}", $secret ? ['X-Login-Secret' => $secret] : []);
}

function webhook(array $update, string $secret = 'hook-secret')
{
    return test()->postJson('/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
}

function startMessage(int $fromId, string $text, string $languageCode = 'ru'): array
{
    return ['update_id' => 1, 'message' => [
        'message_id' => 10,
        'from' => ['id' => $fromId, 'first_name' => 'Elyor', 'username' => 'elyor', 'language_code' => $languageCode],
        'chat' => ['id' => $fromId, 'type' => 'private'],
        'text' => $text,
    ]];
}

function buttonPress(int $fromId, string $data): array
{
    return ['update_id' => 2, 'callback_query' => [
        'id' => 'cb1',
        'from' => ['id' => $fromId, 'first_name' => 'Elyor', 'username' => 'elyor', 'language_code' => 'ru'],
        'message' => ['message_id' => 11, 'chat' => ['id' => $fromId, 'type' => 'private']],
        'data' => $data,
    ]];
}

function sentTo(string $method): array
{
    return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), "/{$method}"))
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

it('opens a sign-in request with a bot link and a secret for the app', function () {
    [$id, $secret, $code] = startLogin();

    expect($id)->toHaveLength(26)
        ->and($secret)->toHaveLength(48)
        ->and($code)->toHaveLength(32);

    $login = LoginRequest::find($id);
    expect($login->status)->toBe('pending')
        ->and($login->device_name)->toBe('Chrome, Windows')
        ->and($login->code_hash)->toBe(hash('sha256', $code));
});

it('refuses to start when the bot is not configured', function () {
    config(['telegram.bot_username' => null]);

    $this->postJson('/api/auth/telegram/start')->assertStatus(503);
});

it('reports pending and hides the request from anyone without the secret', function () {
    [$id, $secret] = startLogin();

    pollStatus($id, $secret)->assertOk()->assertExactJson(['status' => 'pending']);
    pollStatus($id, 'wrong')->assertNotFound();
    pollStatus($id, null)->assertNotFound();
});

it('signs the owner in after the bot link and the confirm button', function () {
    [$id, $secret, $code] = startLogin();

    webhook(startMessage(OWNER_ID, "/start login_{$code}"))->assertOk();

    $confirm = sentTo('sendMessage')[0];
    expect($confirm['chat_id'])->toBe(OWNER_ID)
        ->and($confirm['text'])->toContain('Chrome, Windows')
        ->and($confirm['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toBe("login:approve:{$id}");
    pollStatus($id, $secret)->assertExactJson(['status' => 'pending']);

    webhook(buttonPress(OWNER_ID, "login:approve:{$id}"))->assertOk();

    $user = User::firstWhere('telegram_id', OWNER_ID);
    expect($user->name)->toBe('Elyor')->and($user->telegram_username)->toBe('elyor');

    $response = pollStatus($id, $secret)->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('user.name', 'Elyor');

    $this->getJson('/api/me', ['Authorization' => 'Bearer '.$response->json('token')])
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

it('hands out the token only once', function () {
    [$id, $secret, $code] = startLogin();
    webhook(startMessage(OWNER_ID, "/start login_{$code}"));
    webhook(buttonPress(OWNER_ID, "login:approve:{$id}"));

    pollStatus($id, $secret)->assertJsonPath('status', 'approved');
    pollStatus($id, $secret)->assertExactJson(['status' => 'used']);

    expect(User::first()->tokens()->count())->toBe(1);
});

it('turns strangers away in the bot', function () {
    [$id, $secret, $code] = startLogin();

    webhook(startMessage(STRANGER_ID, "/start login_{$code}"))->assertOk();
    webhook(buttonPress(STRANGER_ID, "login:approve:{$id}"))->assertOk();

    expect(sentTo('sendMessage')[0]['text'])->toBe(__('bot.private'));
    expect(User::count())->toBe(0);
    pollStatus($id, $secret)->assertExactJson(['status' => 'pending']);
});

it('declines when the owner presses «Отклонить»', function () {
    [$id, $secret, $code] = startLogin();
    webhook(startMessage(OWNER_ID, "/start login_{$code}"));

    webhook(buttonPress(OWNER_ID, "login:deny:{$id}"));

    pollStatus($id, $secret)->assertExactJson(['status' => 'denied']);
    expect(sentTo('editMessageText')[0]['text'])->toBe(__('bot.login.denied'));
});

it('treats an old link as expired', function () {
    [$id, $secret, $code] = startLogin();
    $this->travel(LoginRequest::TTL_MINUTES + 1)->minutes();

    webhook(startMessage(OWNER_ID, "/start login_{$code}"));

    expect(sentTo('sendMessage')[0]['text'])->toBe(__('bot.login.expired'));
    pollStatus($id, $secret)->assertExactJson(['status' => 'expired']);
});

it('does not accept a confirmation after the link expired', function () {
    [$id, $secret, $code] = startLogin();
    webhook(startMessage(OWNER_ID, "/start login_{$code}"));
    $this->travel(LoginRequest::TTL_MINUTES + 1)->minutes();

    webhook(buttonPress(OWNER_ID, "login:approve:{$id}"));

    expect(User::count())->toBe(0);
    pollStatus($id, $secret)->assertExactJson(['status' => 'expired']);
});

it('answers a plain /start with what the bot does, in Uzbek for an Uzbek Telegram', function () {
    webhook(startMessage(OWNER_ID, '/start', 'uz'));

    expect(sentTo('sendMessage')[0]['text'])->toBe(__('bot.welcome', [], 'uz'));
});

it('rejects webhook calls without the secret header', function () {
    webhook(startMessage(OWNER_ID, '/start'), 'wrong')->assertForbidden();
    $this->postJson('/telegram/webhook', startMessage(OWNER_ID, '/start'))->assertForbidden();

    Http::assertNothingSent();
});

it('rejects webhook calls when no secret is configured', function () {
    config(['telegram.webhook_secret' => null]);

    webhook(startMessage(OWNER_ID, '/start'), '')->assertForbidden();
});
