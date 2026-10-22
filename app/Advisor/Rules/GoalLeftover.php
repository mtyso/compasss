<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Goals\GoalMath;
use App\Support\Money;

/**
 * «До дохода остаётся 1 200 000. Отложи их в Dongfeng 008: цель станет ближе на 2 дня.» In the evening of
 * the last day before an income, when money for spending is left: it goes to the main goal (docs/PLAN.md, 2.6).
 */
final class GoalLeftover extends BaseRule
{
    private const STEP = 50_000;

    public function key(): string
    {
        return 'goal_leftover';
    }

    public function events(): array
    {
        return [self::EVENT_EVENING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $limit = $context->limit();
        $main = $context->goals()->mainSaving();
        if ($main === null || $limit->nextIncome === null || $limit->daysLeft !== 1 || $main->rate === null) {
            return [];
        }
        // The last day: what is left of today's limit is all that is left until the income.
        $leftUzs = (int) (floor($limit->leftTodayUzs / self::STEP) * self::STEP);
        if ($leftUzs < $context->pick(300_000, 200_000, 100_000)) {
            return [];
        }
        $goal = $main->goal;
        $currency = $goal->currency ?? 'UZS';
        $amount = min($main->remaining, Money::fromUzs($leftUzs, $currency, $main->rate));
        $days = (int) round($amount / $main->monthly * GoalMath::MONTH_DAYS);

        return [$this->finding('good', $context->today->toDateString(), '', [
            'variant' => $days >= 1 ? 'days' : 'plain',
            'goal_id' => $goal->id,
            'left_uzs' => $leftUzs,
            'amount' => $amount,
            'currency' => $currency,
            'days' => $days,
        ])];
    }
}
