<?php

use App\Advisor\Advisor;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Insight;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// Each rule of docs/PLAN.md, 2.2 on a small worked example.

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 15:00', 'Asia/Tashkent'));
    config(['telegram.allowed_ids' => ['111222333']]);
    $this->user = User::factory()->create(['telegram_id' => 111222333]);
    $this->auth = ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken];
    ExchangeRate::create(['currency' => 'USD', 'date' => '2026-07-01', 'rate' => '12000.0000']);

    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 4_000_000]);
    // Old operations live on an account that is not money for spending, so they shape the history without changing what is free now.
    $this->archive = $this->user->accounts()->create(['name' => 'Старая карта', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 0, 'spendable' => false]);
    $this->cat = fn (string $key) => $this->user->categories()->firstWhere('system_key', $key);
    $this->advisor = app(Advisor::class);
});

function advisorExpense(object $test, int $amount, string $category, string $when, array $extra = []): Transaction
{
    return advisorOperation($test, 'expense', $amount, $category, $when, $extra);
}

function advisorOperation(object $test, string $type, int $amount, string $category, string $when, array $extra = []): Transaction
{
    $account = $extra['account'] ?? $test->card;
    unset($extra['account']);

    return $test->user->transactions()->create([
        'type' => $type, 'account_id' => $account->id,
        'amount' => $amount, 'currency' => 'UZS', 'rate' => '1.0000', 'amount_uzs' => $amount,
        'category_id' => ($test->cat)($category)->id, 'occurred_at' => Carbon::parse($when, 'Asia/Tashkent'), 'source' => 'app',
        ...$extra,
    ]);
}

function advisorBudget(object $test, string $category, int $limit, string $month = '2026-10'): void
{
    $test->user->budgets()->create(['month' => $month, 'category_id' => ($test->cat)($category)->id, 'limit_uzs' => $limit]);
}

/** @return Collection<int, Insight> */
function advisorInsights(object $test, string $rule)
{
    return $test->user->insights()->where('rule', $rule)->get();
}

it('warns at 80% of a budget and alerts when it is exceeded, once each per month', function () {
    advisorBudget($this, 'cafe', 500_000);
    $t = advisorExpense($this, 420_000, 'cafe_delivery', '2026-10-20 13:00');

    $this->advisor->run($this->user, 'transaction', now(), $t);
    $this->advisor->run($this->user, 'transaction', now(), $t);
    $near = advisorInsights($this, 'budget_threshold');
    expect($near)->toHaveCount(1)
        ->and($near[0]->severity)->toBe('warn')
        ->and($near[0]->text('ru'))->toBe(['title' => 'Кафе и рестораны: 84% бюджета', 'body' => "До конца месяца осталось 80\u{202F}000 сум."])
        ->and($near[0]->text('uz')['title'])->toBe('Kafe va restoranlar: byudjetning 84%');

    advisorExpense($this, 100_000, 'cafe', '2026-10-21 12:00');
    $this->advisor->run($this->user, 'morning', now());
    $over = $this->user->insights()->where('period_key', '2026-10:over')->sole();
    expect($over->severity)->toBe('alert')
        ->and($over->text('ru')['body'])->toBe("Потрачено 520\u{202F}000 сум при плане 500\u{202F}000 сум, это на 20\u{202F}000 сум больше.");
});

it('keeps an unread insight current and leaves a read one as it was', function () {
    advisorBudget($this, 'cafe', 500_000);
    advisorExpense($this, 410_000, 'cafe', '2026-10-20 13:00');
    $this->advisor->run($this->user, 'morning', now());
    advisorExpense($this, 50_000, 'cafe', '2026-10-21 13:00');
    $this->advisor->run($this->user, 'morning', now());
    expect(advisorInsights($this, 'budget_threshold')->sole()->payload['percent'])->toBe(92);

    advisorInsights($this, 'budget_threshold')->sole()->update(['status' => 'seen']);
    advisorExpense($this, 20_000, 'cafe', '2026-10-21 14:00');
    $this->advisor->run($this->user, 'morning', now());
    expect(advisorInsights($this, 'budget_threshold')->sole()->payload['percent'])->toBe(92);
});

it('ignores back-dated operations from earlier months in budget rules', function () {
    advisorBudget($this, 'cafe', 500_000, '2026-09');
    $t = advisorExpense($this, 600_000, 'cafe', '2026-09-20 13:00');

    $this->advisor->run($this->user, 'transaction', now(), $t);

    expect(advisorInsights($this, 'budget_threshold'))->toBeEmpty();
});

it('tells how much a day is left when a flexible category outpaces the month', function () {
    $this->travelTo(Carbon::parse('2026-10-08 10:00', 'Asia/Tashkent'));
    advisorBudget($this, 'cafe', 1_000_000);
    advisorBudget($this, 'groceries', 1_000_000);
    advisorExpense($this, 500_000, 'cafe', '2026-10-05 13:00');
    advisorExpense($this, 500_000, 'groceries', '2026-10-05 13:00');

    // 8 of 31 days gone (26%), 50% spent: 500 000 left for 24 days
    $this->advisor->run($this->user, 'morning', now());
    $pace = advisorInsights($this, 'category_pace')->sole();
    expect($pace->period_key)->toBe('2026-W41')
        ->and($pace->payload)->toMatchArray(['percent' => 50, 'pace' => 26, 'per_day_uzs' => 20_833])
        ->and($pace->text('ru')['body'])->toBe("Потрачено 50% бюджета, а месяц прошёл на 26%. Чтобы уложиться, трать не больше 20\u{202F}833 сум в день.");
});

it('is quieter about the pace when the advisor is soft', function () {
    $this->travelTo(Carbon::parse('2026-10-08 10:00', 'Asia/Tashkent'));
    $this->user->update(['settings' => ['advisor_strictness' => 'soft']]);
    advisorBudget($this, 'cafe', 1_000_000);
    advisorExpense($this, 500_000, 'cafe', '2026-10-05 13:00');

    $this->advisor->run($this->user, 'morning', now());

    expect(advisorInsights($this, 'category_pace'))->toBeEmpty();
});

it('forecasts an overspend in essential categories', function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00', 'Asia/Tashkent'));
    advisorBudget($this, 'groceries', 2_000_000);
    advisorBudget($this, 'cafe', 2_000_000);
    advisorExpense($this, 1_200_000, 'groceries_bazaar', '2026-10-10 10:00');
    advisorExpense($this, 1_200_000, 'cafe', '2026-10-10 10:00');

    // 1 200 000 in 14 days is about 2 657 143 by the 31st
    $this->advisor->run($this->user, 'morning', now());
    $forecast = advisorInsights($this, 'overspend_forecast')->sole();
    expect($forecast->payload)->toMatchArray(['category_id' => ($this->cat)('groceries')->id, 'forecast_uzs' => 2_657_143, 'limit_uzs' => 2_000_000])
        ->and($forecast->text('ru')['title'])->toBe('Продукты: к концу месяца выйдет больше плана');
});

it('notices an expense far above the usual one in its category', function () {
    foreach ([140_000, 150_000, 160_000, 150_000, 130_000, 170_000] as $i => $amount) {
        advisorExpense($this, $amount, 'clothes', '2026-09-'.(10 + $i).' 12:00');
    }
    $coat = advisorExpense($this, 900_000, 'clothes', '2026-10-21 12:00');

    $this->advisor->run($this->user, 'transaction', now(), $coat);
    $large = advisorInsights($this, 'large_spend')->sole();
    expect($large->payload)->toMatchArray(['amount_uzs' => 900_000, 'typical_uzs' => 150_000, 'transaction_id' => $coat->id])
        ->and($large->action())->toBe('mark_one_off');

    // Already one-off, or without enough history: nothing to say.
    $gift = advisorExpense($this, 900_000, 'clothes', '2026-10-21 13:00', ['is_one_off' => true]);
    $phone = advisorExpense($this, 3_000_000, 'leisure_hobby', '2026-10-21 13:00');
    $this->advisor->run($this->user, 'transaction', now(), $gift);
    $this->advisor->run($this->user, 'transaction', now(), $phone);
    expect(advisorInsights($this, 'large_spend'))->toHaveCount(1);
});

it('adds up frequent small purchases in flexible subcategories', function () {
    foreach (range(1, 11) as $day) {
        advisorExpense($this, 30_000, 'cafe_coffee', "2026-10-{$day} 09:00");
        advisorExpense($this, 30_000, 'transport_taxi', "2026-10-{$day} 09:30");
    }

    $this->advisor->run($this->user, 'morning', now());

    $small = advisorInsights($this, 'small_things')->sole();
    expect($small->payload)->toMatchArray(['category_id' => ($this->cat)('cafe_coffee')->id, 'count' => 11, 'total_uzs' => 330_000])
        ->and($small->text('ru'))->toBe(['title' => 'Мелочи складываются: Кофе и перекусы', 'body' => "Покупок за месяц: 11, вместе 330\u{202F}000 сум."]);
});

it('splits a salary into payments, cushion and money for each day', function () {
    $this->user->accounts()->create(['name' => 'Подушка', 'type' => 'savings', 'currency' => 'UZS', 'opening_balance' => 2_000_000, 'spendable' => false, 'is_cushion' => true]);
    $this->user->incomeSchedules()->create(['name' => 'Зарплата', 'day_of_month' => 21, 'expected_amount' => 12_000_000, 'currency' => 'UZS']);
    $this->user->recurringPayments()->create(['name' => 'Аренда', 'amount' => 3_000_000, 'currency' => 'UZS', 'category_id' => ($this->cat)('home_rent')->id, 'day_of_month' => 1, 'next_due_on' => '2026-11-01']);
    $salary = advisorOperation($this, 'income', 12_000_000, 'salary', '2026-10-21 11:00');

    // 16 000 000 on the card − 3 000 000 rent = 13 000 000 free; 1 200 000 to the cushion; 11 800 000 for 31 days
    $this->advisor->run($this->user, 'transaction', now(), $salary);
    $split = advisorInsights($this, 'income_split')->sole();
    expect($split->severity)->toBe('good')
        ->and($split->payload)->toMatchArray([
            'variant' => 'cushion', 'payments_uzs' => 3_000_000, 'cushion_uzs' => 1_200_000,
            'left_uzs' => 11_800_000, 'per_day_uzs' => 380_645, 'days' => 31,
        ])
        ->and($split->text('ru')['title'])->toBe("Пришёл доход 12\u{202F}000\u{202F}000 сум");

    // A small refund or yesterday's income says nothing.
    $refund = advisorOperation($this, 'income', 100_000, 'other_income', '2026-10-21 12:00');
    $late = advisorOperation($this, 'income', 12_000_000, 'salary', '2026-10-20 12:00');
    $this->advisor->run($this->user, 'transaction', now(), $refund);
    $this->advisor->run($this->user, 'transaction', now(), $late);
    expect(advisorInsights($this, 'income_split'))->toHaveCount(1);
});

it('measures the cushion in months of usual spending', function () {
    // History from July: 5, 6 and 7 million a month, the median is 6 million.
    foreach (['07' => 5_000_000, '08' => 6_000_000, '09' => 7_000_000] as $month => $amount) {
        advisorExpense($this, $amount, 'groceries', "2026-{$month}-02 10:00", ['account' => $this->archive]);
    }
    advisorExpense($this, 900_000, 'leisure_travel', '2026-08-15 10:00', ['account' => $this->archive, 'is_one_off' => true]);

    $this->advisor->run($this->user, 'morning', now());
    expect(advisorInsights($this, 'cushion')->sole()->payload)->toMatchArray([
        'variant' => 'none', 'target_months' => 3, 'target_uzs' => 18_000_000, 'monthly_uzs' => 1_500_000,
    ]);

    $this->user->insights()->delete();
    $cushion = $this->user->accounts()->create(['name' => 'Подушка', 'type' => 'savings', 'currency' => 'UZS', 'opening_balance' => 2_000_000, 'spendable' => false, 'is_cushion' => true]);
    $this->advisor->run($this->user, 'morning', now());
    $below = advisorInsights($this, 'cushion')->sole();
    expect($below->severity)->toBe('warn')
        ->and($below->payload)->toMatchArray(['variant' => 'below', 'cushion_months' => 0.3, 'monthly_uzs' => 1_350_000])
        ->and($below->text('ru')['title'])->toBe('Подушки хватит на 0,3 мес.');

    $this->user->insights()->delete();
    $cushion->update(['opening_balance' => 20_000_000]);
    $this->advisor->run($this->user, 'morning', now());
    expect(advisorInsights($this, 'cushion')->sole())->severity->toBe('good')->payload->toMatchArray(['variant' => 'reached', 'cushion_months' => 3.3]);
});

it('says nothing about the cushion before a full month of history', function () {
    advisorExpense($this, 5_000_000, 'groceries', '2026-09-10 10:00', ['account' => $this->archive]);

    $this->advisor->run($this->user, 'morning', now());

    expect(advisorInsights($this, 'cushion'))->toBeEmpty();
});

it('reports last month\'s savings rate against the month before', function () {
    $this->travelTo(Carbon::parse('2026-11-03 09:00', 'Asia/Tashkent'));
    foreach (['09' => 9_100_000, '10' => 8_600_000] as $month => $spent) {
        advisorOperation($this, 'income', 10_000_000, 'salary', "2026-{$month}-01 10:00", ['account' => $this->archive]);
        advisorExpense($this, $spent, 'groceries', "2026-{$month}-02 10:00", ['account' => $this->archive]);
    }

    $this->advisor->run($this->user, 'morning', now());

    $rate = advisorInsights($this, 'savings_rate')->sole();
    expect($rate->severity)->toBe('good')
        ->and($rate->text('ru'))->toBe(['title' => 'За октябрь отложено 14% дохода', 'body' => 'За сентябрь было 9%.'])
        ->and($rate->text('uz')['title'])->toBe("Oktabr oyida daromadning 14% jamg'arildi");
});

it('warns when last month spent more than it earned', function () {
    $this->travelTo(Carbon::parse('2026-11-03 09:00', 'Asia/Tashkent'));
    advisorOperation($this, 'income', 10_000_000, 'salary', '2026-10-01 10:00', ['account' => $this->archive]);
    advisorExpense($this, 11_000_000, 'groceries', '2026-10-02 10:00', ['account' => $this->archive]);

    $this->advisor->run($this->user, 'morning', now());

    expect(advisorInsights($this, 'savings_rate')->sole())->severity->toBe('warn')
        ->payload->toMatchArray(['variant' => 'deficit', 'deficit_uzs' => 1_000_000]);
});

it('celebrates a month kept within the budget', function () {
    $this->travelTo(Carbon::parse('2026-11-03 09:00', 'Asia/Tashkent'));
    advisorBudget($this, 'cafe', 1_000_000);
    advisorExpense($this, 800_000, 'cafe', '2026-10-01 10:00', ['account' => $this->archive]);

    $this->advisor->run($this->user, 'morning', now());

    expect(advisorInsights($this, 'good_news')->sole()->text('ru')['title'])->toBe('Бюджет за октябрь выполнен');
});

it('celebrates a flexible category below last month at the same day', function () {
    advisorExpense($this, 600_000, 'cafe', '2026-09-01 10:00', ['account' => $this->archive]);
    advisorExpense($this, 900_000, 'cafe', '2026-09-25 10:00', ['account' => $this->archive]);
    advisorExpense($this, 300_000, 'cafe', '2026-10-10 10:00');

    $this->advisor->run($this->user, 'morning', now());

    expect(advisorInsights($this, 'good_news')->sole()->payload)->toMatchArray(['variant' => 'spending_down', 'less_uzs' => 300_000]);
});

it('reminds in the evening when nothing was recorded today', function () {
    $this->travelTo(Carbon::parse('2026-10-21 21:00', 'Asia/Tashkent'));
    $this->advisor->run($this->user, 'evening', now());
    expect(advisorInsights($this, 'forgot_to_record'))->toBeEmpty();

    $this->travelTo(Carbon::parse('2026-10-20 12:00', 'Asia/Tashkent'));
    advisorExpense($this, 30_000, 'cafe', '2026-10-20 12:00');
    $this->travelTo(Carbon::parse('2026-10-21 21:00', 'Asia/Tashkent'));
    $this->advisor->run($this->user, 'evening', now());
    expect(advisorInsights($this, 'forgot_to_record')->sole()->period_key)->toBe('2026-10-21');

    advisorExpense($this, 30_000, 'cafe', '2026-10-22 12:00');
    $this->travelTo(Carbon::parse('2026-10-22 21:00', 'Asia/Tashkent'));
    $this->advisor->run($this->user, 'evening', now());
    expect(advisorInsights($this, 'forgot_to_record'))->toHaveCount(1);
});

it('stays silent about muted rules', function () {
    $this->user->update(['settings' => ['muted_rules' => ['budget_threshold']]]);
    advisorBudget($this, 'cafe', 500_000);
    advisorExpense($this, 600_000, 'cafe', '2026-10-20 13:00');

    $this->advisor->run($this->user, 'morning', now());

    expect(advisorInsights($this, 'budget_threshold'))->toBeEmpty();
});

it('runs after an operation saved through the API, from any source', function () {
    advisorBudget($this, 'cafe', 100_000);

    $this->postJson('/api/transactions', [
        'type' => 'expense', 'account_id' => $this->card->id, 'amount' => 150_000,
        'category_id' => ($this->cat)('cafe')->id, 'occurred_at' => '2026-10-21 13:00',
    ], $this->auth)->assertCreated();

    expect(advisorInsights($this, 'budget_threshold')->sole()->severity)->toBe('alert');
});

it('lists every rule under the same keys everywhere', function () {
    $keys = collect(Advisor::RULES)->map(fn ($class) => app($class)->key())->all();

    expect($keys)->toBe(Insight::RULES)
        ->and(explode(',', Insight::RULES_LIST))->toBe(Insight::RULES);
});
