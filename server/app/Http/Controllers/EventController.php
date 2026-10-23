<?php

namespace App\Http\Controllers;

use App\Calendar\Occurrences;
use App\Models\Event;
use App\Models\User;
use App\Support\Repeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Events of the calendar. A change or deletion of a repeating event names its scope, like in Google Calendar:
 * `all` the series, `one` occurrence (it becomes an exception) or that one and the `following` ones (the series
 * splits in two). `date` is the start of the occurrence the user acted on.
 */
class EventController extends Controller
{
    public const SCOPES = ['all', 'one', 'following'];

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($request->user()->events()->findOrFail($id)->toApi());
    }

    public function store(Request $request): JsonResponse
    {
        $body = $this->body($request, null);
        $base = [
            'title' => '', 'notes' => null, 'starts_on' => $body['starts_on'], 'ends_on' => $body['starts_on'],
            'start_time' => null, 'end_time' => null, 'color' => 'indigo', 'repeat' => null, 'repeat_until' => null,
            'skip_dates' => null, 'remind_before' => null,
        ];
        [$attributes] = $this->compose($base, $body);

        return response()->json($request->user()->events()->create($attributes)->toApi(), 201);
    }

    /** Returns {event}: the event changed, or the one the change produced (an exception, the new series). */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $event = $user->events()->findOrFail($id);
        [$scope, $date] = $this->scope($request, $event);
        $body = $this->body($request, $event);

        $changed = match ($scope) {
            'one' => $this->editOne($user, $event, $date, $body),
            'following' => $this->editFollowing($user, $event, $date, $body),
            default => $this->editAll($event, $date, $body),
        };

        return response()->json(['event' => $changed->toApi()]);
    }

    /** Returns {event}: the series as it is after the change, or null when the event is gone. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $event = $request->user()->events()->findOrFail($id);
        [$scope, $date] = $this->scope($request, $event);

        if ($scope === 'all') {
            DB::transaction(function () use ($event) {
                $event->delete();
                // At the same moment as the series, so restoring it brings back exactly these.
                $event->exceptions()->update(['deleted_at' => $event->deleted_at]);
            });

            return response()->json(['event' => null]);
        }

        DB::transaction(function () use ($event, $scope, $date) {
            if ($scope === 'one') {
                $event->update(['skip_dates' => self::withDate($event->skip_dates, $date)]);
                $event->exceptions()->where('series_date', $date)->get()->each->delete();
            } else {
                $event->update(['repeat_until' => Event::day($date)->subDay()->toDateString()]);
                $event->exceptions()->where('series_date', '>=', $date)->get()->each->delete();
            }
        });

        return response()->json(['event' => $event->toApi()]);
    }

    /** Undoes a deletion; a series comes back with the exceptions deleted along with it. */
    public function restore(Request $request, int $id): JsonResponse
    {
        $event = $request->user()->events()->onlyTrashed()->findOrFail($id);
        DB::transaction(function () use ($event) {
            $event->exceptions()->onlyTrashed()->where('deleted_at', $event->deleted_at)->restore();
            $event->restore();
        });

        return response()->json($event->toApi());
    }

    /**
     * The whole event. With `date`, the dates sent are where that occurrence goes, and the series moves by as
     * much; skipped days and exceptions move along so they stay on the occurrences they belong to.
     *
     * @param  array<string, mixed>  $body
     */
    private function editAll(Event $event, ?string $date, array $body): Event
    {
        if ($date !== null) {
            $offset = Event::daysBetween(Event::day($date), $event->startDate());
            foreach (['starts_on', 'ends_on'] as $field) {
                if (isset($body[$field])) {
                    $body[$field] = Event::day($body[$field])->addDays($offset)->toDateString();
                }
            }
        }
        if (array_key_exists('unskip', $body)) {
            $body['skip_dates'] = array_values(array_diff($event->skip_dates ?? [], [$body['unskip']])) ?: null;
            unset($body['unskip']);
        }
        // An exception given a repeat of its own becomes a series of its own.
        if ($event->series_id !== null && ! empty($body['repeat'])) {
            $body += ['series_id' => null, 'series_date' => null];
        }
        [$attributes, $delta] = $this->compose($this->stored($event), $body);

        DB::transaction(function () use ($event, $attributes, $delta) {
            $event->update($attributes);
            if ($delta !== 0) {
                foreach ($event->exceptions()->withTrashed()->get() as $exception) {
                    $exception->update(['series_date' => Event::day($exception->series_date)->addDays($delta)->toDateString()]);
                }
            }
        });

        return $event;
    }

    /**
     * «Только это»: the occurrence is skipped in the series and becomes an event of its own (an exception),
     * starting from the series' fields.
     *
     * @param  array<string, mixed>  $body
     */
    private function editOne(User $user, Event $series, string $date, array $body): Event
    {
        unset($body['repeat'], $body['repeat_until'], $body['unskip']);
        $base = [
            ...$this->stored($series),
            'starts_on' => $date,
            'ends_on' => Event::day($date)->addDays($series->spanDays())->toDateString(),
            'repeat' => null,
            'repeat_until' => null,
            'skip_dates' => null,
        ];
        [$attributes] = $this->compose($base, $body);

        return DB::transaction(function () use ($user, $series, $date, $attributes) {
            $series->update(['skip_dates' => self::withDate($series->skip_dates, $date)]);

            return $user->events()->create([...$attributes, 'series_id' => $series->id, 'series_date' => $date]);
        });
    }

    /**
     * «Это и следующие»: the series ends the day before, and a new one starts from this occurrence with the
     * changes, taking over the rest: its end, the skipped days and the exceptions from then on.
     *
     * @param  array<string, mixed>  $body
     */
    private function editFollowing(User $user, Event $series, string $date, array $body): Event
    {
        unset($body['unskip']);
        $skipped = $series->skip_dates ?? [];
        $base = [
            ...$this->stored($series),
            'starts_on' => $date,
            'ends_on' => Event::day($date)->addDays($series->spanDays())->toDateString(),
            'skip_dates' => array_values(array_filter($skipped, fn (string $d) => $d >= $date)) ?: null,
        ];
        [$attributes, $delta] = $this->compose($base, $body);

        return DB::transaction(function () use ($user, $series, $date, $skipped, $attributes, $delta) {
            $series->update([
                'repeat_until' => Event::day($date)->subDay()->toDateString(),
                'skip_dates' => array_values(array_filter($skipped, fn (string $d) => $d < $date)) ?: null,
            ]);
            $next = $user->events()->create($attributes);
            foreach ($series->exceptions()->withTrashed()->where('series_date', '>=', $date)->get() as $exception) {
                $exception->update([
                    'series_id' => $next->id,
                    'series_date' => Event::day($exception->series_date)->addDays($delta)->toDateString(),
                ]);
            }

            return $next;
        });
    }

    /**
     * The event as it will be: the body over the base fields, the length kept when only the start moves, the
     * repeat rule fitted to the start, and its end and skipped days moved along with the start.
     *
     * @param  array<string, mixed>  $base  the fields before the change
     * @param  array<string, mixed>  $body  the fields sent
     * @return array{0: array<string, mixed>, 1: int} the fields and the days the start moved
     */
    private function compose(array $base, array $body): array
    {
        $before = Event::day($base['starts_on']);
        $event = [...$base, ...$body];
        $moved = Event::daysBetween($before, Event::day($event['starts_on']));
        if (array_key_exists('starts_on', $body) && ! array_key_exists('ends_on', $body)) {
            $event['ends_on'] = Event::day($base['ends_on'])->addDays($moved)->toDateString();
        }

        if ($event['repeat'] === null) {
            $event['repeat_until'] = $event['skip_dates'] = null;
        } else {
            $event = $this->fitRule($event, array_key_exists('repeat', $body) ? 0 : $moved);
        }
        $delta = Event::daysBetween($before, Event::day($event['starts_on']));
        if ($delta !== 0 && $event['repeat'] !== null) {
            // A repeat_until sent is a date of its own.
            if ($event['repeat_until'] !== null && ! array_key_exists('repeat_until', $body)) {
                $event['repeat_until'] = Event::day($event['repeat_until'])->addDays($delta)->toDateString();
            }
            if ($event['skip_dates'] !== null) {
                $event['skip_dates'] = array_map(fn (string $d) => Event::day($d)->addDays($delta)->toDateString(), $event['skip_dates']);
            }
        }
        $this->check($event);

        return [$event, $delta];
    }

    /**
     * A monthly or yearly rule keeps the start's day. A weekly one's weekdays move along with a start moved by
     * $shift days, and a start on a weekday the rule does not list moves on to the next one that it does.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function fitRule(array $event, int $shift): array
    {
        $rule = $event['repeat'];
        $start = Event::day($event['starts_on']);
        if (($rule['unit'] ?? null) === 'week' && ! empty($rule['days'])) {
            $days = array_map(fn ($d) => (((int) $d - 1 + $shift) % 7 + 7) % 7 + 1, $rule['days']);
            $days = array_values(array_unique($days));
            sort($days);
            $rule['days'] = $days;

            $ahead = 0;
            while (! in_array($start->copy()->addDays($ahead)->dayOfWeekIso, $days, true)) {
                $ahead++;
            }
            if ($ahead > 0) {
                $event['starts_on'] = $start->addDays($ahead)->toDateString();
                $event['ends_on'] = Event::day($event['ends_on'])->addDays($ahead)->toDateString();
            }
        }
        if (in_array($rule['unit'] ?? null, ['month', 'year'], true)) {
            $rule['day'] = $start->day;
        }
        $event['repeat'] = $rule;

        return $event;
    }

    /**
     * Checks the event as a whole, after the body is put over what is stored, so a start time sent alone is
     * held against the stored end.
     *
     * @param  array<string, mixed>  $event
     */
    private function check(array $event): void
    {
        $fail = fn (string $field, string $key) => throw ValidationException::withMessages([$field => __("things.{$key}")]);

        if (($event['start_time'] === null) !== ($event['end_time'] === null)) {
            $fail('end_time', 'event_times_pair');
        }
        if ($event['ends_on'] < $event['starts_on']) {
            $fail('ends_on', 'event_end_before_start');
        }
        if ($event['start_time'] !== null && "{$event['ends_on']} {$event['end_time']}" <= "{$event['starts_on']} {$event['start_time']}") {
            $fail('end_time', 'event_end_before_start');
        }
        if (Event::daysBetween(Event::day($event['starts_on']), Event::day($event['ends_on'])) > Event::MAX_SPAN_DAYS) {
            $fail('ends_on', 'event_too_long');
        }
        if ($event['repeat_until'] !== null && $event['repeat_until'] < $event['starts_on']) {
            $fail('repeat_until', 'event_until_before_start');
        }
    }

    /**
     * The scope and the occurrence acted on. An event that does not repeat, or an exception, is only ever
     * itself; from the first occurrence on is the whole series.
     *
     * @return array{0: string, 1: string|null}
     */
    private function scope(Request $request, Event $event): array
    {
        $data = $request->validate([
            'scope' => ['sometimes', Rule::in(self::SCOPES)],
            'date' => [Rule::requiredIf(in_array($request->input('scope'), ['one', 'following'], true)), 'date_format:Y-m-d'],
        ]);
        $scope = $data['scope'] ?? 'all';
        $date = $data['date'] ?? null;
        if ($date !== null && ! Occurrences::isOccurrence($event, $date)) {
            throw ValidationException::withMessages(['date' => __('things.event_not_occurrence')]);
        }
        if ($event->series_id !== null || ! $event->repeating() || ($scope === 'following' && $date === $event->startDate()->toDateString())) {
            $scope = 'all';
        }

        return [$scope, $date];
    }

    /** @return array<string, mixed> the fields sent, checked one by one */
    private function body(Request $request, ?Event $event): array
    {
        $required = $event ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$required, 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'color' => ['sometimes', Rule::in(Event::COLORS)],
            'repeat' => ['sometimes', 'nullable', 'array'],
            'repeat.unit' => ['required_with:repeat', Rule::in(Repeat::UNITS)],
            'repeat.every' => ['sometimes', 'integer', 'between:1,365'],
            'repeat.days' => ['sometimes', 'array', 'max:7'],
            'repeat.days.*' => ['integer', 'between:1,7', 'distinct'],
            'repeat_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'remind_before' => ['sometimes', 'nullable', 'integer', 'between:0,'.Event::MAX_REMIND_BEFORE],
            // Undo of deleting one occurrence: the day comes back into the series.
            ...($event ? ['unskip' => [
                'sometimes', Rule::prohibitedIf($request->input('scope', 'all') !== 'all'),
                'date_format:Y-m-d', Rule::in($event->skip_dates ?? []),
            ]] : []),
        ]);

        if (array_key_exists('repeat', $data)) {
            $data['repeat'] = empty($data['repeat']) ? null : self::rule($data['repeat']);
        }
        if (array_key_exists('title', $data)) {
            $data['title'] = trim($data['title']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $repeat
     * @return array<string, mixed>
     */
    private static function rule(array $repeat): array
    {
        $rule = ['unit' => $repeat['unit'], 'every' => (int) ($repeat['every'] ?? 1)];
        if ($rule['unit'] === 'week' && ! empty($repeat['days'])) {
            $rule['days'] = array_values(array_unique(array_map('intval', $repeat['days'])));
            sort($rule['days']);
        }

        return $rule;
    }

    /** @return array<string, mixed> the stored fields a change starts from */
    private function stored(Event $event): array
    {
        return [
            'title' => $event->title,
            'notes' => $event->notes,
            'starts_on' => $event->startDate()->toDateString(),
            'ends_on' => $event->endDate()->toDateString(),
            'start_time' => $event->start_time,
            'end_time' => $event->end_time,
            'color' => $event->color,
            'repeat' => $event->repeat,
            'repeat_until' => $event->untilDate()?->toDateString(),
            'skip_dates' => $event->skip_dates,
            'remind_before' => $event->remind_before,
        ];
    }

    /**
     * @param  list<string>|null  $dates
     * @return list<string>
     */
    private static function withDate(?array $dates, string $date): array
    {
        $dates = array_values(array_unique([...$dates ?? [], $date]));
        sort($dates);

        return $dates;
    }
}
