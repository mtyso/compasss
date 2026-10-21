<?php

use App\Telegram\WebAppInitData;

const BOT_TOKEN = '123456:TEST-token';

function launchData(array $overrides = []): array
{
    return [
        'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
        'user' => json_encode(['id' => 111222333, 'first_name' => 'Elyor', 'username' => 'elyor', 'language_code' => 'ru'], JSON_UNESCAPED_UNICODE),
        'auth_date' => '1792569600',
        'signature' => 'aBcD_eFgH',
        ...$overrides,
    ];
}

it('accepts launch data signed with the bot token', function () {
    $fields = WebAppInitData::validate(WebAppInitData::sign(launchData(), BOT_TOKEN), BOT_TOKEN, 86400, 1792569600 + 60);

    expect($fields)->not->toBeNull()
        ->and(json_decode($fields['user'], true)['id'])->toBe(111222333);
});

it('matches the algorithm in the Telegram docs', function () {
    // Built by hand: secret = HMAC(key "WebAppData", bot token), hash = HMAC(secret, sorted key=value lines).
    $fields = ['auth_date' => '1792569600', 'user' => '{"id":1}'];
    $secret = hash_hmac('sha256', BOT_TOKEN, 'WebAppData', true);
    $hash = hash_hmac('sha256', "auth_date=1792569600\nuser={\"id\":1}", $secret);

    expect(WebAppInitData::validate('auth_date=1792569600&user=%7B%22id%22%3A1%7D&hash='.$hash, BOT_TOKEN, 86400, 1792569600))
        ->toBe($fields);
});

it('refuses data signed with another token or changed after signing', function () {
    $signed = WebAppInitData::sign(launchData(), 'other:token');
    expect(WebAppInitData::validate($signed, BOT_TOKEN, 86400, 1792569600))->toBeNull();

    $tampered = str_replace('111222333', '111222334', WebAppInitData::sign(launchData(), BOT_TOKEN));
    expect(WebAppInitData::validate($tampered, BOT_TOKEN, 86400, 1792569600))->toBeNull();
});

it('refuses stale data and data without a hash', function () {
    $signed = WebAppInitData::sign(launchData(), BOT_TOKEN);

    expect(WebAppInitData::validate($signed, BOT_TOKEN, 86400, 1792569600 + 86401))->toBeNull()
        ->and(WebAppInitData::validate('auth_date=1792569600&user=%7B%7D', BOT_TOKEN, 86400, 1792569600))->toBeNull()
        ->and(WebAppInitData::validate($signed, '', 86400, 1792569600))->toBeNull();
});
