<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;

/**
 * «Подушки хватит на 1,4 месяца обычных расходов. Цель 3 месяца: откладывай по 800 000.»
 * Once a month, measured against the usual monthly spending. The monthly amount reaches the target within a year.
 * A cushion that is a goal has its own plan: then only its size is reported, and the goal rules do the rest.
 */
final class Cushion extends BaseRule
{
    public function key(): string
    {
        return 'cushion';
    }

    public function events(): array
    {
        return [self::EVENT_MORNING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $typical = $context->typicalMonthly();
        if (! $typical) {
            return [];
        }
        $target = (int) $context->setting('cushion_target_months', 3);
        $cushion = $context->cushionUzs();
        $months = round($cushion / $typical, 1);

        if ($context->cushionAccounts()->isEmpty()) {
            return [$this->finding('info', $context->month(), '', [
                'variant' => 'none',
                'target_months' => $target,
                'target_uzs' => $typical * $target,
                'monthly_uzs' => self::roundUp(intdiv($typical * $target, 12)),
            ])];
        }
        $isGoal = $context->goals()->collecting()->contains(fn ($s) => (bool) $s->goal->account?->is_cushion);
        if ($cushion < $typical * $target && $isGoal) {
            return [];
        }
        if ($cushion < $typical * $target) {
            return [$this->finding($months < 1 ? 'warn' : 'info', $context->month(), '', [
                'variant' => 'below',
                'cushion_months' => $months,
                'target_months' => $target,
                'monthly_uzs' => self::roundUp(intdiv($typical * $target - $cushion, 12)),
            ])];
        }

        return [$this->finding('good', $context->month(), '', ['variant' => 'reached', 'cushion_months' => $months])];
    }
}
