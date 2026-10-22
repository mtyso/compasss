<?php

namespace App\Money;

use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\MonthDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Spending that comes back about once a month at about the same sum, but is no recurring payment yet:
 * a subscription, a phone plan, a gym. Made into one, the limit sets the money aside before it is due
 * and the bot reminds the evening before (docs/PLAN.md, D28).
 */
class SubscriptionFinder
{
    /** How far back to look: enough for three or four monthly charges. */
    private const LOOKBACK_DAYS = 130;

    /** Days between two charges of a monthly payment, with room for weekends and short months. */
    private const GAP_MIN = 26;

    private const GAP_MAX = 35;

    /** The last charge must be this recent, or the subscription has probably ended. */
    private const STALE_DAYS = 40;

    /** Charges count as the same sum within this share of each other. */
    private const AMOUNT_TOLERANCE = 0.1;

    /** Charges in a row it takes: with a note naming them two are enough, by category alone three. */
    private const MIN_NAMED = 2;

    private const MIN_UNNAMED = 3;

    /**
     * @return list<array{key: string, name: string, amount: int, currency: string, category_id: int, account_id: int,
     *     day_of_month: int, count: int, last_on: string, next_due_on: string}>
     */
    public function find(User $user, Carbon $now): array
    {
        $today = $now->copy()->timezone(config('app.timezone'))->startOfDay();
        $expenses = $user->transactions()
            ->where('type', 'expense')
            ->whereNull('recurring_payment_id')
            ->where('is_one_off', false)
            ->whereNotNull('category_id')
            ->where('occurred_at', '>=', $today->copy()->subDays(self::LOOKBACK_DAYS))
            ->orderBy('occurred_at')
            ->get();

        $payments = $user->recurringPayments()->get();
        $parents = PaymentMatcher::parents($user);
        $dismissed = (array) $user->setting('dismissed_subscriptions', []);
        $categories = $user->categories()->get()->keyBy('id');

        $found = [];
        foreach ($expenses->groupBy(fn (Transaction $t) => self::groupKey($t)) as $group => $charges) {
            $named = str_starts_with($group, 'n:');
            foreach (self::bySum($charges) as $series) {
                if (! self::monthly($series, $named ? self::MIN_NAMED : self::MIN_UNNAMED, $today)) {
                    continue;
                }
                /** @var Transaction $last */
                $last = $series->last();
                $key = $group.':'.(int) (round($last->amount, -3) ?: $last->amount);
                $suggestion = [
                    'key' => $key,
                    'name' => $named ? trim((string) $last->note) : ($categories->get($last->category_id)?->names()[$user->locale] ?? ''),
                    'amount' => $last->amount,
                    'currency' => $last->currency,
                    'category_id' => $last->category_id,
                    'account_id' => $last->account_id,
                    'day_of_month' => (int) $last->occurred_at->copy()->timezone(config('app.timezone'))->day,
                    'count' => $series->count(),
                    'last_on' => $last->occurred_at->copy()->timezone(config('app.timezone'))->toDateString(),
                ];
                $suggestion['next_due_on'] = MonthDay::nextMonth(Carbon::parse($suggestion['last_on'], config('app.timezone')), $suggestion['day_of_month'])->toDateString();

                if (! in_array($key, $dismissed, true) && ! self::coveredBy($payments, $parents, $suggestion)) {
                    $found[] = $suggestion;
                }
            }
        }

        return collect($found)->sortByDesc('last_on')->values()->all();
    }

    /** A suggestion that a recurring payment now stands for: the same name, or a related category and sum. */
    public static function covered(User $user, array $suggestion): bool
    {
        return self::coveredBy($user->recurringPayments()->get(), PaymentMatcher::parents($user), $suggestion);
    }

    /** @param  Collection<int, RecurringPayment>  $payments */
    private static function coveredBy(Collection $payments, Collection $parents, array $suggestion): bool
    {
        $name = PaymentMatcher::normalize($suggestion['name'] ?? '');

        return $payments->contains(fn (RecurringPayment $p) => $p->currency === ($suggestion['currency'] ?? null) && (
            ($name !== '' && PaymentMatcher::normalize($p->name) === $name)
            || (PaymentMatcher::related($parents, $p->category_id, $suggestion['category_id'] ?? null) && PaymentMatcher::close($p, (int) ($suggestion['amount'] ?? 0)))
        ));
    }

    /** Charges with a note group by the note, the rest by category. */
    private static function groupKey(Transaction $t): string
    {
        $name = mb_substr(PaymentMatcher::normalize($t->note), 0, 60);

        return $name !== '' ? "n:{$name}:{$t->currency}" : "c:{$t->category_id}:{$t->currency}";
    }

    /**
     * Charges of about the same sum, each series in date order.
     *
     * @param  Collection<int, Transaction>  $charges
     * @return list<Collection<int, Transaction>>
     */
    private static function bySum(Collection $charges): array
    {
        $series = [];
        foreach ($charges->sortBy('amount') as $charge) {
            $last = $series === [] ? null : $series[array_key_last($series)];
            if ($last !== null && $charge->amount <= $last->first()->amount * (1 + self::AMOUNT_TOLERANCE)) {
                $last->push($charge);
            } else {
                $series[] = collect([$charge]);
            }
        }

        return array_map(fn (Collection $s) => $s->sortBy('occurred_at')->values(), $series);
    }

    /** Every gap about a month, enough of them, and the last one recent. */
    private static function monthly(Collection $series, int $min, Carbon $today): bool
    {
        if ($series->count() < $min || $series->last()->occurred_at->lt($today->copy()->subDays(self::STALE_DAYS))) {
            return false;
        }
        $dates = $series->map(fn (Transaction $t) => $t->occurred_at->copy()->timezone(config('app.timezone'))->startOfDay())->values();
        for ($i = 1; $i < $dates->count(); $i++) {
            $gap = (int) $dates[$i - 1]->diffInDays($dates[$i]);
            if ($gap < self::GAP_MIN || $gap > self::GAP_MAX) {
                return false;
            }
        }

        return true;
    }
}
