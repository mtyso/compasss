<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Advisor\Finding;
use App\Models\Transaction;
use App\Money\BudgetStatus;
use Illuminate\Support\Carbon;

/**
 * «Бюджет за октябрь выполнен», «Кафе: на 180 000 меньше обычного».
 * Two kinds: last month kept within the budget (once, early in the month), and a flexible category
 * the user spends on every month clearly below its usual amount by this day (once a week). The
 * second can stop being true after the next purchase, so it is re-checked after every operation of
 * this month and withdrawn while still unread.
 */
final class GoodNews extends BaseRule implements Withdraws
{
    public function key(): string
    {
        return 'good_news';
    }

    public function events(): array
    {
        return [self::EVENT_MORNING, self::EVENT_TRANSACTION];
    }

    public function evaluate(AdvisorContext $context): array
    {
        if ($context->event === self::EVENT_TRANSACTION) {
            // After an operation only praise already given is re-checked; new praise waits for the morning.
            $down = $this->aboutThisMonth($context) ? $this->spendingDown($context) : null;
            $given = $down && $context->user->insights()
                ->where(['rule' => $this->key(), 'period_key' => $down->periodKey, 'subject' => $down->subject])->exists();

            return $given ? [$down] : [];
        }

        return array_values(array_filter([
            $this->budgetKept($context),
            $this->spendingDown($context),
        ]));
    }

    public function checked(AdvisorContext $context): array
    {
        return $context->event === self::EVENT_MORNING || $this->aboutThisMonth($context) ? [[$context->week(), 'down']] : [];
    }

    private function budgetKept(AdvisorContext $context): ?Finding
    {
        $month = $context->now->copy()->subMonthNoOverflow()->format('Y-m');
        if ($context->now->day > 10 || ! in_array($month, $context->historyMonths(), true)) {
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
        // Before the 8th the comparison is mostly noise, and one month of history is not a habit yet.
        $months = $context->historyMonths();
        if ($context->now->day < 8 || count($months) < 2) {
            return null;
        }
        $elapsed = (int) $context->now->copy()->startOfMonth()->diffInSeconds($context->now);

        // What each category had cost by this moment of each past month (or by its end, if shorter).
        $past = [];
        foreach ($months as $month) {
            $start = Carbon::createFromFormat('Y-m', $month, config('app.timezone'))->startOfMonth();
            $end = $start->copy()->addSeconds($elapsed)->min($start->copy()->addMonth());
            foreach ($this->byFlexibleRoot($context, $context->expensesBetween($start, $end)) as $rootId => $sum) {
                $past[$rootId][] = $sum;
            }
        }
        $now = $this->byFlexibleRoot($context, $context->monthExpenses()->filter(fn (Transaction $t) => $t->occurred_at->lte($context->now)));

        $best = null;
        foreach ($past as $rootId => $sums) {
            // Only categories spent on by this day every month: a coat bought last October says nothing about this one.
            if (count($sums) < count($months)) {
                continue;
            }
            $usual = AdvisorContext::median($sums);
            $less = $usual - ($now[$rootId] ?? 0);
            if ($less >= max(100_000, (int) ($usual * 0.2)) && ($best === null || $less > $best['less_uzs'])) {
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
