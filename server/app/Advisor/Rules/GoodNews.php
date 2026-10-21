<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Advisor\Finding;
use App\Models\Transaction;
use App\Money\BudgetStatus;
use Illuminate\Support\Carbon;

/**
 * «Неделя без перерасхода. В кафе на 180 000 меньше, чем в прошлом месяце.»
 * Two kinds: last month kept within the budget (once, early in the month), and a flexible category
 * clearly below last month at the same day (once a week).
 */
final class GoodNews extends BaseRule
{
    public function key(): string
    {
        return 'good_news';
    }

    public function events(): array
    {
        return [self::EVENT_MORNING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $lastMonth = $context->now->copy()->subMonthNoOverflow()->format('Y-m');
        if (! in_array($lastMonth, $context->historyMonths(), true)) {
            return [];
        }

        return array_values(array_filter([
            $this->budgetKept($context, $lastMonth),
            $this->spendingDown($context),
        ]));
    }

    private function budgetKept(AdvisorContext $context, string $month): ?Finding
    {
        if ($context->now->day > 10) {
            return null;
        }
        $items = BudgetStatus::for($context->user, $month, $context->now)->items;
        if ($items === [] || collect($items)->contains(fn (array $item) => $item['spent_uzs'] > $item['limit_uzs'])) {
            return null;
        }

        return $this->finding('good', $month, 'budget', ['variant' => 'budget_kept', 'month' => $month]);
    }

    private function spendingDown(AdvisorContext $context): ?Finding
    {
        // Before the 8th the comparison is mostly noise.
        if ($context->now->day < 8) {
            return null;
        }
        $start = $context->now->copy()->startOfMonth();
        $lastStart = $start->copy()->subMonthNoOverflow();
        // The same moment of last month, or its end if this month is longer.
        $lastNow = Carbon::createFromTimestamp(min(
            $lastStart->copy()->addSeconds($start->diffInSeconds($context->now))->timestamp,
            $start->timestamp,
        ), config('app.timezone'));

        $now = $this->byFlexibleRoot($context, $context->monthExpenses()->filter(fn (Transaction $t) => $t->occurred_at->lte($context->now)));
        $before = $this->byFlexibleRoot($context, $context->expensesBetween($lastStart, $lastNow));

        $best = null;
        foreach ($before as $rootId => $was) {
            $less = $was - ($now[$rootId] ?? 0);
            if ($less >= max(100_000, (int) ($was * 0.2)) && ($best === null || $less > $best['less_uzs'])) {
                $best = ['category_id' => $rootId, 'less_uzs' => $less];
            }
        }

        return $best === null ? null : $this->finding('good', $context->week(), 'down', ['variant' => 'spending_down', ...$best]);
    }

    /**
     * @param  iterable<Transaction>  $expenses
     * @return array<int, int> flexible main category id => regular spending
     */
    private function byFlexibleRoot(AdvisorContext $context, iterable $expenses): array
    {
        $sums = [];
        foreach ($expenses as $t) {
            $root = $context->rootOf($t->category_id);
            if ($root?->necessity === 'flexible' && AdvisorContext::isEveryday($t)) {
                $sums[$root->id] = ($sums[$root->id] ?? 0) + $t->amount_uzs;
            }
        }

        return $sums;
    }
}
