<?php

namespace App\Console\Commands;

use App\Telegram\BotUsers;
use App\Telegram\Handlers\Commands;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bot:digest {kind : morning, weekly or monthly}')]
#[Description('Send the morning summary with the night\'s advice, the weekly totals or last month\'s report')]
class BotDigest extends Command
{
    public function handle(TelegramClient $telegram, Cards $cards, Commands $commands): int
    {
        $kind = $this->argument('kind');
        if (! in_array($kind, ['morning', 'weekly', 'monthly'], true)) {
            $this->error('Kind must be morning, weekly or monthly.');

            return self::INVALID;
        }

        if ((string) config('telegram.bot_token') === '') {
            // Before the bot is set up (docs/BOT.md) there is nobody to write to.
            return self::SUCCESS;
        }

        $users = BotUsers::reachable()->filter(fn ($u) => $u->accounts()->whereNull('archived_at')->exists());

        foreach ($users as $user) {
            match ($kind) {
                'morning' => $commands->today($user->telegram_id, $user, morning: true),
                // A Sunday that is the 1st gets the month's report at the same hour instead: one card, not two.
                'weekly' => now()->timezone(config('app.timezone'))->day !== 1 && ($card = $cards->weekly($user)) ? $telegram->sendCard($user->telegram_id, $card, $cards->startKeyboard($user)) : null,
                'monthly' => ($card = $cards->monthly($user)) ? $telegram->sendCard($user->telegram_id, $card, $cards->reportKeyboard($user, now()->startOfMonth()->subMonthNoOverflow()->format('Y-m'))) : null,
            };
        }

        return self::SUCCESS;
    }
}
