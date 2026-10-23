<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An event of Компас's own calendar: one day or several, all day or from a time to a time, maybe repeating
 * by the rules tasks use (App\Support\Repeat). An occurrence of a series edited on its own is an exception:
 * an event of its own with `series_id` and the date it replaces in the series' `skip_dates`.
 * App\Calendar\Occurrences expands it into days.
 */
class Event extends Model
{
    use SoftDeletes;

    public const COLORS = Category::COLORS;

    /** The earliest a reminder may come, in minutes before the start: four weeks. */
    public const MAX_REMIND_BEFORE = 40320;

    /** The longest event, in days from the first to the last. */
    public const MAX_SPAN_DAYS = 31;

    protected $fillable = [
        'series_id', 'series_date', 'title', 'notes', 'starts_on', 'ends_on', 'start_time', 'end_time', 'color',
        'repeat', 'repeat_until', 'skip_dates', 'remind_before',
    ];

    // Dates stay uncast and are always written as Y-m-d (see RecurringPayment).
    protected function casts(): array
    {
        return [
            'repeat' => 'array',
            'skip_dates' => 'array',
            'remind_before' => 'integer',
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(self::class, 'series_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(self::class, 'series_id');
    }

    public function allDay(): bool
    {
        return $this->start_time === null;
    }

    public function repeating(): bool
    {
        return $this->repeat !== null;
    }

    public function startDate(): Carbon
    {
        return self::day($this->starts_on);
    }

    public function endDate(): Carbon
    {
        return self::day($this->ends_on);
    }

    /** Days from the first to the last day: 0 for a one-day event. */
    public function spanDays(): int
    {
        return self::daysBetween($this->startDate(), $this->endDate());
    }

    public function untilDate(): ?Carbon
    {
        return $this->repeat_until ? self::day($this->repeat_until) : null;
    }

    /** A stored Y-m-d date as the start of that day in the app time zone. */
    public static function day(string $date): Carbon
    {
        return Carbon::parse(substr($date, 0, 10), config('app.timezone'))->startOfDay();
    }

    /** Whole days from $a to $b, negative when $b is earlier. */
    public static function daysBetween(Carbon $a, Carbon $b): int
    {
        return (int) round($a->diffInDays($b));
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'series_id' => $this->series_id,
            'series_date' => $this->series_date ? substr($this->series_date, 0, 10) : null,
            'title' => $this->title,
            'notes' => $this->notes,
            'starts_on' => $this->startDate()->toDateString(),
            'ends_on' => $this->endDate()->toDateString(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'all_day' => $this->allDay(),
            'color' => $this->color,
            'repeat' => $this->repeat,
            'repeat_until' => $this->untilDate()?->toDateString(),
            'skip_dates' => $this->skip_dates ?? [],
            'remind_before' => $this->remind_before,
        ];
    }
}
