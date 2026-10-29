<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Telegram\Keyboard;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;
use App\Telegram\Views\Fmt;
use Illuminate\Support\Carbon;

/**
 * The buttons under the evening message (Cards::evening): «Трат не было» and a mark for a habit whose streak
 * ends tonight. Each is for the day written in it, so a tap after midnight still counts for that evening.
 */
class StreaksFlow
{
    /** As DayController: «Трат не было» a week back at most. A habit only for the evening before. */
    private const BACK_DAYS = ['nospend' => 7, 'habit' => 1];

    public function __construct(private TelegramClient $telegram, private Cards $cards, private ThingsFlow $things) {}

    public function onCallback(array $query, User $user): void
    {
        $l = $user->locale;
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);
        $today = now()->startOfDay();

        $day = preg_match('/^streak:(nospend|habit):(?:(\d+):)?(\d{4}-\d{2}-\d{2})$/', $query['data'] ?? '', $m)
            ? Carbon::createFromFormat('!Y-m-d', $m[3])
            : null;
        if (! $day || $day->toDateString() !== $m[3] || $day->gt($today) || $day->lt($today->copy()->subDays(self::BACK_DAYS[$m[1]]))) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.evening.too_late', [], $l));

            return;
        }

        $forgot = $user->insights()->where('rule', 'forgot_to_record')->where('period_key', $m[3])->latest('id')->first();
        if ($m[1] === 'nospend') {
            // As «Трат не было» on the question in the app: the day counts, the question is answered.
            $forgot !== null
                ? $forgot->noSpending()
                : $user->dayResults()->updateOrCreate(['date' => $m[3]], ['no_spending' => true]);
            $toast = $day->eq($today)
                ? __('bot.evening.no_spending_done', [], $l)
                : __('bot.evening.no_spending_day', ['date' => Fmt::date($day, $l)], $l);
        } else {
            $toast = $this->things->logHabit($user, (int) $m[2], $day);
        }

        if ($toast === null) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.record.gone', [], $l));

            return;
        }

        $this->telegram->answerCallbackQuery($query['id'], $toast);
        // What is still open keeps its button; after midnight that evening is over and only the app is left.
        $this->telegram->editKeyboard($chatId, $messageId, $day->eq($today)
            ? $this->cards->eveningKeyboard($user, now(), $forgot?->refresh())
            : Keyboard::make()->row([Keyboard::webApp('🧭 '.__('bot.buttons.open_app', [], $l), '/')]));
    }
}
