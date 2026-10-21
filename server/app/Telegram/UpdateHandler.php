<?php

namespace App\Telegram;

use App\Models\LoginRequest;
use App\Models\User;

/**
 * Handles one webhook update. Step 1.2 covers sign-in only; expense phrases and
 * commands arrive in step 1.7 (docs/PLAN.md, section 9).
 */
class UpdateHandler
{
    public function __construct(private TelegramClient $telegram) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->onCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;
        if (! isset($message['text'], $message['from']['id'], $message['chat']['id'])) {
            return;
        }

        $from = $message['from'];
        $chatId = (int) $message['chat']['id'];
        $locale = $this->localeFor($from);

        if (! $this->isAllowed($from['id'])) {
            $this->telegram->sendMessage($chatId, __('bot.private', [], $locale));

            return;
        }

        $text = trim($message['text']);

        if (preg_match('/^\/start(?:@\w+)?\s+login_([A-Za-z0-9]{32})$/', $text, $m)) {
            $this->onLoginLink($chatId, $from, $m[1], $locale);

            return;
        }

        $this->telegram->sendMessage($chatId, __(str_starts_with($text, '/start') ? 'bot.welcome' : 'bot.not_yet', [], $locale));
    }

    private function onLoginLink(int $chatId, array $from, string $code, string $locale): void
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

    private function onCallback(array $query): void
    {
        $from = $query['from'] ?? [];
        $locale = $this->localeFor($from);
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $messageId = (int) ($query['message']['message_id'] ?? 0);

        if (! preg_match('/^login:(approve|deny):([0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26})$/', $query['data'] ?? '', $m)
            || ! isset($from['id']) || ! $this->isAllowed($from['id'])) {
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

        $user = $this->upsertUser($from);
        $login->update(['status' => 'approved', 'user_id' => $user->id, 'approved_at' => now()]);

        $this->telegram->answerCallbackQuery($query['id']);
        $this->telegram->editMessageText($chatId, $messageId, __('bot.login.approved', [], $user->locale));
    }

    private function upsertUser(array $from): User
    {
        $user = User::firstOrNew(['telegram_id' => (int) $from['id']]);

        $user->telegram_username = $from['username'] ?? null;
        if (! $user->exists) {
            $user->name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: ($from['username'] ?? 'Owner');
            $user->locale = $this->localeFor($from);
        }
        $user->save();

        return $user;
    }

    private function isAllowed(int|string $telegramId): bool
    {
        return in_array((string) $telegramId, config('telegram.allowed_ids'), true);
    }

    private function localeFor(array $from): string
    {
        $known = isset($from['id']) ? User::where('telegram_id', (int) $from['id'])->value('locale') : null;

        return $known ?? (str_starts_with($from['language_code'] ?? '', 'uz') ? 'uz' : 'ru');
    }
}
