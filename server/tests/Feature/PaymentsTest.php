<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

// Recurring payments as things to do (docs/PLAN.md, stage 2.2, D28): an everyday expense that pays one,
// «Пропустить», reminders and spending that looks like a subscription.

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 15:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];

    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 4_000_000]);
    $this->home = $this->user->categories()->firstWhere('system_key', 'home');
    $this->internet = $this->user->categories()->firstWhere('system_key', 'home_internet');
    $this->subscriptions = $this->user->categories()->firstWhere('system_key', 'connection_subscriptions');
    $this->cafe = $this->user->categories()->firstWhere('system_key', 'cafe');
});

function newPayment(array $data = []): array
{
    return test()->postJson('/api/recurring-payments', [
        'name' => 'Интернет', 'amount' => 150_000, 'currency' => 'UZS', 'category_id' => test()->internet->id,
        'account_id' => test()->card->id, 'day_of_month' => 21, ...$data,
    ], test()->auth)->assertCreated()->json();
}

function spend(int $amount, int $categoryId, string $at = '2026-10-21 14:00', array $data = []): array
{
    return test()->postJson('/api/transactions', [
        'type' => 'expense', 'account_id' => test()->card->id, 'amount' => $amount, 'category_id' => $categoryId, 'occurred_at' => $at, ...$data,
    ], test()->auth)->assertCreated()->json();
}

// --- An expense written by hand pays the payment ------------------------------------------

it('counts an expense written by hand as the payment it pays, and undoes that with the expense', function () {
    $payment = newPayment();
    $this->getJson('/api/today', $this->auth)->assertJsonPath('limit.upcoming_payments_uzs', 150_000);

    $tx = spend(155_000, $this->internet->id);
    expect($tx['recurring_payment_id'])->toBe($payment['id']);
    $this->getJson('/api/recurring-payments', $this->auth)->assertJsonPath('0.next_due_on', '2026-11-21');
    // Set aside once, so neither today's spending nor the reserve counts it again.
    $this->getJson('/api/today', $this->auth)
        ->assertJsonPath('limit.upcoming_payments_uzs', 0)
        ->assertJsonPath('limit.spent_today_uzs', 0);

    $this->deleteJson("/api/transactions/{$tx['id']}", [], $this->auth)->assertNoContent();
    $this->getJson('/api/recurring-payments', $this->auth)->assertJsonPath('0.next_due_on', '2026-10-21');

    $this->postJson("/api/transactions/{$tx['id']}/restore", [], $this->auth)->assertOk();
    $this->getJson('/api/recurring-payments', $this->auth)->assertJsonPath('0.next_due_on', '2026-11-21');
});

it('matches a sub-category to its parent, late or a week early', function () {
    // Due on the 5th and not paid yet: paid late in «Дом › Интернет» for a payment set on «Дом».
    $late = newPayment(['name' => 'Аренда', 'amount' => 2_000_000, 'category_id' => $this->home->id, 'day_of_month' => 5, 'next_due_on' => '2026-10-05']);
    expect(spend(2_000_000, $this->internet->id)['recurring_payment_id'])->toBe($late['id']);

    // Due in six days: paid early.
    $early = newPayment(['day_of_month' => 27]);
    expect(spend(140_000, $this->internet->id)['recurring_payment_id'])->toBe($early['id']);
});

it('leaves other spending alone', function () {
    newPayment(['day_of_month' => 21]);

    expect(spend(150_000, $this->cafe->id)['recurring_payment_id'])->toBeNull()                       // another category
        ->and(spend(200_000, $this->internet->id)['recurring_payment_id'])->toBeNull()               // a third more
        ->and(spend(150_000, $this->internet->id, data: ['is_one_off' => true])['recurring_payment_id'])->toBeNull()
        ->and(spend(150_000, $this->internet->id, '2026-09-10 10:00')['recurring_payment_id'])->toBeNull(); // long before it was due
    $this->getJson('/api/recurring-payments', $this->auth)->assertJsonPath('0.next_due_on', '2026-10-21');

    // Paid once, the next month's charge is weeks away: a second expense is just an expense.
    expect(spend(150_000, $this->internet->id)['recurring_payment_id'])->not->toBeNull()
        ->and(spend(150_000, $this->internet->id)['recurring_payment_id'])->toBeNull();
});

it('lets a note naming the payment differ more, and links an expense moved into its category', function () {
    $gas = newPayment(['name' => 'Газ', 'amount' => 100_000, 'category_id' => $this->home->id]);
    expect(spend(140_000, $this->home->id, data: ['note' => 'газ за октябрь'])['recurring_payment_id'])->toBe($gas['id']);

    $tx = spend(150_000, $this->cafe->id);
    $internet = newPayment();
    $this->patchJson("/api/transactions/{$tx['id']}", [
        'type' => 'expense', 'account_id' => $this->card->id, 'amount' => 150_000, 'category_id' => $this->internet->id, 'occurred_at' => '2026-10-21 14:00',
    ], $this->auth)->assertOk()
        ->assertJsonPath('recurring_payment_id', $internet['id']);
});

it('keeps «Оплачено» to one month and lets a month be skipped', function () {
    $payment = newPayment();

    $this->postJson("/api/recurring-payments/{$payment['id']}/pay", ['amount' => 162_000], $this->auth)->assertOk()
        ->assertJsonPath('transaction.amount', 162_000)
        ->assertJsonPath('transaction.recurring_payment_id', $payment['id'])
        ->assertJsonPath('payment.next_due_on', '2026-11-21');

    $this->postJson("/api/recurring-payments/{$payment['id']}/skip", [], $this->auth)->assertOk()
        ->assertJsonPath('next_due_on', '2026-12-21');
    expect(Transaction::count())->toBe(1);
});

// --- Reminders ---------------------------------------------------------------------------

it('reminds the evening before, says what is missing, and once more the morning after', function () {
    $internet = newPayment(['day_of_month' => 22]);
    $rent = newPayment(['name' => 'Аренда', 'amount' => 3_900_000, 'category_id' => $this->home->id, 'day_of_month' => 22]);

    $this->travelTo(Carbon::parse('2026-10-21 21:00', 'Asia/Tashkent'));
    $this->artisan('advisor:run evening')->assertSuccessful();
    $reminders = $this->user->insights()->where('rule', 'payment_due')->orderBy('id')->get();

    // 4 000 000 on the card: the internet first, then 3 850 000 left for 3 900 000 of rent.
    expect($reminders)->toHaveCount(2)
        ->and($reminders[0]->severity)->toBe('info')
        ->and($reminders[0]->text('ru')['title'])->toBe("Завтра платёж: Интернет, 150\u{202F}000 сум")
        ->and($reminders[1]->severity)->toBe('warn')
        ->and($reminders[1]->text('ru')['title'])->toBe("Завтра Аренда: на «Humo» не хватает 50\u{202F}000 сум");

    // On the day nothing new: the evening's reminder stands for it.
    $this->travelTo(Carbon::parse('2026-10-22 08:00', 'Asia/Tashkent'));
    $this->artisan('advisor:run morning')->assertSuccessful();
    expect($this->user->insights()->where('rule', 'payment_due')->count())->toBe(2);

    // The rent was paid; the internet still is not the next morning.
    $this->postJson("/api/recurring-payments/{$rent['id']}/pay", [], $this->auth)->assertOk();
    $this->travelTo(Carbon::parse('2026-10-23 08:00', 'Asia/Tashkent'));
    $this->artisan('advisor:run morning')->assertSuccessful();
    $late = $this->user->insights()->where(['rule' => 'payment_due', 'period_key' => 'd2026-10-22:late'])->sole();
    expect($late->subject)->toBe("p{$internet['id']}")
        ->and($late->text('ru')['title'])->toBe('«Интернет» не отмечен оплаченным');
});

it('pays from a reminder in the app and then takes the button away', function () {
    $payment = newPayment(['day_of_month' => 22]);
    $this->travelTo(Carbon::parse('2026-10-21 21:00', 'Asia/Tashkent'));
    $this->artisan('advisor:run evening')->assertSuccessful();
    $reminder = $this->user->insights()->where('rule', 'payment_due')->sole();

    $shown = collect($this->getJson('/api/insights', $this->auth)->json('data'))->firstWhere('id', $reminder->id);
    expect($shown)->toMatchArray(['action' => 'pay', 'ref' => $payment['id']]);
    // «Сегодня» lists due payments itself.
    expect(collect($this->getJson('/api/today', $this->auth)->json('insights'))->pluck('rule'))->not->toContain('payment_due');

    $this->postJson("/api/insights/{$reminder->id}/action", [], $this->auth)->assertOk()
        ->assertJsonPath('insight.action', null)
        ->assertJsonPath('transaction.recurring_payment_id', $payment['id']);
    $this->getJson('/api/recurring-payments', $this->auth)->assertJsonPath('0.next_due_on', '2026-11-22');
    $this->postJson("/api/insights/{$reminder->id}/action", [], $this->auth)->assertUnprocessable();
});

it('drops a reminder nobody has seen once the payment is paid', function () {
    $payment = newPayment(['day_of_month' => 22]);
    $this->travelTo(Carbon::parse('2026-10-21 21:00', 'Asia/Tashkent'));
    $this->artisan('advisor:run evening')->assertSuccessful();

    spend(150_000, $this->internet->id, '2026-10-21 21:05');
    expect($this->user->insights()->where('rule', 'payment_due')->count())->toBe(0)
        ->and($this->user->recurringPayments()->find($payment['id'])->next_due_on)->toBe('2026-11-22');
});

// --- Subscriptions -----------------------------------------------------------------------

it('finds spending that repeats every month and suggests a payment for it', function () {
    // Three months of a gym by category alone, two of Netflix with its name, and coffee that is no subscription.
    foreach (['2026-08-03', '2026-09-02', '2026-10-03'] as $day) {
        spend(300_000, $this->subscriptions->id, "{$day} 10:00");
    }
    foreach (['2026-09-15', '2026-10-15'] as $day) {
        spend(120_000, $this->subscriptions->id, "{$day} 10:00", ['note' => 'Netflix']);
    }
    foreach (['2026-10-01', '2026-10-08', '2026-10-15'] as $day) {
        spend(35_000, $this->cafe->id, "{$day} 09:00");
    }

    $found = $this->getJson('/api/recurring-payments/suggestions', $this->auth)->assertOk()->json();
    expect($found)->toHaveCount(2)
        ->and($found[0])->toMatchArray(['name' => 'Netflix', 'amount' => 120_000, 'day_of_month' => 15, 'count' => 2, 'next_due_on' => '2026-11-15'])
        ->and($found[1])->toMatchArray(['amount' => 300_000, 'category_id' => $this->subscriptions->id, 'count' => 3, 'next_due_on' => '2026-11-03']);

    // The morning advice brings them up once, with «Добавить».
    $this->artisan('advisor:run morning')->assertSuccessful();
    $advice = $this->user->insights()->where('rule', 'subscription_found')->orderBy('id')->get();
    expect($advice)->toHaveCount(2)
        ->and($advice[0]->action())->toBe('add_payment')
        ->and($advice[0]->ref())->toBe($found[0]['key'])
        ->and($advice[0]->text('ru')['title'])->toBe('«Netflix» похоже на регулярный платёж');

    // Made a payment, Netflix is covered; «Не то» hides the gym.
    newPayment(['name' => 'Netflix', 'amount' => 120_000, 'category_id' => $this->subscriptions->id, 'day_of_month' => 15]);
    $this->postJson('/api/recurring-payments/suggestions/dismiss', ['key' => $found[1]['key']], $this->auth)->assertNoContent();
    $this->getJson('/api/recurring-payments/suggestions', $this->auth)->assertExactJson([]);
    expect($advice[0]->fresh()->action())->toBeNull()
        ->and($advice[1]->fresh()->status)->toBe('dismissed');

    $this->artisan('advisor:run morning')->assertSuccessful();
    expect($this->user->insights()->where('rule', 'subscription_found')->count())->toBe(2);
});
