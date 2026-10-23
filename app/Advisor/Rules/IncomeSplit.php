<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;

/**
 * «Пришла зарплата 12 млн. Обязательные платежи 4,2 млн, в подушку советую 1,2 млн, на жизнь остаётся 6,6 млн,
 * это 220 000 в день.» When a sizeable income arrives today. With goals the split names this period's
 * contributions instead, and «Отложить» on the insight makes them (docs/PLAN.md, 2.6).
 */
final class IncomeSplit extends BaseRule
{
    private const MIN_INCOME = 500_000;

    // Share of the income suggested for the cushion while it is below its target
    private const CUSHION_SHARE = 0.10;

    public function key(): string
    {
        return 'income_split';
    }

    public function events(): array
    {
        return [self::EVENT_TRANSACTION];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $income = $context->trigger;
        if ($income === null || $income->type !== 'income' || ! $income->occurred_at->isSameDay($context->today)) {
            return [];
        }
        // An advance or a salary, not a small refund: at least 30% of the expected monthly income.
        if ($income->amount_uzs < max(self::MIN_INCOME, (int) ($context->expectedIncomeUzs() * 0.3))) {
            return [];
        }

        $limit = $context->limit();
        $goals = $context->goals();
        if ($goals->dueUzs() > 0) {
            // «Сначала цели» already keeps them out of the money for each day; «из остатка» does it here,
            // except for debt payments, which the limit keeps out in both modes.
            $left = max(0, $limit->freeUzs - ($goals->mode === 'first' ? 0 : $goals->dueUzs() - $goals->debtDueUzs()));

            return [$this->finding('good', 't'.$income->id, '', [
                'variant' => 'goals',
                'amount_uzs' => $income->amount_uzs,
                'payments_uzs' => $limit->upcomingUzs,
                'goals_uzs' => $goals->dueUzs(),
                'debt_uzs' => $goals->debtDueUzs(),
                'goal_id' => $goals->mainSaving()?->goal->id,
                'left_uzs' => $left,
                'per_day_uzs' => intdiv($left, $limit->daysLeft),
                'days' => $limit->daysLeft,
                'account_id' => $income->account_id,
            ])];
        }
        $typical = $context->typicalMonthly();
        $target = (int) $context->setting('cushion_target_months', 3);
        $belowTarget = $typical === null || $typical === 0 || $context->cushionUzs() < $typical * $target;
        $cushion = $belowTarget ? (int) (floor($income->amount_uzs * self::CUSHION_SHARE / 50_000) * 50_000) : 0;
        $left = max(0, $limit->freeUzs - $cushion);

        $variant = match (true) {
            $cushion === 0 => 'plain',
            $context->cushionAccounts()->isEmpty() => 'new_cushion',
            default => 'cushion',
        };

        return [$this->finding('good', 't'.$income->id, '', [
            'variant' => $variant,
            'amount_uzs' => $income->amount_uzs,
            'payments_uzs' => $limit->upcomingUzs,
            'cushion_uzs' => $cushion,
            'left_uzs' => $left,
            'per_day_uzs' => intdiv($left, $limit->daysLeft),
            'days' => $limit->daysLeft,
        ])];
    }
}
