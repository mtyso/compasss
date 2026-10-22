<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:webhook-info')]
#[Description('Show where the bot webhook points and the last delivery error')]
class TelegramWebhookInfo extends Command
{
    public function handle(TelegramClient $telegram): int
    {
        $info = $telegram->call('getWebhookInfo')['result'] ?? null;
        if (! $info) {
            $this->error('No answer from Telegram.');

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['url', $info['url'] ?: '(not set)'],
            ['pending updates', $info['pending_update_count'] ?? 0],
            ['last error', isset($info['last_error_date'])
                ? date('d.m.Y H:i', $info['last_error_date']).' '.$info['last_error_message']
                : '-'],
        ]);

        return self::SUCCESS;
    }
}
