<?php

namespace App\Telegram\Handlers;

use App\Advisor\Advisor;
use App\Models\Transaction;
use App\Models\User;
use App\Money\TransactionWriter;
use App\Support\MonthDay;
use App\Telegram\Notifier;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;
use App\Telegram\Views\Fmt;
use Illuminate\Support\Facades\DB;

/** /start, /help, /today, /month and the «Оплачено» button under the day's summary. */
class Commands
{
    public function __construct(
        private TelegramClient $telegram,
        private TransactionWriter $writer,
        private Cards $cards,
        private Advisor $advisor,
    ) {}

    public function handle(string $command, int $chatId, User $user): void
    {
        match ($command) {
            'today' => $this->today($chatId, $user),
            'month' => $this->telegram->sendCard($chatId, $this->cards->month($user), $this->cards->startKeyboard($user)),
            default => $this->telegram->sendCard($chatId, $this->cards->start($user), $this->cards->startKeyboard($user)),
        };
    }

    public function today(int $chatId, User $user, bool $morning = false): void
    {
        if (! $user->accounts()->whereNull('archived_at')->exists()) {
            $this->telegram->sendCard($chatId, $this->cards->noAccounts($user), $this->cards->noAccountsKeyboard($user));

            return;
        }

        if (! $morning) {
            $this->telegram->sendCard($chatId, $this->cards->today($user), $this->cards->todayKeyboard($user));

            return;
        }

        // The night's advice rides along with the morning summary instead of arriving as separate messages.
        // advisor:run morning is scheduled first; this covers a cron that ran late or out of order.
        $this->advisor->morning($user, now());
        $insights = Notifier::unsent($user, now())->take(3);
        $this->telegram->sendCard($chatId, $this->cards->today($user, morning: true, insights: $insights), $this->cards->todayKeyboard($user));
        Notifier::markSent($insights);
    }

    /** Same as «Оплачено» in the app (RecurringPaymentController::pay): the expense, then the next due date. */
    public function onPay(array $query, User $user): void
    {
        $l = $user->locale;
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);

        $payment = preg_match('/^pay:(\d+)$/', $query['data'] ?? '', $m)
            ? $user->recurringPayments()->where('active', true)->find((int) $m[1])
            : null;
        if (! $payment) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.record.gone', [], $l));

            return;
        }

        // A second tap on an old summary must not pay next month's bill too.
        if ($payment->dueDate()->gt(now()->startOfDay()->addDays(10))) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.pay.already', [], $l));

            return;
        }

        $account = $payment->account_id ? $user->accounts()->whereNull('archived_at')->find($payment->account_id) : null;
        if (! $account || $account->currency !== $payment->currency) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.pay.need_app', [], $l));

            return;
        }

        DB::transaction(function () use ($user, $payment, $account) {
            $this->writer->save($user, new Transaction(['recurring_payment_id' => $payment->id]), [
                'type' => 'expense',
                'account_id' => $account->id,
                'amount' => $payment->amount,
                'category_id' => $payment->category_id,
                'note' => $payment->name,
                'occurred_at' => now()->toDateTimeString(),
                'source' => 'bot',
            ]);
            $payment->update(['next_due_on' => MonthDay::nextMonth($payment->dueDate(), $payment->day_of_month)->toDateString()]);
        });

        $this->telegram->answerCallbackQuery($query['id'], __('bot.pay.done', ['name' => $payment->name, 'amount' => Fmt::money($payment->amount, $payment->currency, $l)], $l));
        // The summary above is now out of date: redraw it without the paid bill.
        $this->telegram->editCard($chatId, $messageId, $this->cards->today($user), $this->cards->todayKeyboard($user));
    }
}
