<?php

namespace App\Console\Commands;

use App\Telegram\BotUsers;
use App\Telegram\Handlers\Commands;
use App\Telegram\Notifier;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bot:digest {kind : morning, evening, weekly or monthly}')]
#[Description('Send the morning summary with the night\'s advice, the evening streaks, the weekly totals or last month\'s report')]
class BotDigest extends Command
{
    public function handle(TelegramClient $telegram, Cards $cards, Commands $commands, Notifier $notifier): int
    {
        $kind = $this->argument('kind');
        if (! in_array($kind, ['morning', 'evening', 'weekly', 'monthly'], true)) {
            $this->error('Kind must be morning, evening, weekly or monthly.');

            return self::INVALID;
        }

        if ((string) config('telegram.bot_token') === '') {
            // Before the bot is set up (docs/BOT.md) there is nobody to write to.
            return self::SUCCESS;
        }

        // Habits need no account: the evening is for everyone the bot can reach.
        $users = BotUsers::reachable()->filter(fn ($u) => $kind === 'evening' || $u->accounts()->whereNull('archived_at')->exists());

        foreach ($users as $user) {
            match ($kind) {
                'morning' => $commands->today($user->telegram_id, $user, morning: true),
                'evening' => $notifier->evening($user, now()),
                // A Sunday that is the 1st gets the month's report at the same hour instead: one card, not two.
                'weekly' => now()->timezone(config('app.timezone'))->day !== 1 && ($card = $cards->weekly($user)) ? $telegram->sendCard($user->telegram_id, $card, $cards->startKeyboard($user)) : null,
                'monthly' => ($card = $cards->monthly($user)) ? $telegram->sendCard($user->telegram_id, $card, $cards->reportKeyboard($user, now()->startOfMonth()->subMonthNoOverflow()->format('Y-m'))) : null,
            };
        }

        return self::SUCCESS;
    }
}
