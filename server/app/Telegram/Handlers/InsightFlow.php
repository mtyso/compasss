<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;

/**
 * The buttons under an insight the bot sent on its own (App\Telegram\Notifier): «Полезно»,
 * «Не присылать такое» and «Разовая покупка», the same answers the «Советы» feed takes.
 */
class InsightFlow
{
    public function __construct(private TelegramClient $telegram, private Cards $cards) {}

    public function onCallback(array $query, User $user): void
    {
        $l = $user->locale;
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);

        $insight = preg_match('/^ins:(useful|mute|oneoff):(\d+)$/', $query['data'] ?? '', $m)
            ? $user->insights()->find((int) $m[2])
            : null;
        if (! $insight) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.record.gone', [], $l));

            return;
        }

        switch ($m[1]) {
            case 'useful':
                $insight->update(['status' => 'useful']);
                $toast = __('bot.insight.thanks', [], $l);
                break;

            case 'mute':
                $insight->mute();
                $toast = __('bot.insight.muted', [], $l);
                break;

            default: // oneoff
                if ($insight->action() === 'mark_one_off') {
                    $insight->markOneOff();
                }
                $toast = __('bot.insight.one_off_done', [], $l);
        }

        $this->telegram->answerCallbackQuery($query['id'], $toast);
        // Answered: only «Открыть Компас» stays under the text.
        $this->telegram->editKeyboard($chatId, $messageId, $this->cards->insightKeyboard($insight, $user, answered: true));
    }
}
