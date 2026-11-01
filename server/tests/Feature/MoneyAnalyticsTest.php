<?php

use App\Models\User;
use Illuminate\Support\Carbon;

// The «Деньги» screen's analytics (docs/PLAN.md, D41): days, the previous month, months side by side,
// the week and the day, the biggest operations and subcategories; plus the operations of one day.

beforeEach(function () {
    // Friday 16 October: half of October is gone.
    $this->travelTo(Carbon::parse('2026-10-16 10:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 0]);
    $this->cat = fn (string $key) => $this->user->categories()->firstWhere('system_key', $key);
});

function analyticsOp(object $test, string $type, int $amount, string $when, ?string $category = null, array $extra = []): object
{
    return $test->user->transactions()->create([
        'type' => $type, 'account_id' => $test->card->id, 'amount' => $amount, 'currency' => 'UZS', 'rate' => '1.0000',
        'amount_uzs' => $amount, 'category_id' => $category ? ($test->cat)($category)->id : null,
        'occurred_at' => Carbon::parse($when, 'Asia/Tashkent'), ...$extra,
    ]);
}

/** September from the 2nd, then the first half of October with a coat bought once. */
function analyticsMonths(object $test): void
{
    analyticsOp($test, 'expense', 100_000, '2026-09-02 12:00', 'groceries_bazaar');
    analyticsOp($test, 'income', 5_000_000, '2026-09-03 09:00', 'salary');
    analyticsOp($test, 'expense', 50_000, '2026-09-10 20:00', 'cafe');

    analyticsOp($test, 'expense', 40_000, '2026-10-01 09:15', 'groceries_bazaar');   // Thursday
    analyticsOp($test, 'income', 5_000_000, '2026-10-03 09:00', 'salary');
    analyticsOp($test, 'expense', 60_000, '2026-10-03 19:30', 'cafe');               // Saturday
    analyticsOp($test, 'expense', 20_000, '2026-10-03 21:10', 'transport_taxi');     // Saturday
    analyticsOp($test, 'expense', 80_000, '2026-10-10 19:05', 'groceries_supermarket'); // Saturday
    analyticsOp($test, 'expense', 1_000_000, '2026-10-12 14:00', 'clothes', ['is_one_off' => true]); // Monday
    analyticsOp($test, 'expense', 30_000, '2026-10-15 23:30', 'cafe');               // Thursday
    // Neither a transfer nor a deleted expense is spending.
    $savings = $test->user->accounts()->create(['name' => 'Копилка', 'type' => 'savings', 'currency' => 'UZS', 'opening_balance' => 0]);
    analyticsOp($test, 'transfer', 500_000, '2026-10-05 10:00', null, ['to_account_id' => $savings->id, 'to_amount' => 500_000]);
    analyticsOp($test, 'expense', 999_000, '2026-10-06 10:00', 'cafe')->delete();
}

it('shows a month still going day by day, against the last one, by weekday and hour', function () {
    analyticsMonths($this);

    $data = $this->getJson('/api/analytics?month=2026-10', $this->auth)->assertOk()
        ->assertJsonPath('month', '2026-10')
        ->assertJsonPath('first_month', '2026-09')
        ->assertJsonPath('tracked', true)
        ->assertJsonPath('over', false)
        ->assertJsonPath('today', '2026-10-16')
        ->assertJsonPath('counted_days', 16)
        ->json();

    $days = collect($data['days'])->keyBy('date');
    expect($data['days'])->toHaveCount(31)
        ->and($days['2026-10-01'])->toBe(['date' => '2026-10-01', 'expense_uzs' => 40_000, 'one_off_uzs' => 0, 'count' => 1])
        ->and($days['2026-10-03'])->toMatchArray(['expense_uzs' => 80_000, 'count' => 2])
        ->and($days['2026-10-05'])->toMatchArray(['expense_uzs' => 0, 'count' => 0])
        ->and($days['2026-10-06'])->toMatchArray(['expense_uzs' => 0, 'count' => 0])
        ->and($days['2026-10-12'])->toMatchArray(['expense_uzs' => 1_000_000, 'one_off_uzs' => 1_000_000]);

    // September, day by day, to compare the running total with.
    expect($data['prev']['month'])->toBe('2026-09')
        ->and($data['prev']['tracked'])->toBeTrue()
        ->and($data['prev']['days'])->toHaveCount(30)
        ->and($data['prev']['days'][1])->toBe(['date' => '2026-09-02', 'expense_uzs' => 100_000])
        ->and($data['prev']['days'][9])->toBe(['date' => '2026-09-10', 'expense_uzs' => 50_000]);

    // Only the months the app has seen, oldest first.
    expect($data['months'])->toBe([
        ['month' => '2026-09', 'expense_uzs' => 150_000, 'one_off_uzs' => 0, 'income_uzs' => 5_000_000],
        ['month' => '2026-10', 'expense_uzs' => 1_230_000, 'one_off_uzs' => 1_000_000, 'income_uzs' => 5_000_000],
    ]);

    // The week and the day leave the coat out; days are the weekdays gone by: three Thursdays, two Saturdays.
    $weekdays = collect($data['weekdays'])->keyBy('weekday');
    expect($weekdays)->toHaveCount(7)
        ->and($weekdays[4])->toBe(['weekday' => 4, 'total_uzs' => 70_000, 'count' => 2, 'days' => 3])
        ->and($weekdays[6])->toBe(['weekday' => 6, 'total_uzs' => 160_000, 'count' => 3, 'days' => 2])
        ->and($weekdays[1])->toBe(['weekday' => 1, 'total_uzs' => 0, 'count' => 0, 'days' => 2])
        ->and($weekdays[7]['days'])->toBe(2);

    $hours = collect($data['hours'])->keyBy('hour');
    expect($hours)->toHaveCount(24)
        ->and($hours[19])->toBe(['hour' => 19, 'total_uzs' => 140_000, 'count' => 2])
        ->and($hours[9]['total_uzs'])->toBe(40_000)
        ->and($hours[23]['total_uzs'])->toBe(30_000)
        ->and($hours[14]['total_uzs'])->toBe(0);

    // The five biggest operations, as the list shows them.
    expect(array_column($data['top'], 'amount_uzs'))->toBe([1_000_000, 80_000, 60_000, 40_000, 30_000])
        ->and($data['top'][0])->toHaveKeys(['id', 'category_id', 'occurred_at', 'created_at', 'is_one_off']);

    // Groceries split into the bazaar and the supermarket; a coat written under «Одежда» itself is its own row.
    $groceries = ($this->cat)('groceries')->id;
    $subs = collect($data['subcategories'])->keyBy('category_id');
    expect($subs[($this->cat)('groceries_supermarket')->id])->toBe(['category_id' => ($this->cat)('groceries_supermarket')->id, 'root_id' => $groceries, 'total_uzs' => 80_000, 'count' => 1])
        ->and($subs[($this->cat)('groceries_bazaar')->id])->toMatchArray(['root_id' => $groceries, 'total_uzs' => 40_000])
        ->and($subs[($this->cat)('clothes')->id])->toMatchArray(['root_id' => ($this->cat)('clothes')->id, 'total_uzs' => 1_000_000])
        ->and($data['subcategories'][0]['total_uzs'])->toBe(1_000_000);
});

it('counts days from the first the app saw and compares a month that is over in full', function () {
    // The app started on 20 September: September is not seen whole.
    analyticsOp($this, 'expense', 70_000, '2026-09-20 08:00', 'cafe');
    analyticsOp($this, 'expense', 30_000, '2026-09-27 13:00', 'cafe');

    $this->getJson('/api/analytics?month=2026-09', $this->auth)->assertOk()
        ->assertJsonPath('tracked', false)
        ->assertJsonPath('over', true)
        ->assertJsonPath('today', null)
        // 20 to 30 September
        ->assertJsonPath('counted_days', 11)
        ->assertJsonPath('prev', null)
        ->assertJsonPath('months', [['month' => '2026-09', 'expense_uzs' => 100_000, 'one_off_uzs' => 0, 'income_uzs' => 0]]);

    // October has September before it, though not a whole one.
    $this->getJson('/api/analytics?month=2026-10', $this->auth)->assertOk()
        ->assertJsonPath('tracked', true)
        ->assertJsonPath('prev.month', '2026-09')
        ->assertJsonPath('prev.tracked', false)
        ->assertJsonPath('top', []);
});

it('starts empty and refuses a month still ahead', function () {
    $this->getJson('/api/analytics?month=2026-10', $this->auth)->assertOk()
        ->assertJsonPath('first_month', null)
        ->assertJsonPath('tracked', false)
        ->assertJsonPath('counted_days', 16)
        ->assertJsonPath('prev', null)
        ->assertJsonPath('months', [['month' => '2026-10', 'expense_uzs' => 0, 'one_off_uzs' => 0, 'income_uzs' => 0]])
        ->assertJsonPath('subcategories', []);

    $this->getJson('/api/analytics?month=2026-11', $this->auth)->assertUnprocessable();
    $this->getJson('/api/analytics', $this->auth)->assertUnprocessable();
});

it('keeps each user to their own operations', function () {
    analyticsMonths($this);
    config(['telegram.allowed_ids' => ['111222333', '444555666']]);
    $other = User::factory()->create(['telegram_id' => 444555666]);

    $this->getJson('/api/analytics?month=2026-10', ['Authorization' => 'Bearer '.$other->createToken('t')->plainTextToken])->assertOk()
        ->assertJsonPath('top', [])
        ->assertJsonPath('months.0.expense_uzs', 0);
});

it('lists the operations of one day, a category within it, and says when each was written down', function () {
    analyticsMonths($this);

    $saturday = $this->getJson('/api/transactions?day=2026-10-03', $this->auth)->assertOk()->json('data');
    expect(array_column($saturday, 'amount'))->toBe([20_000, 60_000, 5_000_000])
        ->and($saturday[0]['created_at'])->toBe(now()->toIso8601String());

    $this->getJson('/api/transactions?day=2026-10-03&category_id='.($this->cat)('transport')->id, $this->auth)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.amount', 20_000);

    $this->getJson('/api/transactions?day=03.10.2026', $this->auth)->assertUnprocessable();
});
