<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;

/** «Подписки: 95% бюджета, осталось 10 000.» A category reached the alert share of its budget, or went over it. */
final class BudgetThreshold extends BaseRule
{
    public function key(): string
    {
        return 'budget_threshold';
    }

    public function events(): array
    {
        return [self::EVENT_TRANSACTION, self::EVENT_MORNING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        if (! $this->aboutThisMonth($context)) {
            return [];
        }
        $alertPercent = (int) $context->setting('budget_alert_percent', 80);
        $findings = [];

        foreach ($context->budget()->items as $item) {
            ['category_id' => $id, 'limit_uzs' => $limit, 'spent_uzs' => $spent] = $item;
            $percent = intdiv($spent * 100, $limit);

            if ($spent > $limit) {
                $findings[] = $this->finding('alert', $context->month().':over', (string) $id, [
                    'variant' => 'over', 'category_id' => $id, 'spent_uzs' => $spent, 'limit_uzs' => $limit, 'over_uzs' => $spent - $limit,
                ]);
            } elseif ($percent >= $alertPercent) {
                $findings[] = $this->finding('warn', $context->month().':near', (string) $id, [
                    'variant' => 'near', 'category_id' => $id, 'percent' => $percent, 'left_uzs' => $limit - $spent,
                ]);
            }
        }

        return $findings;
    }
}
