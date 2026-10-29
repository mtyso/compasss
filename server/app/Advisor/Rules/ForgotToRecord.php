<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;

/** «Сегодня ничего не записано. Были траты?» In the evening, when the day has no operations and no «Трат не было». */
final class ForgotToRecord extends BaseRule
{
    public function key(): string
    {
        return 'forgot_to_record';
    }

    public function events(): array
    {
        return [self::EVENT_EVENING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        // A soft advisor does not nag, and someone who never recorded anything is not «forgetting».
        if ($context->strictness() === 'soft' || $context->firstTransactionAt() === null) {
            return [];
        }
        $tomorrow = $context->today->copy()->addDay();
        $recorded = $context->user->transactions()
            ->where(fn ($q) => $q->whereBetween('occurred_at', [$context->today, $tomorrow])->orWhereBetween('created_at', [$context->today, $tomorrow]))
            ->exists();
        // «Трат не было» already answered the question (docs/PLAN.md, D38).
        $answered = $context->user->dayResults()->where('date', $context->today->toDateString())->where('no_spending', true)->exists();

        return $recorded || $answered ? [] : [$this->finding('info', $context->today->toDateString())];
    }
}
