<?php

namespace App\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin wrapper over the Bot API. Failures are logged, not thrown, so one
 * undeliverable reply never turns a webhook call into a Telegram retry loop.
 */
class TelegramClient
{
    /** @return array<string, mixed> */
    public function call(string $method, array $params = []): array
    {
        $token = (string) config('telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not set.');
        }

        try {
            $response = Http::asJson()->timeout(10)->post("https://api.telegram.org/bot{$token}/{$method}", $params);
        } catch (ConnectionException $e) {
            // The exception message contains the request URL, and with it the bot token.
            Log::warning("Telegram {$method} failed: ".str_replace($token, '***', $e->getMessage()));

            return ['ok' => false];
        }

        $body = $response->json() ?? ['ok' => false];
        if (! ($body['ok'] ?? false)) {
            Log::warning("Telegram {$method} answered {$response->status()}: ".($body['description'] ?? 'no description'));
        }

        return $body;
    }

    /** @param  list<list<array{text: string, callback_data: string}>>|null  $buttons */
    public function sendMessage(int $chatId, string $text, ?array $buttons = null): array
    {
        return $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $buttons ? ['inline_keyboard' => $buttons] : null,
        ]));
    }

    public function editMessageText(int $chatId, int $messageId, string $text): array
    {
        return $this->call('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text]);
    }

    public function answerCallbackQuery(string $id, ?string $text = null): array
    {
        return $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => $text]));
    }
}
