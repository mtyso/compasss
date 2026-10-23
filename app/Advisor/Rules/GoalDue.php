<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;

/**
 * «До дохода 2 дня, а в цели ещё не отложено 2 500 000.» In the morning of the last days of a pay period,
 * while this period's contributions are not made. «Отложить» on the insight makes them; once they are made
 * an unread reminder goes away.
 */
final class GoalDue extends BaseRule implements Withdraws
{
    // Days before the next income when the reminder comes
    private const DAYS_BEFORE = 3;

    public function key(): string
    {
        return 'goal_due';
    }

    public function events(): array
    {
        return [self::EVENT_MORNING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $goals = $context->goals();
        $days = (int) $context->today->diffInDays($goals->period->end);
        if ($goals->dueUzs() <= 0 || $days > self::DAYS_BEFORE || $days < 1) {
            return [];
        }

        return [$this->finding($days <= 1 ? 'warn' : 'info', $this->periodKey($context), '', [
            'variant' => match (true) {
                $goals->mode === 'first' => 'first',
                $goals->debtDueUzs() > 0 => 'leftover_debt',
                default => 'leftover',
            },
            'goals_uzs' => $goals->dueUzs(),
            'debt_uzs' => $goals->debtDueUzs(),
            'goal_id' => $goals->mainSaving()?->goal->id,
            'days' => $days,
        ])];
    }

    public function checked(AdvisorContext $context): array
    {
        return [[$this->periodKey($context), '']];
    }

    private function periodKey(AdvisorContext $context): string
    {
        return 'p'.$context->goals()->period->start->toDateString();
    }
}
