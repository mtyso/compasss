<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->token = $this->user->createToken('test')->plainTextToken;
    $this->auth = ['Authorization' => "Bearer {$this->token}"];
});

it('needs a token', function () {
    $this->getJson('/api/me')->assertUnauthorized();
    $this->get('/api/me')->assertUnauthorized();
});

it('returns and updates the profile', function () {
    $this->getJson('/api/me', $this->auth)->assertOk()->assertJsonPath('locale', 'ru');

    $this->patchJson('/api/me', ['locale' => 'uz', 'name' => 'Elyor'], $this->auth)
        ->assertOk()
        ->assertJsonPath('locale', 'uz')
        ->assertJsonPath('name', 'Elyor');

    $this->patchJson('/api/me', ['locale' => 'en'], $this->auth)->assertUnprocessable();
});

it('locks out an account removed from the allowed list', function () {
    config(['telegram.allowed_ids' => []]);

    $this->getJson('/api/me', $this->auth)->assertForbidden();
});

it('revokes the token on logout', function () {
    $this->postJson('/api/auth/logout', [], $this->auth)->assertNoContent();

    expect($this->user->tokens()->count())->toBe(0);
});

it('answers in the language the app asks for', function () {
    config(['telegram.allowed_ids' => []]);

    $this->getJson('/api/me', $this->auth + ['Accept-Language' => 'uz'])
        ->assertForbidden()
        ->assertJsonPath('message', __('login.not_allowed', [], 'uz'));
});
