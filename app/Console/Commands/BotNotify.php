<?php

namespace App\Console\Commands;

use App\Telegram\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bot:notify')]
#[Description('Send calendar reminders, and the advisor\'s new alerts, warnings and good news outside quiet hours')]
class BotNotify extends Command
{
    public function handle(Notifier $notifier): int
    {
        if ((string) config('telegram.bot_token') === '') {
            return self::SUCCESS;
        }

        // Reminders first: the advisor's messages stop for the night, reminders do not.
        $sent = $notifier->remind(now()) + $notifier->run(now());
        if ($sent > 0) {
            $this->info("Sent: {$sent}");
        }

        return self::SUCCESS;
    }
}
