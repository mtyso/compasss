<?php

namespace App\Prayer;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Prayer reminders for the bot (docs/PLAN.md, D40), handed out with the calendar's (App\Calendar\Reminders):
 * when a prayer's time begins, and 20 minutes before the end to aim for if it is still not marked
 * (setting `prayer_remind`; `prayer_mute` lists the prayers left out). A prayer already marked is not reminded.
 */
final class PrayerReminders
{
    public function __construct(private Prayers $prayers) {}

    /**
     * Reminders with their moment in ($since, $now]: yesterday's too, for an Isha whose last call is past midnight.
     * `title` is the prayer's name in the user's language, `prayer` and `phase` (start, end) say which reminder it is.
     *
     * @return list<array<string, mixed>>
     */
    public function due(User $user, Carbon $since, Carbon $now): array
    {
        $mode = Prayers::remindMode($user);
        if (! Prayers::on($user) || $mode === 'off') {
            return [];
        }
        $phases = match ($mode) {
            'start' => ['start'],
            'end' => ['end'],
            default => ['start', 'end'],
        };
        $mute = (array) $user->setting('prayer_mute', []);
        $today = $now->copy()->timezone(config('app.timezone'));

        $due = [];
        foreach ([$today->copy()->subDay()->toDateString(), $today->toDateString()] as $date) {
            $marks = null;
            foreach (Prayers::windows($user, $date) as $name => $window) {
                if (in_array($name, $mute, true)) {
                    continue;
                }
                foreach ($phases as $phase) {
                    $at = $phase === 'start' ? $window['start'] : $window['last_call'];
                    if (! $at->gt($since) || $at->gt($now)) {
                        continue;
                    }
                    $marks ??= $this->prayers->marks($user, $date);
                    if (isset($marks[$name])) {
                        continue;
                    }
                    $due[] = [
                        'key' => "n:{$date}:{$name}:{$phase}",
                        'kind' => 'prayer',
                        'block_kind' => null,
                        'title' => __("prayer.names.{$name}", [], $user->locale),
                        'date' => $date,
                        'start' => $window['start']->format('H:i'),
                        'end' => $window['until']->format('H:i'),
                        'all_day' => false,
                        'remind_at' => $at,
                        'remind_before' => $phase === 'end' ? Prayers::LAST_CALL_MINUTES : 0,
                        'prayer' => $name,
                        'phase' => $phase,
                    ];
                }
            }
        }

        return $due;
    }
}
