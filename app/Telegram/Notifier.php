<?php

namespace App\Telegram;

use App\Calendar\Reminders;
use App\Models\Insight;
use App\Models\Record;
use App\Models\User;
use App\Telegram\Views\Cards;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The advisor's insights in the chat (docs/BOT.md). Alerts, warnings and good news go out on their own
 * within a minute, outside quiet hours; the rest waits for the morning summary or stays in the app.
 * An insight shown in the chat in any way gets 'telegram' in `sent_via` and is never sent again.
 * Calendar reminders go out here too (remind()), at the time the user chose, and so do new milestones and
 * records of the journal, and the 21:00 message with the streaks that end tonight (evening()).
 */
class Notifier
{
    /** Pushed on their own, besides the evening «nothing recorded today». */
    public const PUSH_SEVERITIES = ['alert', 'warn', 'good'];

    /** At most this many separate messages per user a minute: the rest come a minute later. */
    private const PER_RUN = 3;

    /** A journal line dated earlier than yesterday is not news (docs/PLAN.md, D39). */
    private const RECORD_NEWS_DAYS = 1;

    public function __construct(private TelegramClient $telegram, private Cards $cards, private Reminders $reminders) {}

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

    /**
     * New lines of the records journal to tell about, oldest first. One dated before yesterday is the journal
     * filling in what came before it (its first run, a month judged late): it is marked told without a message.
     *
     * @return Collection<int, Record>
     */
    public static function newRecords(User $user, Carbon $now): Collection
    {
        $since = $now->copy()->subDays(self::RECORD_NEWS_DAYS)->toDateString();
        $user->records()->whereNull('notified_at')->where('date', '<', $since)->update(['notified_at' => $now]);

        return $user->records()->whereNull('notified_at')->orderBy('date')->orderBy('id')->get();
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
        foreach (BotUsers::reachable() as $user) {
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
        foreach (BotUsers::reachable() as $user) {
            $due = self::unsent($user, $now)->filter(fn (Insight $i) => self::pushes($i))->take(self::PER_RUN);

            foreach ($due as $insight) {
                // «Ничего не записано» brings the streaks that end tonight, as the 21:00 message does.
                [$card, $keyboard] = $insight->rule === 'forgot_to_record'
                    ? [$this->cards->evening($user, $now, $insight), $this->cards->eveningKeyboard($user, $now, $insight)]
                    : [$this->cards->insight($insight, $user), $this->cards->insightKeyboard($insight, $user)];
                $result = $this->telegram->sendCard($user->telegram_id, $card, $keyboard);

                // Telegram said no (blocked bot, bad markup): retrying every minute would not help.
                // No answer at all: try again next minute.
                if (($result['ok'] ?? false) || isset($result['error_code'])) {
                    self::markSent([$insight]);
                }
                $sent += ($result['ok'] ?? false) ? 1 : 0;
            }

            $sent += $this->records($user, $now);
        }

        return $sent;
    }

    /**
     * 21:00, right after advisor:run evening (bot:digest evening): «Сегодня ничего не записано» and the
     * streaks that end tonight in one message, or either alone. The question is then sent, and run() leaves it.
     *
     * @return bool whether a message went out
     */
    public function evening(User $user, Carbon $now): bool
    {
        if (self::isQuiet($now)) {
            return false;
        }
        $forgot = self::unsent($user, $now)->first(fn (Insight $i) => $i->rule === 'forgot_to_record');
        $card = $this->cards->evening($user, $now, $forgot);
        if ($card === null) {
            return false;
        }

        $result = $this->telegram->sendCard($user->telegram_id, $card, $this->cards->eveningKeyboard($user, $now, $forgot));
        if ($forgot !== null && (($result['ok'] ?? false) || isset($result['error_code']))) {
            self::markSent([$forgot]);
        }

        return (bool) ($result['ok'] ?? false);
    }

    /** New milestones and records as one short message (docs/PLAN.md, D39); quiet hours hold them till morning. */
    private function records(User $user, Carbon $now): int
    {
        $records = self::newRecords($user, $now);
        if ($records->isEmpty()) {
            return 0;
        }

        $result = $this->telegram->sendCard($user->telegram_id, $this->cards->journal($records, $user), $this->cards->journalKeyboard($records, $user));
        // As with insights: a refusal would be refused again, no answer at all is worth another try.
        if (($result['ok'] ?? false) || isset($result['error_code'])) {
            $user->records()->whereKey($records->modelKeys())->update(['notified_at' => $now]);
        }

        return ($result['ok'] ?? false) ? 1 : 0;
    }
}
