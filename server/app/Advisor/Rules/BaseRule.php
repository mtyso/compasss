<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Advisor\Finding;

abstract class BaseRule implements Rule
{
    /** @param  array<string, mixed>  $payload */
    protected function finding(string $severity, string $periodKey, string $subject = '', array $payload = []): Finding
    {
        return new Finding($this->key(), $severity, $periodKey, $subject, $payload);
    }

    /** Budget rules react to this month's expenses only; a back-dated operation says nothing about today. */
    protected function aboutThisMonth(AdvisorContext $context): bool
    {
        $trigger = $context->trigger;

        return $trigger === null
            || ($trigger->type === 'expense' && $trigger->occurred_at->format('Y-m') === $context->month());
    }

    /** Rounds a sum up to whole 50 000 som, for advice that should read as a round number. */
    protected static function roundUp(int $amount, int $step = 50_000): int
    {
        return (int) (ceil($amount / $step) * $step);
    }
}
