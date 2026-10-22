<?php

namespace App\Models;

use App\Habits\HabitProgress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A habit of one of three types (docs/PLAN.md, D22): `check` is done or not, `count` reaches a daily
 * target (8 glasses of water), `limit` stays at or under one (no more than 2 coffees). The schedule
 * is either some weekdays or a number of times a week.
 */
class Habit extends Model
{
    use SoftDeletes;

    public const TYPES = ['check', 'count', 'limit'];

    protected $fillable = ['name', 'type', 'target', 'unit', 'schedule', 'color', 'icon', 'sort', 'started_on', 'archived_at'];

    // `started_on` stays uncast and is always written as Y-m-d.
    protected function casts(): array
    {
        return [
            'target' => 'integer',
            'schedule' => 'array',
            'sort' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(HabitLog::class);
    }

    public function startDate(): Carbon
    {
        return Carbon::parse($this->started_on, config('app.timezone'))->startOfDay();
    }

    /** Times a week, for a «3 раза в неделю» habit; null for one on fixed weekdays. */
    public function perWeek(): ?int
    {
        return isset($this->schedule['per_week']) ? (int) $this->schedule['per_week'] : null;
    }

    public function isScheduledOn(Carbon $day): bool
    {
        return $this->perWeek() !== null || in_array($day->dayOfWeekIso, array_map('intval', $this->schedule['days'] ?? []), true);
    }

    /** Whether a day with this much logged counts as kept. */
    public function keeps(int $value): bool
    {
        return match ($this->type) {
            'count' => $value >= $this->target,
            'limit' => $value <= $this->target,
            default => $value >= 1,
        };
    }

    /** @return array<string, mixed> */
    public function toApi(?Carbon $today = null): array
    {
        $progress = HabitProgress::for($this, ($today ?? now())->copy()->startOfDay());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'target' => $this->target,
            'unit' => $this->unit,
            'schedule' => $this->schedule,
            'color' => $this->color,
            'icon' => $this->icon,
            'sort' => $this->sort,
            'started_on' => $this->startDate()->toDateString(),
            'archived' => $this->archived_at !== null,
            ...$progress->toApi(),
        ];
    }
}
