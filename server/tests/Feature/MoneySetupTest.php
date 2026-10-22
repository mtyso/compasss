<?php

use App\Models\Category;
use App\Models\User;
use App\Money\DefaultCategories;

beforeEach(function () {
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
});

it('gives a new account the ready-made categories with necessity marks', function () {
    $categories = $this->user->categories;

    expect($categories->whereNull('parent_id')->where('kind', 'expense'))->toHaveCount(count(DefaultCategories::EXPENSE))
        ->and($categories->where('kind', 'income'))->toHaveCount(count(DefaultCategories::INCOME))
        ->and($categories->firstWhere('system_key', 'groceries')->necessity)->toBe('essential')
        ->and($categories->firstWhere('system_key', 'cafe')->necessity)->toBe('flexible')
        // A child can differ from its parent: repairs are flexible inside essential «Дом».
        ->and($categories->firstWhere('system_key', 'home_repair')->necessity)->toBe('flexible')
        ->and($categories->firstWhere('system_key', 'home_rent')->necessity)->toBe('essential');

    DefaultCategories::seedFor($this->user);
    expect($this->user->categories()->count())->toBe($categories->count());
});

it('returns categories with names in both languages', function () {
    $taxi = collect($this->getJson('/api/categories', $this->auth)->assertOk()->json())
        ->firstWhere('system_key', 'transport_taxi');

    expect($taxi['names'])->toBe(['ru' => 'Такси', 'uz' => 'Taksi'])
        ->and($taxi['parent_id'])->toBe(Category::firstWhere('system_key', 'transport')->id);
});

it('adds a custom subcategory and lets a standard one be renamed and re-marked', function () {
    $cafe = $this->user->categories()->firstWhere('system_key', 'cafe');

    $this->postJson('/api/categories', ['kind' => 'expense', 'parent_id' => $cafe->id, 'name' => 'Шаурма'], $this->auth)
        ->assertCreated()
        ->assertJsonPath('names.ru', 'Шаурма')
        ->assertJsonPath('necessity', 'flexible');

    $this->patchJson("/api/categories/{$cafe->id}", ['name' => 'Еда вне дома', 'necessity' => 'essential'], $this->auth)
        ->assertOk()
        ->assertJsonPath('names', ['ru' => 'Еда вне дома', 'uz' => 'Еда вне дома'])
        ->assertJsonPath('necessity', 'essential');

    $this->patchJson("/api/categories/{$cafe->id}", ['name' => null], $this->auth)
        ->assertJsonPath('names.uz', 'Kafe va restoranlar');
});

it('refuses a subcategory under another subcategory or another kind', function () {
    $taxi = $this->user->categories()->firstWhere('system_key', 'transport_taxi');
    $salary = $this->user->categories()->firstWhere('system_key', 'salary');

    $this->postJson('/api/categories', ['kind' => 'expense', 'parent_id' => $taxi->id, 'name' => 'x'], $this->auth)->assertUnprocessable();
    $this->postJson('/api/categories', ['kind' => 'expense', 'parent_id' => $salary->id, 'name' => 'x'], $this->auth)->assertUnprocessable();
});

it('creates accounts and keeps the currency fixed afterwards', function () {
    $id = $this->postJson('/api/accounts', [
        'name' => 'Наличные $', 'type' => 'cash', 'currency' => 'USD', 'opening_balance' => 50_000,
    ], $this->auth)->assertCreated()->assertJsonPath('balance', 50_000)->json('id');

    $this->patchJson("/api/accounts/{$id}", ['currency' => 'UZS'], $this->auth)->assertUnprocessable();
    $this->patchJson("/api/accounts/{$id}", ['name' => 'Доллары дома'], $this->auth)->assertOk()->assertJsonPath('name', 'Доллары дома');
});

it('never shows another user\'s data', function () {
    $other = User::factory()->create();
    $foreign = $other->accounts()->create(['name' => 'x', 'type' => 'card', 'currency' => 'UZS']);
    $foreignCategory = $other->categories()->first();

    $this->patchJson("/api/accounts/{$foreign->id}", ['name' => 'mine'], $this->auth)->assertNotFound();
    $this->deleteJson("/api/categories/{$foreignCategory->id}", [], $this->auth)->assertNotFound();
    expect($this->getJson('/api/accounts', $this->auth)->json('accounts'))->toBe([]);
});
