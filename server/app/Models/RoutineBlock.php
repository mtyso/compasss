<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A block of the daily routine (распорядок дня): sleep, work, sport… from a time to a time on some weekdays.
 * The calendar lays the blocks under the day as its background; one ending at or before its start runs
 * into the next day (sleep 23:00–07:00).
 */
class RoutineBlock extends Model
{
    use SoftDeletes;

    public const KINDS = ['sleep', 'work', 'study', 'sport', 'meal', 'road', 'rest', 'family', 'other'];

    protected $fillable = ['habit_id', 'title', 'kind', 'start_time', 'end_time', 'days', 'remind', 'sort'];

    // The table's defaults, so a block just created answers with them too.
    protected $attributes = ['remind' => false, 'sort' => 0];

    // `days` are ISO weekdays the block starts on, always stored as sorted unique ints.
    protected function casts(): array
    {
        return [
            'days' => 'array',
            'remind' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    public function overnight(): bool
    {
        return $this->end_time <= $this->start_time;
    }

    public function minutes(): int
    {
        return self::toMinutes($this->end_time) - self::toMinutes($this->start_time) + ($this->overnight() ? 1440 : 0);
    }

    public function startsOn(Carbon $day): bool
    {
        return in_array($day->dayOfWeekIso, array_map('intval', $this->days ?? []), true);
    }

    /**
     * The linked habit while it is still in use: the foreign key only fires on a real delete, so a
     * soft-deleted or archived habit is left out here.
     */
    public function activeHabitId(): ?int
    {
        return $this->habit_id !== null && $this->habit !== null && $this->habit->archived_at === null ? $this->habit_id : null;
    }

    public static function toMinutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'habit_id' => $this->activeHabitId(),
            'title' => $this->title,
            'kind' => $this->kind,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'days' => $this->days,
            'remind' => $this->remind,
            'sort' => $this->sort,
            'overnight' => $this->overnight(),
            'minutes' => $this->minutes(),
        ];
    }
}
