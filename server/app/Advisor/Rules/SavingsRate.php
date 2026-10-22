<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;

/** «В октябре отложено 14% дохода, в сентябре было 9%.» Last month's result, in the first days of the new one. */
final class SavingsRate extends BaseRule
{
    public function key(): string
    {
        return 'savings_rate';
    }

    public function events(): array
    {
        return [self::EVENT_MORNING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $history = $context->historyMonths();
        // Only a month the app saw whole, and only while it is still news.
        if ($context->now->day > 10 || ! isset($history[0]) || $history[0] !== $context->now->copy()->subMonthNoOverflow()->format('Y-m')) {
            return [];
        }
        $month = $history[0];
        $rate = $this->rate($context, $month);
        if ($rate === null) {
            return [];
        }
        ['income' => $income, 'expense' => $expense] = $context->monthTotals($month);

        if ($rate < 0) {
            return [$this->finding('warn', $month, '', ['variant' => 'deficit', 'month' => $month, 'deficit_uzs' => $expense - $income])];
        }
        $previous = isset($history[1]) ? $this->rate($context, $history[1]) : null;
        $severity = $rate >= 10 || ($previous !== null && $rate > $previous) ? 'good' : 'info';

        return [$this->finding($severity, $month, '', $previous === null
            ? ['variant' => 'plain', 'month' => $month, 'rate' => $rate, 'saved_uzs' => $income - $expense]
            : ['variant' => 'compare', 'month' => $month, 'rate' => $rate, 'prev_month' => $history[1], 'prev_rate' => $previous])];
    }

    /** Share of the month's income that was not spent, in whole percent; null without income. */
    private function rate(AdvisorContext $context, string $month): ?int
    {
        ['income' => $income, 'expense' => $expense] = $context->monthTotals($month);

        return $income > 0 ? (int) round(($income - $expense) * 100 / $income) : null;
    }
}
