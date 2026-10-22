<?php

namespace App\Goals;

use App\Models\Account;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use App\Money\ExchangeRates;
use App\Money\TransactionWriter;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Отложить»: moves money from an account into goals (docs/PLAN.md, 2.6). Each contribution is an
 * ordinary transfer into the goal's account, so balances, history and the daily limit need nothing new.
 * A dollar goal paid from a som card is a currency exchange at today's rate.
 */
class GoalContributor
{
    public function __construct(private TransactionWriter $writer, private ExchangeRates $rates) {}

    /**
     * @param  list<array{goal: Goal, amount: int}>  $items  amounts in minor units of each goal's currency
     * @return list<Transaction>
     */
    public function contribute(User $user, Account $from, array $items, Carbon $now, string $source = 'app'): array
    {
        return DB::transaction(function () use ($user, $from, $items, $now, $source) {
            $saved = [];
            foreach ($items as ['goal' => $goal, 'amount' => $amount]) {
                if ($amount <= 0) {
                    continue;
                }
                if (! $goal->hasMoney() || $goal->account_id === null) {
                    throw ValidationException::withMessages(['items' => __('goals.no_money')]);
                }
                if ($goal->account_id === $from->id) {
                    throw ValidationException::withMessages(['account_id' => __('goals.same_account')]);
                }
                $saved[] = $this->writer->save($user, new Transaction, [
                    'type' => 'transfer',
                    'account_id' => $from->id,
                    'to_account_id' => $goal->account_id,
                    'amount' => $this->inCurrency($amount, $goal->currency, $from->currency, $now),
                    'to_amount' => $amount,
                    'note' => __('goals.contribution_note', ['goal' => $goal->title]),
                    'occurred_at' => $now->toDateTimeString(),
                    'source' => $source,
                ]);
            }

            return $saved;
        });
    }

    /** This period's contributions not made yet, all from one account: the button on an income or a reminder. */
    public function contributeDue(User $user, Account $from, Carbon $now, string $source = 'app'): array
    {
        $plan = app(GoalPlanner::class)->plan($user, $now);
        $items = $plan->collecting()
            ->filter(fn (GoalState $s) => $s->due > 0 && $s->goal->account_id !== $from->id)
            ->map(fn (GoalState $s) => ['goal' => $s->goal, 'amount' => $s->due])
            ->values()
            ->all();

        return $this->contribute($user, $from, $items, $now, $source);
    }

    /** An amount in $to's minor units worth $amount of $from at today's rates. */
    public function inCurrency(int $amount, string $from, string $to, Carbon $now): int
    {
        if ($from === $to) {
            return $amount;
        }
        $fromRate = $this->rates->rateFor($from, $now);
        $toRate = $this->rates->rateFor($to, $now);
        if ($fromRate === null || $toRate === null) {
            throw ValidationException::withMessages(['amount' => __('goals.no_rate')]);
        }

        return Money::fromUzs(Money::toUzs($amount, $from, $fromRate), $to, $toRate);
    }
}
