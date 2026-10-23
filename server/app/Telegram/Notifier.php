<?php

namespace App\Telegram;

use App\Models\Insight;
use App\Models\User;
use App\Telegram\Views\Cards;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The advisor's insights in the chat (docs/BOT.md). Alerts, warnings and good news go out on their own
 * within a minute, outside quiet hours; the rest waits for the morning summary or stays in the app.
 * An insight shown in the chat in any way gets 'telegram' in `sent_via` and is never sent again.
 */
class Notifier
{
    /** Pushed on their own, besides the evening «nothing recorded today». */
    public const PUSH_SEVERITIES = ['alert', 'warn', 'good'];

    /** At most this many separate messages per user a minute: the rest come a minute later. */
    private const PER_RUN = 3;

    public function __construct(private TelegramClient $telegram, private Cards $cards) {}

    /**
     * New insights of the last day not shown in the chat yet, most important first.
     *
     * @return Collection<int, Insight>
     */
    public static function unsent(User $user, Carbon $now): Collection
    {
        $rank = array_flip(Insight::SEVERITIES);

        // A handful of rows a day: filtering `sent_via` here keeps the query the same on SQLite and MariaDB.
        return $user->insights()
            ->where('status', 'new')
            ->where('created_at', '>=', $now->copy()->subDay())
            ->get()
            ->reject(fn (Insight $i) => in_array('telegram', (array) $i->sent_via, true))
            ->sortBy(fn (Insight $i) => [$rank[$i->severity] ?? 9, $i->id])
            ->values();
    }

    /** @param  iterable<Insight>  $insights */
    public static function markSent(iterable $insights): void
    {
        foreach ($insights as $insight) {
            $insight->update(['sent_via' => array_values(array_unique([...(array) $insight->sent_via, 'telegram']))]);
        }
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

    /** @return int how many messages went out */
    public function run(Carbon $now): int
    {
        if (self::isQuiet($now)) {
            return 0;
        }

        $sent = 0;
        foreach (BotUsers::reachable() as $user) {
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
}
