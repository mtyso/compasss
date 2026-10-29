<?php

use App\Models\Habit;
use App\Models\Record;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// Streaks and records in the bot (docs/BOT.md, docs/PLAN.md D37–D39): the money streaks line in /today,
// the 21:00 message with the streaks that end tonight, milestones as they happen, «Итог недели» on Sunday.

const STK_OWNER = 111222333;

beforeEach(function () {
    // Thursday evening, when advisor:run evening and the bot's evening message go out.
    $this->travelTo(Carbon::parse('2026-10-29 21:00', 'Asia/Tashkent'));
    config([
        'telegram.bot_token' => 'test-token',
        'telegram.webhook_secret' => 'hook-secret',
        'telegram.allowed_ids' => [(string) STK_OWNER],
        'telegram.webapp_url' => 'https://kompas.example.uz',
        'telegram.rich_messages' => true,
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 80]])]);

    $this->user = User::factory()->create(['telegram_id' => STK_OWNER, 'locale' => 'ru']);
    $this->card = $this->user->accounts()->create(['name' => 'Humo', 'type' => 'card', 'currency' => 'UZS', 'opening_balance' => 4_000_000]);
    $this->cafe = $this->user->categories()->firstWhere('system_key', 'cafe');
    $this->user->incomeSchedules()->create(['name' => 'Зарплата', 'day_of_month' => 10, 'expected_amount' => 12_000_000, 'currency' => 'UZS', 'account_id' => $this->card->id, 'active' => true]);
});

/** @return list<string> every date from $from to $to, both included, except $skip */
function stkDays(string $from, string $to, array $skip = []): array
{
    $days = [];
    for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
        if (! in_array($day->toDateString(), $skip, true)) {
            $days[] = $day->toDateString();
        }
    }

    return $days;
}

/** An operation written down when it happened, as «записано сегодня» counts by either date. */
function stkSpend(string $date, int $amount = 20_000): Transaction
{
    $now = now();
    $at = Carbon::parse("{$date} 13:00", 'Asia/Tashkent');
    test()->travelTo($at->min($now));
    $transaction = test()->user->transactions()->create([
        'type' => 'expense', 'account_id' => test()->card->id,
        'amount' => $amount, 'currency' => 'UZS', 'rate' => '1.0000', 'amount_uzs' => $amount,
        'category_id' => test()->cafe->id, 'occurred_at' => $at, 'source' => 'app',
    ]);
    test()->travelTo($now);

    return $transaction;
}

/** @param  list<string>  $done */
function stkHabit(string $name, string $startedOn, array $done): Habit
{
    $habit = test()->user->habits()->create(['name' => $name, 'type' => 'check', 'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7]], 'started_on' => $startedOn]);
    foreach ($done as $date) {
        $habit->logs()->create(['user_id' => test()->user->id, 'date' => $date, 'value' => 1]);
    }

    return $habit;
}

function stkSay(string $text): void
{
    test()->postJson('/telegram/webhook', ['update_id' => 1, 'message' => [
        'message_id' => 10, 'text' => $text,
        'from' => ['id' => STK_OWNER, 'first_name' => 'Elyor'],
        'chat' => ['id' => STK_OWNER, 'type' => 'private'],
    ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
}

function stkTap(string $data): void
{
    test()->postJson('/telegram/webhook', ['update_id' => 2, 'callback_query' => [
        'id' => 'cb', 'data' => $data,
        'from' => ['id' => STK_OWNER, 'first_name' => 'Elyor'],
        'message' => ['message_id' => 80, 'chat' => ['id' => STK_OWNER, 'type' => 'private']],
    ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
}

/** @return list<array<string, mixed>> */
function stkCalls(string $method): array
{
    return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), "/{$method}"))
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

/** @return list<string> the cards sent, as html */
function stkSent(): array
{
    return array_map(fn (array $m) => $m['rich_message']['html'], stkCalls('sendRichMessage'));
}

/** @return list<string> callback data, or the app address of a web app button */
function stkButtons(array $call): array
{
    return collect($call['reply_markup']['inline_keyboard'] ?? [])->flatten(1)
        ->map(fn ($b) => $b['callback_data'] ?? $b['web_app']['url'])->all();
}

function stkToast(): ?string
{
    return collect(stkCalls('answerCallbackQuery'))->last()['text'] ?? null;
}

function stkEvening(): void
{
    test()->artisan('advisor:run evening')->assertSuccessful();
    test()->artisan('bot:digest evening')->assertSuccessful();
    test()->artisan('bot:notify')->assertSuccessful();
}

/**
 * «Бег»: a week kept, the 22nd missed (the spare goes on it), six more days: 13 in a row with no spare.
 * «Чтение»: ten days in a row, a spare in hand. Operations on the same days as «Бег».
 */
function stkAtRiskWeek(): array
{
    $days = stkDays('2026-10-15', '2026-10-28', ['2026-10-22']);
    foreach ($days as $date) {
        stkSpend($date);
    }

    return [
        'run' => stkHabit('Бег', '2026-10-15', $days),
        'read' => stkHabit('Чтение', '2026-10-19', stkDays('2026-10-19', '2026-10-28')),
    ];
}

it('puts the money streaks in one line under the limit in /today, and leaves it out with nothing to count', function () {
    stkSay('/today');
    expect(stkSent()[0])->not->toContain('🔥');

    foreach (stkDays('2026-10-22', '2026-10-28') as $date) {
        $this->user->dayResults()->create(['date' => $date, 'limit_uzs' => 100_000]);
        stkSpend($date, 50_000);
    }

    stkSay('/today');
    expect(stkSent()[1])->toContain('🔥 В лимите 7 дн. · записи 7 дн.');
});

it('sends one message at 21:00: «ничего не записано» and the streaks that end tonight, with a button for each', function () {
    ['run' => $run] = stkAtRiskWeek();

    stkEvening();

    $evening = collect(stkCalls('sendRichMessage'))->filter(fn (array $m) => str_contains($m['rich_message']['html'], 'Сегодня ничего не записано'));
    expect($evening)->toHaveCount(1)->and(stkSent())->toHaveCount(1);
    $message = $evening->first();
    $insight = $this->user->insights()->where('rule', 'forgot_to_record')->sole();

    expect($message['rich_message']['html'])
        ->toContain('<h4>Оборвутся сегодня</h4>')
        ->toContain('Бег: 13 дней подряд, сегодня ещё не отмечено')
        ->toContain('Записи: 13 дней подряд, сегодня ещё ничего не записано')
        ->toContain('Запасных дней нет: отметь сегодня, и серия продолжится.')
        // «Чтение» has a spare day to cover tonight: nothing to worry about.
        ->not->toContain('Чтение')
        ->and(stkButtons($message))->toBe([
            'streak:nospend:2026-10-29',
            "streak:habit:{$run->id}:2026-10-29",
            "ins:useful:{$insight->id}",
            "ins:mute:{$insight->id}",
            'https://kompas.example.uz/',
        ])
        ->and($insight->fresh()->sent_via)->toContain('telegram');
});

it('sends the streaks alone when something was recorded, and nothing when no streak is at risk', function () {
    ['run' => $run] = stkAtRiskWeek();
    stkSpend('2026-10-29');

    stkEvening();

    expect(stkSent())->toHaveCount(1)
        ->and(stkSent()[0])->toContain('<h3>🔥 Серии на сегодня</h3>')->toContain('Бег: 13 дней подряд')->not->toContain('Записи:')
        ->and(stkButtons(stkCalls('sendRichMessage')[0]))->toBe(["streak:habit:{$run->id}:2026-10-29", 'https://kompas.example.uz/']);

    $run->logs()->create(['user_id' => $this->user->id, 'date' => '2026-10-29', 'value' => 1]);
    $this->artisan('bot:digest evening')->assertSuccessful();
    expect(stkSent())->toHaveCount(1);
});

it('takes «Трат не было» and a habit mark under the evening message, and redraws what is still open', function () {
    ['run' => $run] = stkAtRiskWeek();
    stkEvening();
    $insight = $this->user->insights()->where('rule', 'forgot_to_record')->sole();

    stkTap('streak:nospend:2026-10-29');
    expect(stkToast())->toBe('Отмечено: сегодня без трат')
        ->and($this->user->dayResults()->where('date', '2026-10-29')->value('no_spending'))->toBeTruthy()
        ->and($insight->fresh()->status)->toBe('useful')
        ->and(stkButtons(collect(stkCalls('editMessageReplyMarkup'))->last()))->toBe(["streak:habit:{$run->id}:2026-10-29", 'https://kompas.example.uz/']);

    stkTap("streak:habit:{$run->id}:2026-10-29");
    expect(stkToast())->toBe('Бег: готово')
        ->and($run->logs()->where('date', '2026-10-29')->value('value'))->toBe(1)
        ->and(stkButtons(collect(stkCalls('editMessageReplyMarkup'))->last()))->toBe(['https://kompas.example.uz/']);
});

it('marks the evening the button was for after midnight, and not a day long gone', function () {
    ['run' => $run] = stkAtRiskWeek();
    $this->travelTo(Carbon::parse('2026-10-30 00:20', 'Asia/Tashkent'));

    stkTap("streak:habit:{$run->id}:2026-10-29");
    expect($run->logs()->where('date', '2026-10-29')->exists())->toBeTrue()
        ->and($run->logs()->where('date', '2026-10-30')->exists())->toBeFalse()
        ->and(stkButtons(collect(stkCalls('editMessageReplyMarkup'))->last()))->toBe(['https://kompas.example.uz/']);

    stkTap('streak:nospend:2026-10-29');
    expect(stkToast())->toBe('Отмечено: 29 октября без трат');

    stkTap("streak:habit:{$run->id}:2026-10-27");
    expect(stkToast())->toBe('Этот день уже не отметить');
});

it('tells about a milestone reached with a bot button within a minute, once, and keeps it for the morning at night', function () {
    $run = stkHabit('Бег', '2026-09-30', stkDays('2026-09-30', '2026-10-28'));
    // The journal filling in a line from before yesterday: not news.
    $old = $this->user->records()->create(['kind' => 'cushion', 'subject' => '', 'ref' => 'cushion|1', 'value' => 1, 'date' => '2026-10-20']);

    $this->travelTo(Carbon::parse('2026-10-29 22:30', 'Asia/Tashkent'));
    stkTap("habit:inc:{$run->id}");
    $this->artisan('bot:notify')->assertSuccessful();
    expect(stkSent())->toBe([])
        ->and(Record::where('kind', 'streak_milestone')->where('value', 30)->exists())->toBeTrue();

    $this->travelTo(Carbon::parse('2026-10-30 08:00', 'Asia/Tashkent'));
    $this->artisan('bot:notify')->assertSuccessful();
    $this->artisan('bot:notify')->assertSuccessful();

    $sent = collect(stkCalls('sendRichMessage'))->filter(fn (array $m) => str_contains($m['rich_message']['html'], 'подряд'))->values();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['rich_message']['html'])->toContain('<h3>🔥 Бег: 30 дней подряд</h3>')->not->toContain('Подушка')
        ->and(stkButtons($sent[0]))->toBe(["https://kompas.example.uz/tasks/habits/{$run->id}", 'https://kompas.example.uz/goals/records'])
        ->and($old->fresh()->notified_at)->not->toBeNull();
});

it('puts several new lines in one message', function () {
    $this->travelTo(Carbon::parse('2026-10-29 12:00', 'Asia/Tashkent'));
    foreach ([['cushion', 'cushion|1', 1], ['cushion', 'cushion|3', 3]] as [$kind, $ref, $value]) {
        $this->user->records()->create(['kind' => $kind, 'subject' => '', 'ref' => $ref, 'value' => $value, 'date' => '2026-10-29']);
    }

    $this->artisan('bot:notify')->assertSuccessful();

    expect(stkSent())->toHaveCount(1)
        ->and(stkSent()[0])->toContain('<h3>🏆 Новое в рекордах</h3>')
        ->toContain('🛟 Подушка: 1 месяц расходов')->toContain('🛟 Подушка: 3 месяца расходов')
        ->and(stkButtons(stkCalls('sendRichMessage')[0]))->toBe(['https://kompas.example.uz/goals/records']);
});

it('brings «Итог недели» to the Sunday summary beside the week before, with the week\'s records', function () {
    // Sunday evening; the week from Monday the 19th against the one from the 12th.
    $this->travelTo(Carbon::parse('2026-10-25 20:00', 'Asia/Tashkent'));
    foreach (stkDays('2026-10-12', '2026-10-24') as $date) {
        $this->user->dayResults()->create(['date' => $date, 'limit_uzs' => 100_000]);
        // Two days over the limit each week.
        stkSpend($date, in_array($date, ['2026-10-13', '2026-10-14', '2026-10-20', '2026-10-21'], true) ? 150_000 : 50_000);
    }
    $run = stkHabit('Бег', '2026-10-12', stkDays('2026-10-12', '2026-10-24'));
    $this->user->records()->create([
        'kind' => 'streak_milestone', 'subject' => "h{$run->id}", 'ref' => "streak_milestone|h{$run->id}|30", 'value' => 30,
        'date' => '2026-10-22', 'payload' => ['name' => 'Бег', 'unit' => 'day'], 'notified_at' => now(),
    ]);

    $this->artisan('bot:digest weekly')->assertSuccessful();

    expect(stkSent())->toHaveCount(1)
        ->and(stkSent()[0])->toContain('Неделя: потрачено')
        ->toContain('<h4>Итог недели</h4>')
        ->toContain('4 из 6 дн. · неделей раньше 5 из 7 дн.')
        ->toContain('Привычки')
        ->toContain('<h4>Рекорды недели</h4>')
        ->toContain('🔥 Бег: 30 дней подряд');
});
