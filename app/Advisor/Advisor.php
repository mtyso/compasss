<?php

namespace App\Advisor;

use App\Advisor\Rules\BudgetThreshold;
use App\Advisor\Rules\CategoryPace;
use App\Advisor\Rules\Cushion;
use App\Advisor\Rules\ForgotToRecord;
use App\Advisor\Rules\GoalDue;
use App\Advisor\Rules\GoalLeftover;
use App\Advisor\Rules\GoalPace;
use App\Advisor\Rules\GoalReached;
use App\Advisor\Rules\GoodNews;
use App\Advisor\Rules\IncomeSplit;
use App\Advisor\Rules\LargeSpend;
use App\Advisor\Rules\LentDue;
use App\Advisor\Rules\OverspendForecast;
use App\Advisor\Rules\PaymentDue;
use App\Advisor\Rules\Rule;
use App\Advisor\Rules\SavingsRate;
use App\Advisor\Rules\SmallThings;
use App\Advisor\Rules\SubscriptionFound;
use App\Advisor\Rules\Withdraws;
use App\Models\Insight;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the rules of docs/PLAN.md, 2.2 and stores what they find in `insights`, at most once per period.
 * Events: after an operation is saved (App\Listeners\RunAdvisor), every morning and evening (advisor:run).
 */
class Advisor
{
    /** @var list<class-string<Rule>> */
    public const RULES = [
        BudgetThreshold::class, CategoryPace::class, OverspendForecast::class, LargeSpend::class, SmallThings::class,
        IncomeSplit::class, GoalDue::class, GoalLeftover::class, GoalPace::class, GoalReached::class, Cushion::class, SavingsRate::class, GoodNews::class, ForgotToRecord::class,
        PaymentDue::class, SubscriptionFound::class, LentDue::class,
    ];

    /** @return Collection<int, Insight> the insights this run added */
    public function run(User $user, string $event, Carbon $now, ?Transaction $trigger = null): Collection
    {
        $context = new AdvisorContext($user, $now, $event, $trigger);
        $muted = (array) $user->setting('muted_rules', []);
        $added = collect();

        foreach (self::RULES as $class) {
            $rule = app($class);
            if (! in_array($event, $rule->events(), true) || in_array($rule->key(), $muted, true)) {
                continue;
            }
            $findings = $rule->evaluate($context);
            foreach ($findings as $finding) {
                $insight = $user->insights()->firstOrCreate(
                    ['rule' => $finding->rule, 'period_key' => $finding->periodKey, 'subject' => $finding->subject],
                    ['severity' => $finding->severity, 'payload' => $finding->payload],
                );
                if ($insight->wasRecentlyCreated) {
                    $added->push($insight);
                } elseif ($insight->status === 'new' && empty($insight->sent_via)) {
                    // Not read or sent yet: keep the numbers current («Кафе: 85%» becomes «Кафе: 92%»).
                    $insight->update(['severity' => $finding->severity, 'payload' => $finding->payload]);
                }
            }
            if ($rule instanceof Withdraws) {
                $this->withdraw($user, $rule, $context, $findings);
            }
        }

        return $added;
    }

    /**
     * Removes what the rule checked and no longer finds, as long as nobody has read it or been sent it.
     *
     * @param  list<Finding>  $findings
     */
    private function withdraw(User $user, Rule&Withdraws $rule, AdvisorContext $context, array $findings): void
    {
        $still = array_map(fn (Finding $f) => "{$f->periodKey}|{$f->subject}", $findings);
        foreach ($rule->checked($context) as [$periodKey, $subject]) {
            if (! in_array("{$periodKey}|{$subject}", $still, true)) {
                $user->insights()
                    ->where(['rule' => $rule->key(), 'period_key' => $periodKey, 'subject' => $subject, 'status' => 'new'])
                    ->whereNull('sent_via')
                    ->delete();
            }
        }
    }

    /** @return Collection<int, Insight> */
    public function afterTransaction(Transaction $transaction): Collection
    {
        return $this->run($transaction->user, Rule::EVENT_TRANSACTION, now(), $transaction);
    }

    /**
     * The morning rules, once a day: from the schedule, or from «Сегодня» when the schedule has not run yet
     * (shared hosting cron is not guaranteed).
     */
    public function morning(User $user, Carbon $now): void
    {
        if (Cache::add("advisor:morning:{$user->id}:{$now->toDateString()}", true, $now->copy()->addDay())) {
            $this->run($user, Rule::EVENT_MORNING, $now);
        }
    }

    /**
     * What «Сегодня» shows: the most important fresh advice still open.
     *
     * @return Collection<int, Insight>
     */
    public function top(User $user, Carbon $now, int $count = 2): Collection
    {
        $rank = array_flip(Insight::SEVERITIES);

        return $user->insights()
            ->whereIn('status', ['new', 'seen'])
            // «Сегодня» lists due payments itself, with «Оплачено» on each.
            ->where('rule', '!=', 'payment_due')
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->latest('id')
            ->limit(30)
            ->get()
            ->sortBy(fn (Insight $i) => [$rank[$i->severity] ?? 9, -$i->id])
            ->take($count)
            ->values();
    }
}
