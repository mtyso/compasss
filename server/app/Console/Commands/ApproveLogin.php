<?php

namespace App\Console\Commands;

use App\Models\LoginRequest;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Local development without a bot: confirms a pending sign-in as the first id in
 * ALLOWED_TELEGRAM_IDS, the same way the bot's «Войти» button does.
 */
#[Signature('login:approve {id? : Login request id, the latest pending one by default} {--force : Run in production}')]
#[Description('Confirm a pending app sign-in without Telegram (for local development)')]
class ApproveLogin extends Command
{
    use ConfirmableTrait;

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $telegramId = config('telegram.allowed_ids')[0] ?? null;
        if (! $telegramId) {
            $this->error('ALLOWED_TELEGRAM_IDS is empty.');

            return self::FAILURE;
        }

        $login = $this->argument('id')
            ? LoginRequest::find($this->argument('id'))
            : LoginRequest::where('status', 'pending')->latest()->first();

        if (! $login || ! $login->isOpen()) {
            $this->error('No open sign-in request. Press «Войти через Telegram» in the app first.');

            return self::FAILURE;
        }

        $user = User::firstOrCreate(['telegram_id' => (int) $telegramId], ['name' => 'Elyor']);
        $login->update(['status' => 'approved', 'telegram_id' => $user->telegram_id, 'user_id' => $user->id, 'approved_at' => now()]);

        $this->info("Signed in {$login->device_name} as {$user->name}.");

        return self::SUCCESS;
    }
}
