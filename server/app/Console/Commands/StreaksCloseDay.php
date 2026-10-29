<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Streaks\DayCloser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('streaks:close-day')]
#[Description('Write down today\'s daily limit and this pay period\'s contributions for the money streaks')]
class StreaksCloseDay extends Command
{
    public function handle(DayCloser $closer): int
    {
        foreach (User::all() as $user) {
            try {
                $closer->close($user, now());
            } catch (Throwable $e) {
                // One user's broken data must not leave the others' day unwritten.
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
