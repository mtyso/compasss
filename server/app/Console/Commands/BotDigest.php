<?php

namespace App\Console\Commands;

use App\Telegram\BotUsers;
use App\Telegram\Handlers\Commands;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bot:digest {kind : morning or weekly}')]
#[Description('Send the morning summary with the night\'s advice, or the weekly totals')]
class BotDigest extends Command
{
    public function handle(TelegramClient $telegram, Cards $cards, Commands $commands): int
    {
        $kind = $this->argument('kind');
        if (! in_array($kind, ['morning', 'weekly'], true)) {
            $this->error('Kind must be morning or weekly.');

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
                'weekly' => ($card = $cards->weekly($user)) ? $telegram->sendCard($user->telegram_id, $card, $cards->startKeyboard($user)) : null,
            };
        }

        return self::SUCCESS;
    }
}
