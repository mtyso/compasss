<?php

namespace App\Telegram\Handlers;

use App\Advisor\Advisor;
use App\Models\Insight;
use App\Models\RecurringPayment;
use App\Models\User;
use App\Money\PaymentPayer;
use App\Telegram\Notifier;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;
use App\Telegram\Views\Fmt;

/** /start, /help, /today, /month and the «Оплачено» buttons under the day's summary and payment reminders. */
class Commands
{
    public function __construct(
        private TelegramClient $telegram,
        private PaymentPayer $payer,
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
        // A payment reminder repeats the summary's own payments block, with «Оплачено» under it:
        // only one that says money is missing adds something. «Обещал вернуть» needs its «Вернул» and «Долг
        // закрыт» its «Перенести платёж», so they stay unsent and bot:notify brings each right after the
        // summary as a message of its own.
        [$repeats, $insights] = Notifier::unsent($user, now())
            ->reject(fn (Insight $i) => in_array($i->action(), ['returned', 'roll'], true))
            ->partition(fn (Insight $i) => $i->rule === 'payment_due' && ($i->payload['short_amount'] ?? 0) <= 0);
        $insights = $insights->take(3);
        $this->telegram->sendCard($chatId, $this->cards->today($user, morning: true, insights: $insights), $this->cards->todayKeyboard($user));
        Notifier::markSent([...$repeats, ...$insights]);
    }

    /** «Оплачено» under the day's summary: the payment, then the summary redrawn without it. */
    public function onPay(array $query, User $user): void
    {
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);

        $payment = preg_match('/^pay:(\d+)$/', $query['data'] ?? '', $m)
            ? $user->recurringPayments()->where('active', true)->find((int) $m[1])
            : null;
        if (! $payment) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.record.gone', [], $user->locale));

            return;
        }

        $this->telegram->answerCallbackQuery($query['id'], $this->pay($payment, $user));
        // The summary above is now out of date: redraw it without the paid bill.
        $this->telegram->editCard($chatId, $messageId, $this->cards->today($user), $this->cards->todayKeyboard($user));
    }

    /**
     * Same as «Оплачено» in the app (App\Money\PaymentPayer): the expense from the payment's own account,
     * then the next due date. Returns the toast: what was paid, or why nothing was.
     */
    public function pay(RecurringPayment $payment, User $user): string
    {
        $l = $user->locale;

        // A second tap on an old summary must not pay next month's bill too.
        if ($payment->dueDate()->gt(now()->startOfDay()->addDays(10))) {
            return __('bot.pay.already', [], $l);
        }

        $account = $payment->account_id ? $user->accounts()->whereNull('archived_at')->find($payment->account_id) : null;
        if (! $account || $account->currency !== $payment->currency) {
            return __('bot.pay.need_app', [], $l);
        }

        $this->payer->pay($user, $payment, $account, source: 'bot');

        return __('bot.pay.done', ['name' => $payment->name, 'amount' => Fmt::money($payment->amount, $payment->currency, $l)], $l);
    }
}
