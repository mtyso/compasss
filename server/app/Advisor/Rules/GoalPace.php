<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Goals\GoalState;
use Illuminate\Support\Collection;

/**
 * «Dongfeng 008 отстаёт от графика на 4 000 000. Чтобы успеть к 1 апреля, откладывай 24 000 000 в месяц.»
 * Each morning, about money goals: behind their line, past their deadline, or well ahead of it. At most once
 * a month per goal; an unread one goes away when the goal catches up. A goal saving its own amount, less than
 * the deadline needs (`later`), is not behind and gets nothing. With «Из остатка» being behind the line is how
 * it works, so it only says, calmly, when the actual pace reaches the goal after its deadline.
 */
final class GoalPace extends BaseRule implements Withdraws
{
    public function key(): string
    {
        return 'goal_pace';
    }

    public function events(): array
    {
        return [self::EVENT_MORNING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $findings = [];
        foreach ($this->goals($context) as $state) {
            $goal = $state->goal;
            $base = ['goal_id' => $goal->id, 'currency' => $goal->currency, 'deadline_on' => $goal->deadlineDate()?->toDateString()];
            $finding = match (true) {
                // «Из остатка»: falling behind the line is expected, so only say when the actual pace misses the deadline.
                $state->status === 'behind' && $context->goals()->mode === 'leftover' => $state->forecastFact?->gt($goal->deadlineDate())
                    ? $this->finding('info', $context->month(), 'g'.$goal->id, [
                        'variant' => 'slower', ...$base,
                        'fact_on' => $state->forecastFact->toDateString(),
                        'monthly_amount' => $state->monthlyRequired,
                    ])
                    : null,
                $state->status === 'behind' => $this->finding('warn', $context->month(), 'g'.$goal->id, [
                    'variant' => 'behind', ...$base,
                    'behind_amount' => $state->behind,
                    'monthly_amount' => $state->monthlyRequired,
                ]),
                $state->status === 'late' => $this->finding('alert', $context->month(), 'g'.$goal->id, [
                    'variant' => 'late', ...$base,
                    'remaining_amount' => $state->remaining,
                ]),
                $state->status === 'ahead' => $state->forecastFact !== null && $state->forecastFact->lt($goal->deadlineDate())
                    ? $this->finding('good', $context->month(), 'g'.$goal->id, [
                        'variant' => 'ahead', ...$base,
                        'fact_on' => $state->forecastFact->toDateString(),
                    ])
                    : null,
                default => null,
            };
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    public function checked(AdvisorContext $context): array
    {
        return $this->goals($context)->map(fn (GoalState $s) => [$context->month(), 'g'.$s->goal->id])->values()->all();
    }

    /** @return Collection<int, GoalState> money goals with a deadline still being collected */
    private function goals(AdvisorContext $context)
    {
        return $context->goals()->collecting()->filter(fn (GoalState $s) => $s->goal->deadline !== null)->values();
    }
}
