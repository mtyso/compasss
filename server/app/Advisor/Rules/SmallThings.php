<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Models\Transaction;

/** «Кофе 22 раза за месяц, это 660 000.» Frequent small purchases in a flexible category. Once a month per category. */
final class SmallThings extends BaseRule
{
    private const SMALL = 100_000;

    private const MIN_TOTAL = 200_000;

    public function key(): string
    {
        return 'small_things';
    }

    public function events(): array
    {
        return [self::EVENT_TRANSACTION, self::EVENT_MORNING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $minCount = $context->pick(null, 10, 7);
        if ($minCount === null || ! $this->aboutThisMonth($context)) {
            return [];
        }
        $findings = [];

        $groups = $context->monthExpenses()
            ->filter(fn (Transaction $t) => AdvisorContext::isEveryday($t) && $t->amount_uzs <= self::SMALL)
            ->groupBy('category_id');

        foreach ($groups as $categoryId => $items) {
            // The subcategory's own mark counts: «Кофе и перекусы» is flexible inside any parent.
            if ($context->category((int) $categoryId)?->necessity !== 'flexible') {
                continue;
            }
            $total = (int) $items->sum('amount_uzs');
            if ($items->count() < $minCount || $total < self::MIN_TOTAL) {
                continue;
            }
            $findings[] = $this->finding('info', $context->month(), (string) $categoryId, [
                'category_id' => (int) $categoryId,
                'count' => $items->count(),
                'total_uzs' => $total,
            ]);
        }

        return $findings;
    }
}
