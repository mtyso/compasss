<?php

namespace App\Telegram\Handlers;

use App\Models\Insight;
use App\Models\Transaction;
use App\Models\User;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;
use App\Telegram\Views\Fmt;
use Illuminate\Validation\ValidationException;

/**
 * The buttons under an insight the bot sent on its own (App\Telegram\Notifier): «Полезно»,
 * «Не присылать такое», «Разовая покупка» and «Отложить», the same answers the «Советы» feed takes.
 */
class InsightFlow
{
    public function __construct(private TelegramClient $telegram, private Cards $cards) {}

    public function onCallback(array $query, User $user): void
    {
        $l = $user->locale;
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);

        $insight = preg_match('/^ins:(useful|mute|oneoff|save):(\d+)$/', $query['data'] ?? '', $m)
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

            case 'save':
                $toast = $this->contribute($insight, $user);
                break;

            default: // oneoff
                if ($insight->action() === 'mark_one_off') {
                    $insight->markOneOff();
                }
                $toast = __('bot.insight.one_off_done', [], $l);
        }

        $this->telegram->answerCallbackQuery($query['id'], $toast);
        // Answered: only «Открыть Компас» stays under the text. A refused «Отложить» keeps its buttons.
        $this->telegram->editKeyboard($chatId, $messageId, $this->cards->insightKeyboard($insight, $user, answered: $insight->action() !== 'contribute'));
    }

    /**
     * «Отложить», as on the insight in the app: this period's goal contributions, or the money left
     * before an income into its goal. Returns the toast: the sum moved, or why nothing was.
     */
    public function contribute(Insight $insight, User $user): string
    {
        $l = $user->locale;
        if ($insight->action() !== 'contribute') {
            return __('bot.insight.contributed_before', [], $l);
        }

        try {
            $saved = $insight->contribute(now());
        } catch (ValidationException $e) {
            // No account to take it from, or the goal has no piggy bank: the app says the same.
            return (string) collect($e->errors())->flatten()->first();
        }

        $total = array_sum(array_map(fn (Transaction $t) => (int) $t->amount_uzs, $saved));

        return $total > 0
            ? __('bot.insight.contributed', ['amount' => Fmt::uzs($total, $l)], $l)
            : __('bot.insight.contributed_before', [], $l);
    }
}
