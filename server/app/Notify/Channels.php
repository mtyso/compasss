<?php

namespace App\Notify;

use App\Models\Insight;
use App\Models\User;
use App\Push\FcmClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where a user's notifications go (docs/PLAN.md, D36): the bot, the phone, or both, as the setting
 * `notify_via` says (Telegram until the phone is switched on). A way that cannot deliver right now (no
 * phone signed in, Firebase not set up or refusing the key, the bot not set up) gives way to the other,
 * so a notification is never lost to a setting.
 *
 * Each channel marks what it delivered in `insights.sent_via`. With one channel, an insight delivered any
 * way is done, so switching channels does not send the day's advice again; with both, each sends its own.
 */
class Channels
{
    public const ALL = ['telegram', 'push'];

    public const VIA = ['telegram', 'push', 'both'];

    public function __construct(private FcmClient $fcm) {}

    /** @return list<string> the channels to use now, maybe none */
    public function for(User $user): array
    {
        $ready = array_values(array_filter(self::ALL, fn (string $channel) => $this->ready($user, $channel)));
        $wanted = match ($user->setting('notify_via')) {
            'push' => ['push'],
            'both' => self::ALL,
            default => ['telegram'],
        };
        $chosen = array_values(array_intersect($wanted, $ready));

        return $chosen !== [] ? $chosen : array_slice($ready, 0, 1);
    }

    public function wants(User $user, string $channel): bool
    {
        return in_array($channel, $this->for($user), true);
    }

    /**
     * New insights of the last day the channel still owes the user, most important first.
     *
     * @return Collection<int, Insight>
     */
    public function unsent(User $user, Carbon $now, string $channel): Collection
    {
        $channels = $this->for($user);
        $rank = array_flip(Insight::SEVERITIES);

        // A handful of rows a day: filtering `sent_via` here keeps the query the same on SQLite and MariaDB.
        return $user->insights()
            ->where('status', 'new')
            ->where('created_at', '>=', $now->copy()->subDay())
            ->get()
            ->filter(fn (Insight $i) => self::owes($i, $channel, $channels))
            ->sortBy(fn (Insight $i) => [$rank[$i->severity] ?? 9, $i->id])
            ->values();
    }

    /** Not delivered by this channel, and, unless the user takes both, not by the other either. */
    public static function owes(Insight $insight, string $channel, array $channels): bool
    {
        $sent = (array) $insight->sent_via;

        return ! in_array($channel, $sent, true) && ($sent === [] || count($channels) > 1);
    }

    /** @param  iterable<Insight>  $insights */
    public static function markSent(iterable $insights, string $channel): void
    {
        foreach ($insights as $insight) {
            $insight->update(['sent_via' => array_values(array_unique([...(array) $insight->sent_via, $channel]))]);
        }
    }

    private function ready(User $user, string $channel): bool
    {
        if (! $user->isAllowed()) {
            return false;
        }

        return match ($channel) {
            'telegram' => (string) config('telegram.bot_token') !== '' && $user->telegram_id !== null,
            'push' => $this->fcm->ready() && $user->deviceTokens()->exists(),
        };
    }
}
