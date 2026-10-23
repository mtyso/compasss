<?php

namespace App\Telegram;

use App\Calendar\Reminders;
use App\Models\Insight;
use App\Models\User;
use App\Notify\Channels;
use App\Telegram\Views\Cards;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The advisor's insights in the chat (docs/BOT.md). Alerts, warnings and good news go out on their own
 * within a minute, outside quiet hours; the rest waits for the morning summary or stays in the app.
 * An insight shown in the chat in any way gets 'telegram' in `sent_via` and is never sent again.
 * Calendar reminders go out here too (remind()), at the time the user chose. A user whose notifications
 * go to the phone instead (App\Notify\Channels, D31) gets none of it here.
 */
class Notifier
{
    /** Pushed on their own, besides the evening «nothing recorded today». */
    public const PUSH_SEVERITIES = ['alert', 'warn', 'good'];

    /** At most this many separate messages per user a minute: the rest come a minute later. */
    private const PER_RUN = 3;

    public function __construct(private TelegramClient $telegram, private Cards $cards, private Reminders $reminders, private Channels $channels) {}

    /**
     * New insights of the last day not shown in the chat yet, most important first. With notifications
     * going one way only, an insight the phone already showed is not repeated here either.
     *
     * @return Collection<int, Insight>
     */
    public static function unsent(User $user, Carbon $now): Collection
    {
        return app(Channels::class)->unsent($user, $now, 'telegram');
    }

    /** @param  iterable<Insight>  $insights */
    public static function markSent(iterable $insights): void
    {
        Channels::markSent($insights, 'telegram');
    }

    /** Besides those severities: the evening «nothing recorded today» and every payment reminder. */
    public static function pushes(Insight $insight): bool
    {
        return in_array($insight->severity, self::PUSH_SEVERITIES, true) || in_array($insight->rule, ['forgot_to_record', 'payment_due', 'lent_due'], true);
    }

    /** From the evening (22:00 by default) until the morning summary. */
    public static function isQuiet(Carbon $now): bool
    {
        $time = $now->copy()->timezone(config('app.timezone'))->format('H:i');
        $from = (string) config('telegram.digest.quiet_from');
        $until = (string) config('telegram.digest.morning_at');

        return $from > $until
            ? $time >= $from || $time < $until
            : $time >= $from && $time < $until;
    }

    /**
     * Calendar reminders due now (App\Calendar\Reminders). The user picked the time, so quiet hours and
     * the per-run cap do not hold them back. Each is claimed before it is sent, so an overlapping run
     * cannot send it twice; one Telegram did not answer is given back for the next minute.
     *
     * @return int how many messages went out
     */
    public function remind(Carbon $now): int
    {
        $sent = 0;
        foreach ($this->users() as $user) {
            foreach ($this->reminders->due($user, $now) as $reminder) {
                if (! $this->reminders->claim($user, $reminder['key'], $now)) {
                    continue;
                }
                $result = $this->telegram->sendCard($user->telegram_id, $this->cards->reminder($reminder, $user, $now), $this->cards->reminderKeyboard($reminder, $user));

                // As in run(): a refusal would be refused again, no answer at all is worth another try.
                if (! ($result['ok'] ?? false) && ! isset($result['error_code'])) {
                    $this->reminders->release($user, $reminder['key']);
                }
                $sent += ($result['ok'] ?? false) ? 1 : 0;
            }
        }

        return $sent;
    }

    /** @return int how many messages went out */
    public function run(Carbon $now): int
    {
        if (self::isQuiet($now)) {
            return 0;
        }

        $sent = 0;
        foreach ($this->users() as $user) {
            $due = self::unsent($user, $now)->filter(fn (Insight $i) => self::pushes($i))->take(self::PER_RUN);

            foreach ($due as $insight) {
                $result = $this->telegram->sendCard($user->telegram_id, $this->cards->insight($insight, $user), $this->cards->insightKeyboard($insight, $user));

                // Telegram said no (blocked bot, bad markup): retrying every minute would not help.
                // No answer at all: try again next minute.
                if (($result['ok'] ?? false) || isset($result['error_code'])) {
                    self::markSent([$insight]);
                }
                $sent += ($result['ok'] ?? false) ? 1 : 0;
            }
        }

        return $sent;
    }

    /** @return Collection<int, User> those the bot can write to whose notifications come here */
    private function users(): Collection
    {
        return BotUsers::reachable()->filter(fn (User $user) => $this->channels->wants($user, 'telegram'))->values();
    }
}
