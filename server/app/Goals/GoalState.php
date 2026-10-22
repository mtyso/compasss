<?php

namespace App\Goals;

use App\Models\Goal;
use Illuminate\Support\Carbon;

/**
 * Where one goal stands now (docs/PLAN.md, 2.6). Money is in minor units of the goal's currency;
 * `*Uzs` values are in som at today's rate.
 *
 * status: reached (all collected, a purchase not made yet), done (closed), late (the deadline passed),
 * behind / on_track / ahead (against a straight line from the start to the deadline), open (no deadline,
 * a contribution chosen), no_plan (no deadline and no contribution).
 */
final class GoalState
{
    /**
     * @param  array{done: int, total: int}  $steps
     */
    public function __construct(
        public readonly Goal $goal,
        public readonly int $saved = 0,
        public readonly int $remaining = 0,
        public readonly int $monthlyRequired = 0,
        public readonly int $monthly = 0,
        public readonly int $planned = 0,
        public readonly int $made = 0,
        public readonly int $due = 0,
        public readonly int $dueUzs = 0,
        public readonly int $monthlyUzs = 0,
        public readonly ?string $rate = null,
        public readonly ?Carbon $forecastPlan = null,
        public readonly ?Carbon $forecastFact = null,
        public readonly ?int $factMonthly = null,
        public readonly string $status = 'open',
        public readonly int $behind = 0,
        public readonly float $progress = 0.0,
        public readonly array $steps = ['done' => 0, 'total' => 0],
        public readonly ?float $current = null,
    ) {}

    /** Collecting money right now: an active money goal with something left to collect. */
    public function collecting(): bool
    {
        return $this->goal->hasMoney() && $this->goal->isActive() && $this->remaining > 0;
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $goal = $this->goal;

        return [
            'id' => $goal->id,
            'type' => $goal->type,
            'title' => $goal->title,
            'icon' => $goal->icon,
            'currency' => $goal->currency,
            'target_amount' => $goal->target_amount,
            'account_id' => $goal->account_id,
            'deadline' => $goal->deadlineDate()?->toDateString(),
            'monthly_amount' => $goal->monthly_amount,
            'rate' => $goal->rate === null ? null : (float) $goal->rate,
            'price' => $goal->price,
            'down_percent' => $goal->down_percent,
            'loan_months' => $goal->loan_months,
            'installment' => Installment::for($goal, $this->rate),
            'unit' => $goal->unit,
            'start_value' => $goal->start_value === null ? null : (float) $goal->start_value,
            'target_value' => $goal->target_value === null ? null : (float) $goal->target_value,
            'current_value' => $this->current,
            'sort' => $goal->sort,
            'started_on' => $goal->startDate()->toDateString(),
            'done_at' => $goal->done_at?->toIso8601String(),
            'archived' => $goal->archived_at !== null,
            'saved' => $this->saved,
            'remaining' => $this->remaining,
            'progress' => round($this->progress, 4),
            'monthly' => $this->monthly,
            'monthly_required' => $this->monthlyRequired,
            'monthly_uzs' => $this->monthlyUzs,
            'fx_rate' => $this->rate,
            'period' => ['planned' => $this->planned, 'made' => $this->made, 'due' => $this->due, 'due_uzs' => $this->dueUzs],
            'forecast' => [
                'plan_on' => $this->forecastPlan?->toDateString(),
                'fact_on' => $this->forecastFact?->toDateString(),
                'fact_monthly' => $this->factMonthly,
            ],
            'status' => $this->status,
            'behind' => $this->behind,
            'steps' => $this->steps,
        ];
    }
}
