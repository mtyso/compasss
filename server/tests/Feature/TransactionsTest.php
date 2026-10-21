<?php

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];

    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 5_000_000]);
    $this->dollars = $this->user->accounts()->create(['name' => 'Наличные $', 'type' => 'cash', 'currency' => 'USD', 'opening_balance' => 50_000]);
    $this->savings = $this->user->accounts()->create(['name' => 'Копилка', 'type' => 'savings', 'currency' => 'UZS', 'spendable' => false]);

    $this->taxi = $this->user->categories()->firstWhere('system_key', 'transport_taxi');
    $this->subscriptions = $this->user->categories()->firstWhere('system_key', 'connection_subscriptions');
    $this->salary = $this->user->categories()->firstWhere('system_key', 'salary');

    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-10-20', 'rate' => '12050.1200']);
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-10-21', 'rate' => '12061.5000']);
    Http::preventStrayRequests();
});

function record(array $data)
{
    return test()->postJson('/api/transactions', $data, test()->auth);
}

it('counts a dollar expense in the som totals at the rate of its day', function () {
    record([
        'type' => 'expense', 'account_id' => $this->dollars->id, 'amount' => 2000,
        'category_id' => $this->subscriptions->id, 'occurred_at' => '2026-10-20 14:30', 'note' => 'ChatGPT',
    ])->assertCreated()
        ->assertJsonPath('currency', 'USD')
        ->assertJsonPath('rate', '12050.1200')
        ->assertJsonPath('amount_uzs', 241_002)
        ->assertJsonPath('occurred_at', '2026-10-20T14:30:00+05:00');

    record(['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 32_000, 'category_id' => $this->taxi->id, 'occurred_at' => '2026-10-21 09:00']);
    record(['type' => 'income', 'account_id' => $this->card->id, 'amount' => 12_000_000, 'category_id' => $this->salary->id, 'occurred_at' => '2026-10-10 10:00']);

    $summary = $this->getJson('/api/summary?month=2026-10', $this->auth)->assertOk();

    $summary->assertJsonPath('expense_uzs', 241_002 + 32_000)
        ->assertJsonPath('income_uzs', 12_000_000);
    // Rolled up to the main categories «Связь и подписки» and «Транспорт».
    expect(collect($summary->json('by_category'))->where('type', 'expense')->pluck('total_uzs', 'category_id')->all())->toBe([
        $this->subscriptions->parent_id => 241_002,
        $this->taxi->parent_id => 32_000,
    ]);
});

it('takes a typed rate over the stored one', function () {
    record([
        'type' => 'expense', 'account_id' => $this->dollars->id, 'amount' => 10_000,
        'category_id' => $this->taxi->id, 'occurred_at' => '2026-10-21 12:00', 'rate' => '12800',
    ])->assertJsonPath('rate', '12800.0000')->assertJsonPath('amount_uzs', 1_280_000);
});

it('moves balances with expenses, income and transfers between currencies', function () {
    record(['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 32_000, 'category_id' => $this->taxi->id, 'occurred_at' => '2026-10-21 09:00']);
    record(['type' => 'transfer', 'account_id' => $this->card->id, 'to_account_id' => $this->savings->id, 'amount' => 1_000_000, 'occurred_at' => '2026-10-21 10:00'])
        ->assertCreated()->assertJsonPath('to_amount', 1_000_000)->assertJsonPath('category_id', null);
    // Selling $100 for 1 205 000 som.
    record(['type' => 'transfer', 'account_id' => $this->dollars->id, 'to_account_id' => $this->card->id, 'amount' => 10_000, 'to_amount' => 1_205_000, 'occurred_at' => '2026-10-21 11:00'])
        ->assertCreated();

    $accounts = collect($this->getJson('/api/accounts', $this->auth)->assertOk()->json('accounts'))->pluck('balance', 'name');

    expect($accounts->all())->toBe([
        'Humo' => 5_000_000 - 32_000 - 1_000_000 + 1_205_000,
        'Наличные $' => 50_000 - 10_000,
        'Копилка' => 1_000_000,
    ]);
    // Transfers are not spending.
    $this->getJson('/api/summary?month=2026-10', $this->auth)->assertJsonPath('expense_uzs', 32_000);
});

it('asks how much arrived when a transfer changes currency', function () {
    record(['type' => 'transfer', 'account_id' => $this->dollars->id, 'to_account_id' => $this->card->id, 'amount' => 10_000, 'occurred_at' => '2026-10-21 11:00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to_amount');
});

it('asks for a rate when none is known and CBU is unreachable', function () {
    Http::fake(['cbu.uz/*' => Http::failedConnection()]);
    ExchangeRate::query()->delete();

    record(['type' => 'expense', 'account_id' => $this->dollars->id, 'amount' => 500, 'category_id' => $this->taxi->id, 'occurred_at' => '2026-10-21 12:00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rate');
});

it('validates the category against the operation type and ownership', function () {
    record(['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 1000, 'category_id' => $this->salary->id, 'occurred_at' => '2026-10-21'])
        ->assertJsonValidationErrors('category_id');

    $foreign = User::factory()->create()->categories()->firstWhere('system_key', 'transport_taxi');
    record(['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 1000, 'category_id' => $foreign->id, 'occurred_at' => '2026-10-21'])
        ->assertJsonValidationErrors('category_id');

    record(['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 0, 'category_id' => $this->taxi->id, 'occurred_at' => '2026-10-21'])
        ->assertJsonValidationErrors('amount');
});

it('lists a month newest first, filters by main category and searches notes', function () {
    record(['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 32_000, 'category_id' => $this->taxi->id, 'occurred_at' => '2026-10-21 09:00', 'note' => 'Yandex Go']);
    record(['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 20_000, 'category_id' => $this->taxi->id, 'occurred_at' => '2026-10-31 23:30']);
    record(['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 15_000, 'category_id' => $this->taxi->id, 'occurred_at' => '2026-11-01 00:10']);

    $october = $this->getJson('/api/transactions?month=2026-10', $this->auth)->assertOk()->json('data');
    expect(collect($october)->pluck('amount')->all())->toBe([20_000, 32_000]);

    $byParent = $this->getJson("/api/transactions?category_id={$this->taxi->parent_id}", $this->auth)->json('data');
    expect($byParent)->toHaveCount(3);

    $found = $this->getJson('/api/transactions?q=yandex', $this->auth)->json('data');
    expect($found)->toHaveCount(1);
});

it('edits, soft-deletes and restores an operation', function () {
    $id = record(['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 32_000, 'category_id' => $this->taxi->id, 'occurred_at' => '2026-10-21 09:00'])->json('id');

    $this->putJson("/api/transactions/{$id}", [
        'type' => 'expense', 'account_id' => $this->dollars->id, 'amount' => 300, 'category_id' => $this->taxi->id, 'occurred_at' => '2026-10-21 09:00',
    ], $this->auth)->assertOk()->assertJsonPath('currency', 'USD')->assertJsonPath('amount_uzs', 36_185);

    $this->deleteJson("/api/transactions/{$id}", [], $this->auth)->assertNoContent();
    expect(Transaction::withTrashed()->find($id)->trashed())->toBeTrue();
    $this->getJson('/api/summary?month=2026-10', $this->auth)->assertJsonPath('expense_uzs', 0);

    $this->postJson("/api/transactions/{$id}/restore", [], $this->auth)->assertOk();
    $this->getJson('/api/summary?month=2026-10', $this->auth)->assertJsonPath('expense_uzs', 36_185);
});

it('archives an account with history instead of deleting it', function () {
    record(['type' => 'expense', 'account_id' => $this->card->id, 'amount' => 1000, 'category_id' => $this->taxi->id, 'occurred_at' => '2026-10-21']);
    $empty = $this->user->accounts()->create(['name' => 'Пустой', 'type' => 'card', 'currency' => 'UZS']);

    $this->deleteJson("/api/accounts/{$this->card->id}", [], $this->auth)->assertNoContent();
    $this->deleteJson("/api/accounts/{$empty->id}", [], $this->auth)->assertNoContent();

    expect($this->card->fresh()->archived_at)->not->toBeNull()
        ->and($empty->fresh())->toBeNull();
});

it('totals accounts in som and leaves savings out of spendable money', function () {
    $this->travelTo('2026-10-21 12:00');

    $this->getJson('/api/accounts', $this->auth)
        ->assertJsonPath('total_uzs', 5_000_000 + 6_030_750)
        ->assertJsonPath('spendable_uzs', 5_000_000 + 6_030_750);

    $this->savings->update(['opening_balance' => 2_000_000]);
    $this->getJson('/api/accounts', $this->auth)
        ->assertJsonPath('total_uzs', 13_030_750)
        ->assertJsonPath('spendable_uzs', 11_030_750);
});
