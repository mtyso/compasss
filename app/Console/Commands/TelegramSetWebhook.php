<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:set-webhook')]
#[Description('Point the bot at APP_URL/telegram/webhook with TELEGRAM_WEBHOOK_SECRET')]
class TelegramSetWebhook extends Command
{
    public function handle(TelegramClient $telegram): int
    {
        $url = rtrim((string) config('app.url'), '/').'/telegram/webhook';

        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram needs an https address, APP_URL gives {$url}. Run this on the production server.");

            return self::FAILURE;
        }
        if (! config('telegram.webhook_secret')) {
            $this->error('TELEGRAM_WEBHOOK_SECRET is empty.');

            return self::FAILURE;
        }

        $result = $telegram->call('setWebhook', [
            'url' => $url,
            'secret_token' => config('telegram.webhook_secret'),
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        if (! ($result['ok'] ?? false)) {
            $this->error('Telegram refused: '.($result['description'] ?? 'no answer'));

            return self::FAILURE;
        }

        $this->info("Webhook set to {$url}");

        return self::SUCCESS;
    }
}
