<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Goals\GoalState;
use App\Money\Debts;

/**
 * «Dongfeng 008: первый взнос собран!» When a contribution or a payment completes a money goal: a sum
 * collected, a purchase ready to be made (on installment: its down payment), a debt paid off. A paid-off debt's
 * payment can go on to the next debt: «Перенести платёж» (Insight::roll).
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
        // A debt paid off frees its payment: offered to the next debt in the chosen order (D29, the snowball).
        $next = $goal->type === 'debt' && $state->monthly > 0
            ? app(Debts::class)->owe($context->user, $context->now)->first(fn (array $d) => $d['account_id'] !== $goal->account_id)
            : null;

        return [$this->finding('good', 'reached', 'g'.$goal->id, [
            'variant' => match (true) {
                $installment !== null => 'installment',
                $next !== null => 'debt_roll',
                default => $goal->type,
            },
            'goal_id' => $goal->id,
            'currency' => $goal->currency,
            'target_amount' => $goal->target_amount,
            'payment_amount' => $installment['payment'] ?? ($next !== null ? $state->monthly : null),
            'next_account_id' => $next['account_id'] ?? null,
            'next' => $next['name'] ?? null,
        ])];
    }
}
