<?php

namespace App\Advisor;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * «Можно ли это купить?» (docs/PLAN.md, 2.3):
 *
 *   yes      fits what is left of today's limit;
 *   yes_but  fits the free money, but the daily limit for the days left goes down;
 *   wait     the limit would drop below the usual essential spending per day, or the category budget would be exceeded;
 *   no       more than the free money: it would come out of the payments set aside, the minimum balance or the cushion.
 */
class CanAfford
{
    public function check(User $user, int $amountUzs, ?Category $category, Carbon $now): PurchaseVerdict
    {
        $context = new AdvisorContext($user, $now, 'check');
        $limit = $context->limit();
        $free = max(0, $limit->freeUzs);
        $reasons = [];

        if ($amountUzs > $free) {
            $shortfall = $amountUzs - $free;
            $reasons[] = ['key' => 'short', 'params' => ['shortfall_uzs' => $shortfall]];
            $typical = $context->typicalMonthly();
            $cushionLeft = $context->cushionUzs() - $shortfall;
            if ($context->cushionUzs() > 0 && $cushionLeft <= 0) {
                $reasons[] = ['key' => 'cushion_short', 'params' => []];
            } elseif ($context->cushionUzs() > 0 && $typical) {
                $reasons[] = ['key' => 'cushion_after', 'params' => ['cushion_months' => round($cushionLeft / $typical, 1)]];
            }

            return new PurchaseVerdict('no', $amountUzs, $limit->limitUzs, 0, $limit->daysLeft, $reasons);
        }

        // Bought now, it is paid from the free money like a one-off: the rest is spread over the days left.
        $limitAfter = intdiv($free - $amountUzs + $limit->spentTodayUzs, $limit->daysLeft);
        $waitUntil = null;

        if ($amountUzs <= $limit->leftTodayUzs) {
            $verdict = 'yes';
            $limitAfter = $limit->limitUzs;
            $reasons[] = ['key' => 'fits_today', 'params' => ['left_after_uzs' => $limit->leftTodayUzs - $amountUzs]];
        } else {
            $essential = $context->typicalMonthly(essentialOnly: true);
            $essentialPerDay = $essential ? intdiv($essential, 30) : 0;
            if ($limitAfter < $essentialPerDay) {
                $verdict = 'wait';
                $reasons[] = ['key' => 'below_essentials', 'params' => ['limit_after_uzs' => $limitAfter, 'essential_uzs' => $essentialPerDay]];
                $waitUntil = $limit->nextIncome?->copy();
            } else {
                $verdict = 'yes_but';
                $reasons[] = ['key' => 'limit_drops', 'params' => ['limit_after_uzs' => $limitAfter, 'limit_before_uzs' => $limit->limitUzs, 'days' => $limit->daysLeft]];
            }
        }

        $root = $category ? $context->rootOf($category->id) : null;
        $item = $root ? collect($context->budget()->items)->firstWhere('category_id', $root->id) : null;
        if ($item && $item['limit_uzs'] < $item['spent_uzs'] + $amountUzs) {
            $verdict = 'wait';
            $reasons[] = ['key' => 'budget_over', 'params' => ['category_id' => $root->id, 'over_uzs' => $item['spent_uzs'] + $amountUzs - $item['limit_uzs']]];
            $monthStart = $context->now->copy()->startOfMonth()->addMonth();
            $waitUntil = $waitUntil === null || $monthStart->gt($waitUntil) ? $monthStart : $waitUntil;
        }

        if ($verdict === 'wait' && $waitUntil !== null) {
            $reasons[] = $limit->nextIncome !== null && $waitUntil->equalTo($limit->nextIncome)
                ? ['key' => 'until_income', 'params' => ['date_on' => $waitUntil->toDateString()]]
                : ['key' => 'until_month', 'params' => ['date_on' => $waitUntil->toDateString()]];
        }

        return new PurchaseVerdict($verdict, $amountUzs, $limit->limitUzs, $limitAfter, $limit->daysLeft, $reasons, $waitUntil);
    }
}
