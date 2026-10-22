<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Goals\GoalState;

/**
 * «Dongfeng 008: первый взнос собран!» When a contribution or a payment completes a money goal: a sum
 * collected, a purchase ready to be made (on installment: its down payment), a debt paid off.
 */
final class GoalReached extends BaseRule
{
    public function key(): string
    {
        return 'goal_reached';
    }

    public function events(): array
    {
        return [self::EVENT_TRANSACTION];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $trigger = $context->trigger;
        if ($trigger === null || $trigger->type !== 'transfer' || $trigger->to_account_id === null) {
            return [];
        }
        $state = $context->goals()->states->first(fn (GoalState $s) => $s->goal->account_id === $trigger->to_account_id
            && $s->goal->isActive() && $s->status === 'reached');
        if ($state === null) {
            return [];
        }
        $goal = $state->goal;
        $installment = $state->toApi()['installment'];

        return [$this->finding('good', 'reached', 'g'.$goal->id, [
            'variant' => match (true) {
                $installment !== null => 'installment',
                default => $goal->type,
            },
            'goal_id' => $goal->id,
            'currency' => $goal->currency,
            'target_amount' => $goal->target_amount,
            'payment_amount' => $installment['payment'] ?? null,
        ])];
    }
}
