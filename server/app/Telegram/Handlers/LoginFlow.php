<?php

namespace App\Telegram\Handlers;

use App\Models\LoginRequest;
use App\Telegram\BotUsers;
use App\Telegram\TelegramClient;

/** Confirming an app or web sign-in in the bot (docs/PLAN.md, section 6). */
class LoginFlow
{
    public function __construct(private TelegramClient $telegram) {}

    public function onLink(int $chatId, array $from, string $code, string $locale): void
    {
        $login = LoginRequest::findByCode($code);

        if (! $login || ! $login->isOpen() || ($login->telegram_id && $login->telegram_id !== (int) $from['id'])) {
            $this->telegram->sendMessage($chatId, __('bot.login.expired', [], $locale));

            return;
        }

        // Remember who opened the link: only this account can press the buttons below.
        $login->update(['telegram_id' => (int) $from['id']]);

        // A confirmation step instead of approving on /start: a link that someone
        // else generated and sent to the owner must not sign that person in.
        $this->telegram->sendMessage($chatId, __('bot.login.confirm', ['device' => $login->device_name], $locale), [[
            ['text' => __('bot.login.approve_button', [], $locale), 'callback_data' => "login:approve:{$login->id}"],
            ['text' => __('bot.login.deny_button', [], $locale), 'callback_data' => "login:deny:{$login->id}"],
        ]]);
    }

    public function onCallback(array $query): void
    {
        $from = $query['from'] ?? [];
        $locale = BotUsers::localeFor($from);
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);

        if (! preg_match('/^login:(approve|deny):([0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26})$/', $query['data'] ?? '', $m)
            || ! isset($from['id']) || ! BotUsers::isAllowed($from['id'])) {
            $this->telegram->answerCallbackQuery($query['id']);

            return;
        }

        $login = LoginRequest::find($m[2]);

        if ($login && in_array($login->status, ['approved', 'used'], true)) {
            // A second tap on a button that already worked.
            $this->telegram->answerCallbackQuery($query['id']);

            return;
        }

        if (! $login || ! $login->isOpen() || $login->telegram_id !== (int) $from['id']) {
            $this->telegram->answerCallbackQuery($query['id'], __('bot.login.expired_short', [], $locale));
            $this->telegram->editMessageText($chatId, $messageId, __('bot.login.expired', [], $locale));

            return;
        }

        if ($m[1] === 'deny') {
            $login->update(['status' => 'denied']);
            $this->telegram->answerCallbackQuery($query['id']);
            $this->telegram->editMessageText($chatId, $messageId, __('bot.login.denied', [], $locale));

            return;
        }

        $user = BotUsers::upsert($from);
        $login->update(['status' => 'approved', 'user_id' => $user->id, 'approved_at' => now()]);

        $this->telegram->answerCallbackQuery($query['id']);
        $this->telegram->editMessageText($chatId, $messageId, __('bot.login.approved', [], $user->locale));
    }
}
