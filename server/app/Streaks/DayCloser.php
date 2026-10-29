<?php

namespace App\Streaks;

use App\Goals\GoalPlanner;
use App\Goals\GoalState;
use App\Models\User;
use App\Money\Plan;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Writes down what the money streaks cannot count afterwards (docs/PLAN.md, D38): today's daily limit,
 * which depends on today's balances, and what this pay period asks of the goals. Run through the day by
 * streaks:close-day; the last write of a day or a period is its result.
 */
final class DayCloser
{
    public function __construct(private Plan $plan, private GoalPlanner $goals) {}

    public function close(User $user, Carbon $now): void
    {
        $goals = $this->goals->plan($user, $now);

        // Without accounts there is no limit to keep: such a day counts neither way.
        if ($user->accounts()->whereNull('archived_at')->exists()) {
            $limit = $this->plan->limit($user, $now, $goals);
            $user->dayResults()->updateOrCreate(
                ['date' => $now->toDateString()],
                ['limit_uzs' => $limit->limitUzs, 'spent_uzs' => $limit->spentTodayUzs],
            );
        }

        $asked = $goals->states->filter(fn (GoalState $s) => $s->goal->hasMoney() && $s->goal->isActive() && $s->planned > 0);
        if ($asked->isEmpty() && ! $user->contributionPeriods()->where('starts_on', $goals->period->start->toDateString())->exists()) {
            return;
        }
        $user->contributionPeriods()->updateOrCreate(['starts_on' => $goals->period->start->toDateString()], [
            'ends_on' => $goals->period->end->toDateString(),
            'planned_uzs' => $asked->sum(fn (GoalState $s) => $s->rate === null ? 0 : Money::toUzs($s->planned, $s->goal->currency ?? 'UZS', $s->rate)),
            'due_uzs' => $asked->sum('dueUzs'),
            'goals' => $asked->map(fn (GoalState $s) => ['id' => $s->goal->id, 'planned' => $s->planned, 'due' => $s->due])->values()->all(),
        ]);
    }
}
