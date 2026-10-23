<?php

namespace App\Console\Commands;

use App\Push\FcmClient;
use App\Push\PushNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('push:digest {kind : morning, weekly or monthly}')]
#[Description('Push the morning summary, the weekly totals or last month\'s report to the phones that take them')]
class PushDigest extends Command
{
    public function handle(PushNotifier $push, FcmClient $fcm): int
    {
        $kind = $this->argument('kind');
        if (! in_array($kind, ['morning', 'weekly', 'monthly'], true)) {
            $this->error('Kind must be morning, weekly or monthly.');

            return self::INVALID;
        }
        if (! $fcm->configured()) {
            return self::SUCCESS;
        }

        $now = now();
        foreach ($push->users()->filter(fn ($u) => $u->accounts()->whereNull('archived_at')->exists()) as $user) {
            match ($kind) {
                'morning' => $push->morning($user, $now),
                // A Sunday that is the 1st gets the month's report at the same hour instead, as in the bot.
                'weekly' => $now->copy()->timezone(config('app.timezone'))->day !== 1 ? $push->weekly($user, $now) : null,
                'monthly' => $push->monthly($user, $now),
            };
        }

        return self::SUCCESS;
    }
}
