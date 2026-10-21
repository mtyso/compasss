<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;

/**
 * «Кафе: потрачено 70% бюджета, а месяц прошёл на 40%. Чтобы уложиться, не больше 25 000 в день.»
 * A flexible category runs clearly ahead of the month. Once a week per category.
 */
final class CategoryPace extends BaseRule
{
    public function key(): string
    {
        return 'category_pace';
    }

    public function events(): array
    {
        return [self::EVENT_TRANSACTION, self::EVENT_MORNING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $budget = $context->budget();
        // The first days say little about the pace, and on the last one it is too late.
        if (! $this->aboutThisMonth($context) || $budget->daysGone < 3 || $budget->daysGone >= $budget->daysInMonth) {
            return [];
        }
        $pace = $budget->pace();
        $margin = $context->pick(0.25, 0.15, 0.10);
        // From the alert share on, BudgetThreshold speaks instead.
        $alertShare = (int) $context->setting('budget_alert_percent', 80) / 100;
        $daysLeft = $budget->daysInMonth - $budget->daysGone + 1;
        $findings = [];

        foreach ($budget->items as $item) {
            if ($context->category($item['category_id'])?->necessity !== 'flexible') {
                continue;
            }
            $share = $item['spent_uzs'] / $item['limit_uzs'];
            if ($share >= $alertShare || $share - $pace < $margin || $share < $pace * 1.2) {
                continue;
            }
            $findings[] = $this->finding('warn', $context->week(), (string) $item['category_id'], [
                'category_id' => $item['category_id'],
                'percent' => (int) round($share * 100),
                'pace' => (int) round($pace * 100),
                'per_day_uzs' => intdiv($item['limit_uzs'] - $item['spent_uzs'], $daysLeft),
            ]);
        }

        return $findings;
    }
}
