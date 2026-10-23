<?php

namespace App\Push;

use App\Advisor\Advisor;
use App\Calendar\Reminders;
use App\Models\Insight;
use App\Models\RecurringPayment;
use App\Models\User;
use App\Money\MonthReport;
use App\Money\Plan;
use App\Notify\Channels;
use App\Telegram\Notifier;
use App\Telegram\Views\Cards;
use App\Telegram\Views\Fmt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Notifications on the phone (docs/PLAN.md, D36), for those whose notifications go there
 * (App\Notify\Channels). The same moments as the bot's: calendar reminders at their time, the advisor's
 * alerts, warnings and good news within a minute outside quiet hours, the morning summary, the weekly
 * totals and the month's report. A tap opens the screen the bot's button would.
 */
class PushNotifier
{
    /** At most this many separate notifications per user a minute, as in the bot. */
    private const PER_RUN = 3;

    public function __construct(
        private FcmClient $fcm,
        private Channels $channels,
        private Reminders $reminders,
        private Cards $cards,
        private Plan $plan,
        private Advisor $advisor,
    ) {}

    /** @return Collection<int, User> those whose notifications go to a phone now */
    public function users(): Collection
    {
        if (! $this->fcm->ready()) {
            return collect();
        }

        return User::whereHas('deviceTokens')->get()->filter(fn (User $user) => $this->channels->wants($user, 'push'))->values();
    }

    /**
     * Calendar reminders due now. Claimed before sending, as the bot does, and given back when no phone
     * took it, so the next minute (or the bot, if push broke meanwhile) tries again inside its window.
     *
     * @return int how many went out
     */
    public function remind(Carbon $now): int
    {
        if ($this->fcm->paused()) {
            return 0;
        }

        $sent = 0;
        foreach ($this->users() as $user) {
            // With both channels the bot claims the plain key and the phone its own (Reminders::due).
            $prefix = count($this->channels->for($user)) > 1 ? 'p:' : '';
            foreach ($this->reminders->due($user, $now, $prefix) as $reminder) {
                $key = $prefix.$reminder['key'];
                if (! $this->reminders->claim($user, $key, $now)) {
                    continue;
                }
                $text = $this->cards->reminderText($reminder, $user, $now);
                $result = $this->deliver($user, new PushMessage($text['title'], $text['body'], Cards::reminderPath($reminder), "r:{$reminder['key']}", 3600));
                if ($result !== PushResult::Sent && $result !== PushResult::Rejected) {
                    $this->reminders->release($user, $key);
                }
                if ($result === PushResult::Broken) {
                    return $sent;
                }
                $sent += $result === PushResult::Sent ? 1 : 0;
            }
        }

        return $sent;
    }

    /**
     * The advisor's new alerts, warnings, good news and reminders, as the bot picks them (Notifier::pushes),
     * outside quiet hours. A tap opens «Советы», where each has its button.
     *
     * @return int how many went out
     */
    public function run(Carbon $now): int
    {
        if (Notifier::isQuiet($now) || $this->fcm->paused()) {
            return 0;
        }

        $sent = 0;
        foreach ($this->users() as $user) {
            $due = $this->channels->unsent($user, $now, 'push')->filter(fn (Insight $i) => Notifier::pushes($i))->take(self::PER_RUN);
            foreach ($due as $insight) {
                $text = $insight->text($user->locale);
                $result = $this->deliver($user, new PushMessage(
                    $text['title'], $text['body'], '/advice', "i:{$insight->rule}:{$insight->subject}", 86400, ['insight_id' => $insight->id],
                ));
                // No phone took it: leave it, so the next minute or, with no phone left, the bot brings it.
                if ($result === PushResult::Sent || $result === PushResult::Rejected) {
                    Channels::markSent([$insight], 'push');
                }
                if ($result === PushResult::Broken) {
                    return $sent;
                }
                $sent += $result === PushResult::Sent ? 1 : 0;
            }
        }

        return $sent;
    }

    /**
     * «Доброе утро! Сегодня можно …» with what today holds: payments due, tasks, and the night's advice,
     * which rides along instead of arriving one by one (as in the bot's summary).
     */
    public function morning(User $user, Carbon $now): PushResult
    {
        $l = $user->locale;
        $today = $now->copy()->timezone(config('app.timezone'))->startOfDay();
        // advisor:run morning is scheduled first; this covers a cron that ran late or out of order.
        $this->advisor->morning($user, $now);
        $limit = $this->plan->limit($user, $now);

        // As in the bot: a payment reminder repeats «Сегодня платить», «Вернул» and «Перенести платёж» need
        // their own notification, so those stay unsent and come right after.
        [$repeats, $insights] = $this->channels->unsent($user, $now, 'push')
            ->reject(fn (Insight $i) => in_array($i->action(), ['returned', 'roll'], true))
            ->partition(fn (Insight $i) => $i->rule === 'payment_due' && ($i->payload['short_amount'] ?? 0) <= 0);
        $insights = $insights->take(3)->values();

        $payments = $user->recurringPayments()->where('active', true)->orderBy('next_due_on')->get()
            ->filter(fn (RecurringPayment $p) => $p->dueDate()->lte($today));
        $tasks = $user->tasks()->whereNull('done_at')->whereNotNull('due_on')->where('due_on', '<=', $today->toDateString())->count();

        $parts = array_values(array_filter([
            $payments->isNotEmpty() ? __('push.morning_payments', ['names' => $payments->pluck('name')->join(', ')], $l) : null,
            $tasks > 0 ? trans_choice('push.morning_tasks', $tasks, [], $l) : null,
            $insights->isNotEmpty() ? __('push.morning_advice', ['title' => $insights->first()->text($l)['title']], $l) : null,
            $insights->count() > 1 ? trans_choice('push.morning_more', $insights->count() - 1, [], $l) : null,
        ]));

        $result = $this->deliver($user, new PushMessage(
            $limit->leftTodayUzs > 0 ? __('bot.digest.morning_title', ['amount' => Fmt::uzs($limit->leftTodayUzs, $l)], $l) : __('bot.digest.morning_over', [], $l),
            $parts !== [] ? implode(' · ', $parts) : __('push.morning_free', ['amount' => Fmt::uzs(max(0, $limit->freeUzs), $l)], $l),
            '/', 'morning', 12 * 3600,
        ));
        if ($result === PushResult::Sent) {
            Channels::markSent([...$repeats, ...$insights], 'push');
        }

        return $result;
    }

    /** «Неделя: потрачено …» against the week before, as the bot's Sunday card. Null when both are empty. */
    public function weekly(User $user, Carbon $now): ?PushResult
    {
        $l = $user->locale;
        $end = $now->copy()->timezone(config('app.timezone'))->startOfDay()->addDay();
        $start = $end->copy()->subDays(7);
        $spent = fn (Carbon $from, Carbon $to) => (int) $user->transactions()->where('type', 'expense')
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->sum('amount_uzs');
        $thisWeek = $spent($start, $end);
        $lastWeek = $spent($start->copy()->subDays(7), $start);
        if ($thisWeek === 0 && $lastWeek === 0) {
            return null;
        }

        $diff = $thisWeek - $lastWeek;

        return $this->deliver($user, new PushMessage(
            __('bot.digest.weekly_title', ['amount' => Fmt::uzs($thisWeek, $l)], $l),
            match (true) {
                $diff < 0 => __('bot.digest.weekly_less', ['amount' => Fmt::uzs(-$diff, $l)], $l),
                $diff > 0 => __('bot.digest.weekly_more', ['amount' => Fmt::uzs($diff, $l)], $l),
                default => __('bot.digest.weekly_same', [], $l),
            },
            '/money', 'weekly', 2 * 86400,
        ));
    }

    /** «Итоги месяца: …» on the 1st: what was kept of the income; a tap opens the report. Null for an empty month. */
    public function monthly(User $user, Carbon $now): ?PushResult
    {
        $l = $user->locale;
        $month = $now->copy()->timezone(config('app.timezone'))->startOfMonth()->subMonthNoOverflow();
        $report = app(MonthReport::class)->for($user, $month->format('Y-m'), $now);
        if ($report['income_uzs'] === 0 && $report['expense_uzs'] === 0) {
            return null;
        }

        $saved = $report['saved_uzs'];
        $summary = $saved < 0
            ? __('bot.digest.monthly_deficit', ['amount' => Fmt::uzs(-$saved, $l)], $l)
            : __($report['rate'] === null ? 'bot.digest.monthly_saved_plain' : 'bot.digest.monthly_saved', ['amount' => Fmt::uzs($saved, $l), 'rate' => $report['rate']], $l);
        $prevRate = $report['prev']['rate'] ?? null;
        if ($saved >= 0 && $prevRate !== null) {
            $summary .= ' '.__('bot.digest.monthly_prev', ['rate' => $prevRate], $l);
        }

        return $this->deliver($user, new PushMessage(
            '📊 '.__('bot.digest.monthly_title', ['month' => Fmt::monthName($month, $l)], $l),
            $summary,
            '/money/report/'.$month->format('Y-m'), 'monthly', 3 * 86400,
        ));
    }

    /** «Уведомления работают», from Настройки, to check the whole way to the phone. */
    public function test(User $user): PushResult
    {
        return $this->deliver($user, new PushMessage(__('push.test_title', [], $user->locale), __('push.test_body', [], $user->locale), '/more', 'test', 600));
    }

    /**
     * To every phone of the user. A phone Firebase calls gone is forgotten on the spot. The result is the
     * best any phone got: one that took it is enough.
     */
    private function deliver(User $user, PushMessage $message): PushResult
    {
        $results = [];
        foreach ($user->deviceTokens()->get() as $device) {
            $result = $this->fcm->send($message, $device->token);
            if ($result === PushResult::Broken) {
                return $result;
            }
            if ($result === PushResult::Dead) {
                $device->delete();
            }
            $results[] = $result;
        }

        foreach ([PushResult::Sent, PushResult::Retry, PushResult::Rejected] as $best) {
            if (in_array($best, $results, true)) {
                return $best;
            }
        }

        return PushResult::Dead;
    }
}
