<?php

namespace App\Calendar;

use App\Models\CalendarReminder;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calendar reminders due now, for the bot to send: events with `remind_before` and routine blocks with
 * `remind`. They are what the user asked for, at a time they chose, so unlike the advisor's messages they
 * ignore quiet hours and the per-run cap. The bot claims each before sending, so each goes once, and
 * releases it when Telegram did not answer, so the next minute tries again.
 */
class Reminders
{
    /** A reminder still goes out this late, so a slow or skipped minute of the scheduler loses nothing. */
    public const WINDOW_MINUTES = 15;

    /** An all-day event counts from this time of its day. */
    public const ALL_DAY_AT = '09:00';

    /**
     * Reminders whose time is in (now − 15 min, now] and not claimed yet, the earliest first. The key holds
     * the remind moment, so an event moved after its reminder went out reminds again at the new time. An
     * exception counts as its series, so changing only the title of one occurrence does not remind again.
     *
     * `block_kind` is the routine block's kind (sleep, work…) for its icon, null for an event.
     *
     * `$prefix` keeps a second channel's claims apart (D36): with notifications going both to the bot and the
     * phone, the phone claims «p:» + key, so each channel sends every reminder once.
     *
     * @return Collection<int, array{key: string, kind: string, block_kind: string|null, title: string, date: string, start: string|null, end: string|null, all_day: bool, remind_at: Carbon, remind_before: int}>
     */
    public function due(User $user, Carbon $now, string $prefix = ''): Collection
    {
        $since = $now->copy()->subMinutes(self::WINDOW_MINUTES);
        $due = collect([...$this->events($user, $since, $now), ...$this->routine($user, $since, $now)]);
        if ($due->isEmpty()) {
            return $due;
        }
        $claimed = CalendarReminder::where('user_id', $user->id)->whereIn('key', $due->pluck('key')->map(fn (string $key) => $prefix.$key))->pluck('key')->all();

        return $due->reject(fn (array $r) => in_array($prefix.$r['key'], $claimed, true))
            ->sortBy(fn (array $r) => $r['remind_at']->format('Y-m-d H:i').' '.$r['key'])
            ->values();
    }

    /** Takes a reminder for sending: true only for the one call that got it. */
    public function claim(User $user, string $key, Carbon $now): bool
    {
        return CalendarReminder::query()->insertOrIgnore([
            'user_id' => $user->id,
            'key' => $key,
            'sent_at' => $now->toDateTimeString(),
        ]) === 1;
    }

    /**
     * A series split by «это и следующие» goes on as a new event: the reminders already sent for the old one
     * count for the new one too, so the occurrences it takes over are not reminded twice.
     */
    public function carryOver(User $user, int $from, int $to): void
    {
        // The bot's claims and the phone's («p:», see due()).
        foreach (['', 'p:'] as $prefix) {
            $rows = CalendarReminder::where('user_id', $user->id)->where('key', 'like', "{$prefix}e{$from}:%")->get()
                ->map(fn (CalendarReminder $r) => ['user_id' => $user->id, 'key' => "{$prefix}e{$to}:".substr($r->key, strlen("{$prefix}e{$from}:")), 'sent_at' => $r->sent_at->toDateTimeString()])
                ->all();
            if ($rows) {
                CalendarReminder::query()->insertOrIgnore($rows);
            }
        }
    }

    /** Gives a claimed reminder back, so it is due again while still inside its window. */
    public function release(User $user, string $key): void
    {
        CalendarReminder::where('user_id', $user->id)->where('key', $key)->delete();
    }

    /** @return list<array<string, mixed>> */
    private function events(User $user, Carbon $since, Carbon $now): array
    {
        $first = $since->copy()->startOfDay();
        // The furthest start a reminder could be about now.
        $last = $now->copy()->addMinutes(Event::MAX_REMIND_BEFORE)->addDay()->startOfDay();
        $events = $user->events()->whereNotNull('remind_before')
            ->where('starts_on', '<=', $last->toDateString())
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('repeat')->where('starts_on', '>=', $first->toDateString()))
                ->orWhere(fn ($q) => $q->whereNotNull('repeat')->where(fn ($q) => $q
                    ->whereNull('repeat_until')->orWhere('repeat_until', '>=', $first->toDateString()))))
            ->get();

        $due = [];
        foreach ($events as $event) {
            $until = $now->copy()->addMinutes($event->remind_before)->addDay()->startOfDay();
            foreach (Occurrences::of($event, $first, $until) as $occurrence) {
                // Only an occurrence starting in the range: one that started earlier has begun already.
                if ($occurrence['date'] < $first->toDateString()) {
                    continue;
                }
                $start = Carbon::parse($occurrence['date'].' '.($event->start_time ?? self::ALL_DAY_AT), config('app.timezone'));
                $at = $start->copy()->subMinutes($event->remind_before);
                if ($at->gt($since) && $at->lte($now)) {
                    $due[] = [
                        'key' => 'e'.($event->series_id ?? $event->id).":{$at->format('Y-m-d\TH:i')}",
                        'kind' => 'event',
                        'block_kind' => null,
                        'title' => $event->title,
                        'date' => $occurrence['date'],
                        'start' => $event->start_time,
                        'end' => $event->end_time,
                        'all_day' => $event->allDay(),
                        'remind_at' => $at,
                        'remind_before' => $event->remind_before,
                    ];
                }
            }
        }

        return $due;
    }

    /** @return list<array<string, mixed>> blocks starting now, on today's weekday (or yesterday's, just after midnight) */
    private function routine(User $user, Carbon $since, Carbon $now): array
    {
        $blocks = $user->routineBlocks()->where('remind', true)->get();
        $days = array_unique([$since->toDateString(), $now->toDateString()]);

        $due = [];
        foreach ($days as $date) {
            foreach ($blocks as $block) {
                $at = Carbon::parse("{$date} {$block->start_time}", config('app.timezone'));
                if ($block->startsOn($at) && $at->gt($since) && $at->lte($now)) {
                    $due[] = [
                        'key' => "r{$block->id}:{$at->format('Y-m-d\TH:i')}",
                        'kind' => 'routine',
                        'block_kind' => $block->kind,
                        'title' => $block->title,
                        'date' => $date,
                        'start' => $block->start_time,
                        'end' => $block->end_time,
                        'all_day' => false,
                        'remind_at' => $at,
                        'remind_before' => 0,
                    ];
                }
            }
        }

        return $due;
    }
}
