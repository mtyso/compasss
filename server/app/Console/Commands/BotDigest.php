<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Telegram\BotUsers;
use App\Telegram\Handlers\Commands;
use App\Telegram\Keyboard;
use App\Telegram\TelegramClient;
use App\Telegram\Views\Cards;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bot:digest {kind : morning, evening or weekly}')]
#[Description('Send the morning summary, the evening «nothing recorded» reminder or the weekly totals')]
class BotDigest extends Command
{
    public function handle(TelegramClient $telegram, Cards $cards, Commands $commands): int
    {
        $kind = $this->argument('kind');
        if (! in_array($kind, ['morning', 'evening', 'weekly'], true)) {
            $this->error('Kind must be morning, evening or weekly.');

            return self::INVALID;
        }

        $users = User::whereNotNull('telegram_id')->get()
            ->filter(fn (User $u) => BotUsers::isAllowed($u->telegram_id) && $u->accounts()->whereNull('archived_at')->exists());

        foreach ($users as $user) {
            $chatId = $user->telegram_id;

            match ($kind) {
                'morning' => $commands->today($chatId, $user, morning: true),
                // Only when the day has no operations at all: a reminder, not a nag.
                'evening' => $user->transactions()->where('occurred_at', '>=', now()->startOfDay())->exists()
                    ? null
                    : $telegram->sendCard($chatId, $cards->evening($user), Keyboard::make()->row([Keyboard::webApp('🧭 '.__('bot.buttons.open_app', [], $user->locale), '/')])),
                'weekly' => ($card = $cards->weekly($user)) ? $telegram->sendCard($chatId, $card, $cards->startKeyboard($user)) : null,
            };
        }

        return self::SUCCESS;
    }
}
