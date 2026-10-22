<?php

use App\Models\Account;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Support\Carbon;

// Debts both ways (docs/PLAN.md, stage 2.3, D29): money lent and borrowed, getting it back and paying it off,
// the order debts are paid off in, and the payment a paid-off debt frees.

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 10:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 10_000_000]);
    $this->postJson('/api/income-schedules', ['name' => 'Зарплата', 'day_of_month' => 10, 'expected_amount' => 12_000_000, 'currency' => 'UZS'], $this->auth)->assertCreated();
});

function balanceOf(int $accountId): int
{
    return collect(test()->getJson('/api/accounts', test()->auth)->json('accounts'))->firstWhere('id', $accountId)['balance'];
}

// --- Мне должны -------------------------------------------------------------------------------

it('lends from a card, lends more, and gets it back in parts', function () {
    $lent = $this->postJson('/api/debts/lend', ['name' => 'Бекзод', 'account_id' => $this->card->id, 'amount' => 500_000, 'due_on' => '2026-11-01'], $this->auth)
        ->assertCreated()
        ->assertJsonPath('owed.0.name', 'Бекзод')
        ->assertJsonPath('owed.0.amount', 500_000)
        ->assertJsonPath('owed.0.due_on', '2026-11-01')
        ->assertJsonPath('owed.0.from_account_id', $this->card->id)
        ->assertJsonPath('owed_uzs', 500_000)
        ->assertJsonPath('transaction.note', 'В долг: Бекзод')
        ->json();
    $account = Account::find($lent['account_id']);
    expect($account->type)->toBe('lent')
        ->and($account->spendable)->toBeFalse()
        ->and(balanceOf($this->card->id))->toBe(9_500_000);

    // More to the same person, with a new date.
    $this->postJson('/api/debts/lend', ['lent_account_id' => $account->id, 'account_id' => $this->card->id, 'amount' => 200_000, 'due_on' => '2026-11-15'], $this->auth)
        ->assertCreated()
        ->assertJsonCount(1, 'owed')
        ->assertJsonPath('owed.0.amount', 700_000)
        ->assertJsonPath('owed.0.due_on', '2026-11-15');

    $this->postJson("/api/debts/{$account->id}/settle", ['account_id' => $this->card->id, 'amount' => 300_000], $this->auth)->assertOk()
        ->assertJsonPath('owed.0.amount', 400_000)
        ->assertJsonPath('transaction.account_id', $account->id)
        ->assertJsonPath('transaction.to_account_id', $this->card->id);
    $this->postJson("/api/debts/{$account->id}/settle", ['account_id' => $this->card->id, 'amount' => 500_000], $this->auth)->assertUnprocessable();

    // The rest, without a sum: all of it. The account leaves the lists.
    $this->postJson("/api/debts/{$account->id}/settle", ['account_id' => $this->card->id], $this->auth)->assertOk()
        ->assertJsonCount(0, 'owed');
    expect($account->fresh()->archived_at)->not->toBeNull()
        ->and(balanceOf($this->card->id))->toBe(10_000_000);
});

it('reminds on the promised day and three days later, and «Вернул» brings it back once', function () {
    $lent = $this->postJson('/api/debts/lend', ['name' => 'Бекзод', 'account_id' => $this->card->id, 'amount' => 500_000, 'due_on' => '2026-10-12'], $this->auth)->json();

    $this->travelTo(Carbon::parse('2026-10-12 08:00', 'Asia/Tashkent'));
    $this->artisan('advisor:run morning')->assertSuccessful();
    $today = $this->user->insights()->where('rule', 'lent_due')->sole();
    expect($today->severity)->toBe('info')
        ->and($today->text('ru')['title'])->toBe("Сегодня Бекзод обещал вернуть 500\u{202F}000 сум")
        ->and($today->action())->toBe('returned');

    $this->travelTo(Carbon::parse('2026-10-15 08:00', 'Asia/Tashkent'));
    $this->artisan('cache:clear')->assertSuccessful();
    $this->artisan('advisor:run morning')->assertSuccessful();
    $late = $this->user->insights()->where('rule', 'lent_due')->latest('id')->first();
    expect($late->id)->not->toBe($today->id)
        ->and($late->severity)->toBe('warn')
        ->and($late->text('ru')['body'])->toBe('Обещал до 12 октября. Напомни ему, а когда вернёт, нажми «Вернул».');

    $this->postJson("/api/insights/{$late->id}/action", [], $this->auth)->assertOk()
        ->assertJsonPath('transaction.amount', 500_000)
        ->assertJsonPath('transaction.to_account_id', $this->card->id)
        ->assertJsonPath('insight.action', null);
    // The unread reminder of the day went with the money; a second tap does nothing.
    expect(balanceOf($this->card->id))->toBe(10_000_000)
        ->and($today->fresh())->toBeNull();
    $this->postJson("/api/insights/{$late->id}/action", [], $this->auth)->assertUnprocessable();
    expect(Account::find($lent['account_id'])->archived_at)->not->toBeNull();
});

// --- Я должен ---------------------------------------------------------------------------------

it('borrows onto a card with a plan, or records an old debt, and reserves the payment', function () {
    $brother = $this->postJson('/api/debts/borrow', ['name' => 'Брат', 'account_id' => $this->card->id, 'amount' => 2_000_000, 'deadline' => '2027-02-01'], $this->auth)
        ->assertCreated()
        ->assertJsonPath('owe.0.name', 'Брат')
        ->assertJsonPath('owe.0.amount', 2_000_000)
        // 2 000 000 over 4 months
        ->assertJsonPath('owe.0.goal.monthly', 500_000)
        // Borrowed this period: nothing is due until the next income (the app's payoff plan counts on it too).
        ->assertJsonPath('owe.0.goal.due', 0)
        ->assertJsonPath('owe.0.goal.due_uzs', 0)
        ->json();
    expect(balanceOf($this->card->id))->toBe(12_000_000)
        ->and(Goal::find($brother['goal_id'])->type)->toBe('debt');
    // Borrowed this period: the first payment comes from the next income.
    $this->getJson('/api/today', $this->auth)->assertJsonPath('limit.planned_savings_uzs', 0);

    // Spent long ago: the debt starts below zero, the card stays as it is, and this period pays it.
    $card = $this->postJson('/api/debts/borrow', ['name' => 'Кредитка', 'currency' => 'UZS', 'amount' => 5_000_000, 'rate' => 36, 'monthly_amount' => 400_000], $this->auth)
        ->assertCreated()->json();
    expect(balanceOf($card['account_id']))->toBe(-5_000_000)
        ->and(balanceOf($this->card->id))->toBe(12_000_000);
    $this->getJson('/api/today', $this->auth)->assertJsonPath('limit.planned_savings_uzs', 400_000);

    // Without a plan: just the account.
    $friend = $this->postJson('/api/debts/borrow', ['name' => 'Алишер', 'currency' => 'UZS', 'amount' => 300_000], $this->auth)->assertCreated()->json();
    expect($friend['goal_id'])->toBeNull();

    $this->getJson('/api/debts', $this->auth)->assertOk()
        ->assertJsonPath('owe_uzs', 7_300_000)
        // The dearest first; the others by size
        ->assertJsonPath('owe.0.name', 'Кредитка')
        ->assertJsonPath('owe.0.rate', 36)
        ->assertJsonPath('owe.1.name', 'Алишер')
        ->assertJsonPath('owe.2.name', 'Брат')
        ->assertJsonPath('owe.1.goal', null);

    // Paid back in full: a debt nothing plans for leaves the lists.
    $this->postJson("/api/debts/{$friend['account_id']}/settle", ['account_id' => $this->card->id], $this->auth)->assertOk()
        ->assertJsonCount(2, 'owe');
    expect(Account::find($friend['account_id'])->archived_at)->not->toBeNull();
});

it('puts money beyond the payments on the first debt in the chosen order', function () {
    $this->postJson('/api/debts/borrow', ['name' => 'Кредитка', 'currency' => 'UZS', 'amount' => 5_000_000, 'rate' => 36, 'monthly_amount' => 400_000], $this->auth)->assertCreated();
    $this->postJson('/api/debts/borrow', ['name' => 'Алишер', 'currency' => 'UZS', 'amount' => 300_000], $this->auth)->assertCreated();

    // The snowball: the smallest first, which has no plan yet, so it gets one.
    $plan = $this->postJson('/api/debts/plan', ['method' => 'snowball', 'extra_uzs' => 150_000], $this->auth)->assertOk()
        ->assertJsonPath('method', 'snowball')
        ->assertJsonPath('owe.0.name', 'Алишер')
        ->assertJsonPath('owe.0.goal.monthly', 150_000)
        ->json();
    expect($this->user->fresh()->setting('debt_method'))->toBe('snowball');

    // The avalanche: more on top of the card's payment.
    $this->postJson('/api/debts/plan', ['method' => 'avalanche', 'extra_uzs' => 100_000], $this->auth)->assertOk()
        ->assertJsonPath('owe.0.name', 'Кредитка')
        ->assertJsonPath('owe.0.goal.monthly', 500_000);
    expect(Goal::find($plan['goal_id'])->monthly_amount)->toBe(150_000);
});

it('offers a paid-off debt\'s payment to the next one and moves it there', function () {
    $this->postJson('/api/debts/borrow', ['name' => 'Кредитка', 'currency' => 'UZS', 'amount' => 5_000_000, 'rate' => 36, 'monthly_amount' => 400_000], $this->auth)->assertCreated();
    $brother = $this->postJson('/api/debts/borrow', ['name' => 'Брат', 'currency' => 'UZS', 'amount' => 600_000, 'monthly_amount' => 300_000], $this->auth)->json();

    $this->postJson("/api/debts/{$brother['account_id']}/settle", ['account_id' => $this->card->id], $this->auth)->assertOk()
        ->assertJsonCount(1, 'owe');
    // Paid off: the account leaves the lists, the goal shows as reached until the payment is rolled on.
    expect(Account::find($brother['account_id'])->archived_at)->not->toBeNull()
        ->and(Goal::find($brother['goal_id'])->done_at)->toBeNull();

    $closed = $this->user->insights()->where('rule', 'goal_reached')->sole();
    expect($closed->payload)->toMatchArray(['variant' => 'debt_roll', 'payment_amount' => 300_000, 'next' => 'Кредитка'])
        ->and($closed->text('ru')['body'])->toBe("Освободилось 300\u{202F}000 сум в месяц. Перенеси их на «Кредитка»: так он закроется быстрее, а лимит не изменится.");

    $this->postJson("/api/insights/{$closed->id}/action", [], $this->auth)->assertOk()
        ->assertJsonPath('insight.action', null);
    $this->getJson('/api/debts', $this->auth)->assertJsonPath('owe.0.goal.monthly', 700_000);
    expect(Goal::find($brother['goal_id'])->done_at)->not->toBeNull();
    $this->postJson("/api/insights/{$closed->id}/action", [], $this->auth)->assertUnprocessable();
});

it('stops offering to roll a payment on once the next debt is paid off too', function () {
    $card = $this->postJson('/api/debts/borrow', ['name' => 'Кредитка', 'currency' => 'UZS', 'amount' => 500_000, 'monthly_amount' => 100_000], $this->auth)->json();
    $brother = $this->postJson('/api/debts/borrow', ['name' => 'Брат', 'currency' => 'UZS', 'amount' => 600_000, 'monthly_amount' => 300_000], $this->auth)->json();
    $this->postJson("/api/debts/{$brother['account_id']}/settle", ['account_id' => $this->card->id], $this->auth)->assertOk();
    $closed = $this->user->insights()->where('rule', 'goal_reached')->sole();
    expect($closed->action())->toBe('roll');

    $this->postJson("/api/debts/{$card['account_id']}/settle", ['account_id' => $this->card->id], $this->auth)->assertOk();
    expect($closed->fresh()->action())->toBeNull();
    $this->postJson("/api/insights/{$closed->id}/action", [], $this->auth)->assertUnprocessable();
});

it('does not let money go back and forth between debts', function () {
    $lent = $this->postJson('/api/debts/lend', ['name' => 'Бекзод', 'account_id' => $this->card->id, 'amount' => 500_000], $this->auth)->json();
    $debt = $this->postJson('/api/debts/borrow', ['name' => 'Брат', 'currency' => 'UZS', 'amount' => 600_000], $this->auth)->json();

    $this->postJson("/api/debts/{$debt['account_id']}/settle", ['account_id' => $lent['account_id']], $this->auth)->assertUnprocessable();
    $this->postJson('/api/debts/lend', ['name' => 'Кто-то', 'account_id' => $debt['account_id'], 'amount' => 1], $this->auth)->assertUnprocessable();
});
