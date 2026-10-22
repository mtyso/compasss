<?php

namespace App\Goals;

use Illuminate\Support\Collection;

/** All goals of a user for the current pay period (App\Goals\GoalPlanner). */
final class GoalsPlan
{
    /** @param  Collection<int, GoalState>  $states  goals not archived, in their order */
    public function __construct(
        public readonly PayPeriod $period,
        public readonly Collection $states,
        public readonly string $mode = 'first',
    ) {}

    public function goal(int $id): ?GoalState
    {
        return $this->states->first(fn (GoalState $s) => $s->goal->id === $id);
    }

    /** @return Collection<int, GoalState> money goals still collecting */
    public function collecting(): Collection
    {
        return $this->states->filter(fn (GoalState $s) => $s->collecting())->values();
    }

    /** This period's contributions not made yet, in som. */
    public function dueUzs(): int
    {
        return $this->collecting()->sum('dueUzs');
    }

    /**
     * What the daily limit keeps out of the money for spending (docs/PLAN.md, D25): this period's
     * contributions not made yet, or nothing when goals get what is left over.
     */
    public function reservedUzs(): int
    {
        return $this->mode === 'first' ? $this->dueUzs() : 0;
    }

    /** Planned contributions a month, in som. */
    public function monthlyUzs(): int
    {
        return $this->collecting()->sum('monthlyUzs');
    }

    /** The main goal: the first active one. */
    public function main(): ?GoalState
    {
        return $this->states->first(fn (GoalState $s) => $s->goal->isActive() && $s->status !== 'reached');
    }

    /** The first money goal that gets a contribution: what «Можно ли купить?» counts purchases in. */
    public function mainSaving(): ?GoalState
    {
        return $this->collecting()->first(fn (GoalState $s) => $s->monthlyUzs > 0);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'mode' => $this->mode,
            'period' => [
                'start' => $this->period->start->toDateString(),
                'end' => $this->period->end->toDateString(),
                'by_income' => $this->period->byIncome,
            ],
            'due_uzs' => $this->dueUzs(),
            'monthly_uzs' => $this->monthlyUzs(),
            'main_id' => $this->main()?->goal->id,
            'goals' => $this->states->map->toApi()->values(),
        ];
    }
}
