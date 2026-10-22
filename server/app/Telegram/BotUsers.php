<?php

namespace App\Telegram;

use App\Models\User;
use Illuminate\Support\Collection;

/** Who may talk to the bot and in which language it answers. */
final class BotUsers
{
    public static function isAllowed(int|string $telegramId): bool
    {
        return in_array((string) $telegramId, config('telegram.allowed_ids'), true);
    }

    /**
     * Who the bot writes to on its own (summaries, advice): linked to Telegram and still allowed.
     *
     * @return Collection<int, User>
     */
    public static function reachable(): Collection
    {
        return User::whereNotNull('telegram_id')->get()->filter(fn (User $u) => self::isAllowed($u->telegram_id))->values();
    }

    /** The account's language once it exists, otherwise a guess from the Telegram app's language. */
    public static function localeFor(array $from): string
    {
        $known = isset($from['id']) ? User::where('telegram_id', (int) $from['id'])->value('locale') : null;

        return $known ?? (str_starts_with($from['language_code'] ?? '', 'uz') ? 'uz' : 'ru');
    }

    /** Finds or creates the account of an allowed Telegram user and refreshes the username. */
    public static function upsert(array $from): User
    {
        $user = User::firstOrNew(['telegram_id' => (int) $from['id']]);

        $user->telegram_username = $from['username'] ?? null;
        if (! $user->exists) {
            $user->name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: ($from['username'] ?? 'Owner');
            $user->locale = self::localeFor($from);
        }
        $user->save();

        return $user;
    }
}
