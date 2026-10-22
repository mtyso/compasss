<?php

namespace App\Models;

use App\Support\Repeat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/** A to-do with an optional due date, time, priority and repeat rule (docs/PLAN.md, D21). */
class Task extends Model
{
    use SoftDeletes;

    /** 0 обычная, 1 важная, 2 срочная. */
    public const PRIORITIES = [0, 1, 2];

    protected $fillable = ['title', 'notes', 'due_on', 'due_time', 'priority', 'repeat', 'done_at'];

    // `due_on` stays uncast and is always written as Y-m-d, like RecurringPayment::next_due_on.
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'repeat' => 'array',
            'done_at' => 'datetime',
        ];
    }

    public function dueDate(): ?Carbon
    {
        return $this->due_on ? Carbon::parse($this->due_on, config('app.timezone'))->startOfDay() : null;
    }

    /**
     * Ticks the task off. A repeating one stays open and moves to its next date instead.
     *
     * @return string|null the due date it had, so the app can offer to undo a repeat
     */
    public function complete(Carbon $now): ?string
    {
        $was = $this->dueDate()?->toDateString();
        if ($this->repeat && $was !== null) {
            $this->update(['due_on' => Repeat::nextAfter($this->repeat, $this->dueDate(), $now->copy()->startOfDay())->toDateString()]);
        } else {
            $this->update(['done_at' => $now]);
        }

        return $was;
    }

    /** @return array<string, mixed> */
    public function toApi(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $due = $this->dueDate();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'notes' => $this->notes,
            'due_on' => $due?->toDateString(),
            'due_time' => $this->due_time,
            'priority' => $this->priority,
            'repeat' => $this->repeat,
            'done_at' => $this->done_at?->toIso8601String(),
            'overdue' => $this->done_at === null && $due !== null && $due->lt($today),
        ];
    }
}
