<?php

namespace App\Console\Commands;

use App\Push\FcmClient;
use App\Push\PushNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('push:notify')]
#[Description('Push calendar reminders, and the advisor\'s new alerts, warnings and good news, to the phones that take them')]
class PushNotify extends Command
{
    public function handle(PushNotifier $push, FcmClient $fcm): int
    {
        if (! $fcm->configured()) {
            // Before Firebase is set up (docs/DEPLOY.md) the bot sends everything.
            return self::SUCCESS;
        }

        // Reminders first: the advisor's messages stop for the night, reminders do not.
        $sent = $push->remind(now()) + $push->run(now());
        if ($sent > 0) {
            $this->info("Sent: {$sent}");
        }

        return self::SUCCESS;
    }
}
