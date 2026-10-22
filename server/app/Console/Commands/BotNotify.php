<?php

namespace App\Console\Commands;

use App\Telegram\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bot:notify')]
#[Description('Send the advisor\'s new alerts, warnings and good news to Telegram, outside quiet hours')]
class BotNotify extends Command
{
    public function handle(Notifier $notifier): int
    {
        if ((string) config('telegram.bot_token') === '') {
            return self::SUCCESS;
        }

        $sent = $notifier->run(now());
        if ($sent > 0) {
            $this->info("Sent: {$sent}");
        }

        return self::SUCCESS;
    }
}
