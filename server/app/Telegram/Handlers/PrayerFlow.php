<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Prayer\Prayers;
use App\Prayer\PrayerTimes;
use App\Streaks\RecordKeeper;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;
use Throwable;

/**
 * «Прочитал» (docs/PLAN.md, D40): under a prayer reminder (`prayer:r:…`), in /today (`prayer:t:…`) and in
 * /namaz (`prayer:d:…`). Marks the prayer as the app does, in its time or as qaza, then redraws the card.
 */
class PrayerFlow
{
    public function __construct(private TelegramClient $telegram, private Cards $cards, private Prayers $prayers, private RecordKeeper $keeper) {}

    public function onCallback(array $query, User $user): void
    {
        $l = $user->locale;
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);
        $names = implode('|', PrayerTimes::NAMES);
        if (! preg_match("/^prayer:([rtd]):(\\d{4}-\\d{2}-\\d{2}):({$names})$/", $query['data'] ?? '', $m)) {
            $this->telegram->answerCallbackQuery($query['id']);

            return;
        }
        [, $from, $date, $prayer] = $m;
        $name = __("prayer.names.{$prayer}", [], $l);
        $now = now();

        // A button from a card left in the chat for long: the app can mark a prayer that old, so can the bot.
        $oldest = $now->copy()->startOfDay()->subDays(Prayers::MISSED_DAYS)->toDateString();
        if ($date < $oldest || $date > $now->toDateString()) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.record.gone', [], $l));

            return;
        }
        if ($now->lt(Prayers::windows($user, $date)[$prayer]['start'])) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.prayer.too_early', [], $l));

            return;
        }

        $marked = $this->prayers->marks($user, $date)[$prayer] ?? null;
        if ($marked !== null) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.prayer.already', ['name' => $name], $l));
        } else {
            $marked = Prayers::statusAt($user, $date, $prayer, $now);
            $this->prayers->mark($user, $date, $prayer, $marked);
            try {
                $this->keeper->prayer($user, $now);
            } catch (Throwable $e) {
                report($e);
            }
            $this->telegram->answerCallbackQuery($query['id'], __($marked === 'qaza' ? 'bot.prayer.marked_qaza' : 'bot.prayer.marked', ['name' => $name], $l));
        }

        match ($from) {
            'r' => $this->telegram->editCard($chatId, $messageId, $this->cards->prayerDone($user, $prayer, $marked, $now), $this->cards->prayerDoneKeyboard($user)),
            't' => $this->telegram->editCard($chatId, $messageId, $this->cards->today($user), $this->cards->todayKeyboard($user)),
            'd' => $this->telegram->editCard($chatId, $messageId, $this->cards->prayerDay($user, $now), $this->cards->prayerDayKeyboard($user, $now)),
        };
    }

    public function day(int $chatId, User $user): void
    {
        $this->telegram->sendCard($chatId, $this->cards->prayerDay($user), $this->cards->prayerDayKeyboard($user));
    }
}
