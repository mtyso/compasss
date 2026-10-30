<?php

namespace App\Telegram;

use App\Models\User;
use App\Telegram\Handlers\Commands;
use App\Telegram\Handlers\InsightFlow;
use App\Telegram\Handlers\LoginFlow;
use App\Telegram\Handlers\PrayerFlow;
use App\Telegram\Handlers\RecordFlow;
use App\Telegram\Handlers\StreaksFlow;
use App\Telegram\Handlers\ThingsFlow;

/**
 * Routes one webhook update (docs/BOT.md): sign-in links and buttons, commands,
 * phrases that become operations, and the buttons under the bot's cards.
 */
class UpdateHandler
{
    public function __construct(
        private TelegramClient $telegram,
        private LoginFlow $login,
        private RecordFlow $records,
        private Commands $commands,
        private InsightFlow $insights,
        private ThingsFlow $things,
        private StreaksFlow $streaks,
        private PrayerFlow $prayers,
    ) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->onCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;
        if (! isset($message['text'], $message['from']['id'], $message['chat']['id'])
            || ($message['chat']['type'] ?? 'private') !== 'private') {
            return;
        }

        $from = $message['from'];
        $chatId = (int) $message['chat']['id'];
        $locale = BotUsers::localeFor($from);

        if (! BotUsers::isAllowed($from['id'])) {
            $this->telegram->sendMessage($chatId, __('bot.private', [], $locale));

            return;
        }

        $text = trim($message['text']);

        if (preg_match('/^\/start(?:@\w+)?\s+login_([A-Za-z0-9]{32})$/', $text, $m)) {
            $this->login->onLink($chatId, $from, $m[1], $locale);

            return;
        }

        $user = $this->userFor($from);

        if (preg_match('/^\/(namaz|prayer)(?:@\w+)?(?:\s|$)/i', $text)) {
            $this->prayers->day($chatId, $user);

            return;
        }

        if (preg_match('/^\/([a-z_]+)(?:@\w+)?(?:\s|$)/i', $text, $m)) {
            $this->commands->handle(strtolower($m[1]), $chatId, $user);

            return;
        }

        $this->records->onText($chatId, $user, $text);
    }

    private function onCallback(array $query): void
    {
        $data = $query['data'] ?? '';
        if (str_starts_with($data, 'login:')) {
            $this->login->onCallback($query);

            return;
        }

        $from = $query['from'] ?? [];
        if (! isset($from['id'], $query['message']['chat']['id']) || ! BotUsers::isAllowed($from['id'])) {
            $this->telegram->answerCallbackQuery($query['id']);

            return;
        }

        $user = $this->userFor($from);

        match (true) {
            str_starts_with($data, 'tx:') => $this->records->onCallback($query, $user),
            str_starts_with($data, 'buy:') => $this->records->onBuy($query, $user),
            str_starts_with($data, 'ins:') => $this->insights->onCallback($query, $user),
            str_starts_with($data, 'pay:') => $this->commands->onPay($query, $user),
            str_starts_with($data, 'task:'), str_starts_with($data, 'habit:') => $this->things->onCallback($query, $user),
            str_starts_with($data, 'streak:') => $this->streaks->onCallback($query, $user),
            str_starts_with($data, 'prayer:') => $this->prayers->onCallback($query, $user),
            default => $this->telegram->answerCallbackQuery($query['id']),
        };
    }

    /** The owner may write to the bot before ever signing in to the app: the account is made then. */
    private function userFor(array $from): User
    {
        return User::firstWhere('telegram_id', (int) $from['id']) ?? BotUsers::upsert($from);
    }
}
