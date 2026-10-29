<?php

namespace App\Telegram;

use App\Telegram\Rich\Card;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
    public function call(string $method, array $params = [], int $timeout = 10): array
    {
        return $this->send($method, fn (string $url) => Http::asJson()->timeout($timeout)->post($url, $params));
    }

    /**
     * A call that uploads files as multipart/form-data, e.g. the bot's profile photo.
     * Object parameters go as JSON and point at a file with attach://<name>.
     *
     * @param  array<string, string>  $files  attach name => path
     * @return array<string, mixed>
     */
    public function upload(string $method, array $params, array $files, int $timeout = 30): array
    {
        return $this->send($method, function (string $url) use ($params, $files, $timeout) {
            $request = Http::asMultipart()->timeout($timeout);
            foreach ($files as $name => $path) {
                $request = $request->attach($name, (string) file_get_contents($path), basename($path));
            }

            return $request->post($url, array_map(
                fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $value,
                $params,
            ));
        });
    }

    /**
     * @param  callable(string): Response  $request  sends the call to the given method URL
     * @return array<string, mixed>
     */
    private function send(string $method, callable $request): array
    {
        $token = (string) config('telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not set.');
        }

        try {
            $response = $request("https://api.telegram.org/bot{$token}/{$method}");
        } catch (ConnectionException $e) {
            // The exception message contains the request URL, and with it the bot token.
            Log::warning("Telegram {$method} failed: ".str_replace($token, '***', $e->getMessage()));

            return ['ok' => false];
        }

        $body = $response->json() ?? ['ok' => false];
        if (! ($body['ok'] ?? false) && ! self::notModified($body)) {
            Log::warning("Telegram {$method} answered {$response->status()}: ".($body['description'] ?? 'no description'));
        }

        return $body;
    }

    /**
     * Long polling for local tests (telegram:poll): waits up to $wait seconds for new updates.
     * On the server the webhook replaces it, and Telegram refuses this call while one is set.
     */
    public function getUpdates(int $offset, int $wait): array
    {
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $wait,
            'allowed_updates' => ['message', 'callback_query'],
        ], $wait + 10);
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

    /**
     * Sends a card as a rich message, or as an HTML message when rich messages are off
     * or Telegram refuses this one. Only a refusal falls back: a timeout may have delivered it.
     */
    public function sendCard(int $chatId, Card $card, ?Keyboard $keyboard = null): array
    {
        $markup = $keyboard?->toMarkup();

        if (config('telegram.rich_messages')) {
            $result = $this->call('sendRichMessage', array_filter([
                'chat_id' => $chatId,
                'rich_message' => ['html' => $card->rich(), 'skip_entity_detection' => true],
                'reply_markup' => $markup,
            ]));
            if (! self::refused($result)) {
                return $result;
            }
        }

        return $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $card->legacy(),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $markup,
        ]));
    }

    /** Replaces a card in place, e.g. after the category was changed with a button. */
    public function editCard(int $chatId, int $messageId, Card $card, ?Keyboard $keyboard = null): array
    {
        $base = ['chat_id' => $chatId, 'message_id' => $messageId];
        // An empty keyboard removes the buttons of the old message.
        $markup = $keyboard?->toMarkup() ?? ['inline_keyboard' => []];

        if (config('telegram.rich_messages')) {
            $result = $this->call('editMessageText', $base + [
                'rich_message' => ['html' => $card->rich(), 'skip_entity_detection' => true],
                'reply_markup' => $markup,
            ]);
            if (! self::refused($result) || self::notModified($result)) {
                return $result;
            }
        }

        return $this->call('editMessageText', $base + [
            'text' => $card->legacy(),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $markup,
        ]);
    }

    /** Swaps only the buttons, e.g. to show the category picker under a card. */
    public function editKeyboard(int $chatId, int $messageId, Keyboard $keyboard): array
    {
        return $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $keyboard->toMarkup() ?? ['inline_keyboard' => []],
        ]);
    }

    /** Telegram answered and said no (bad markup, unknown method), as opposed to not answering. */
    private static function refused(array $result): bool
    {
        return ! ($result['ok'] ?? false) && in_array($result['error_code'] ?? null, [400, 404], true);
    }

    /** Editing a message into exactly what it already shows: harmless, not an error. */
    private static function notModified(array $result): bool
    {
        return str_contains($result['description'] ?? '', 'message is not modified');
    }
}
