<?php

namespace App\Money;

use App\Goals\GoalMath;
use App\Goals\GoalPlanner;
use App\Goals\GoalState;
use App\Models\Account;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Debts both ways (docs/PLAN.md, D29): what the user owes (debt accounts, a negative balance, with a debt goal
 * when there is a plan to pay) and what others owe the user (`lent` accounts). Lending, borrowing, paying and
 * getting money back are ordinary transfers, so balances, the daily limit and goals need nothing new. Debts
 * are paid off in an order, the dearest first (avalanche) or the smallest (snowball), and money beyond the
 * payments goes to the first one.
 */
class Debts
{
    public const METHODS = ['avalanche', 'snowball'];

    public function __construct(private TransactionWriter $writer, private GoalPlanner $planner, private ExchangeRates $rates) {}

    /** @return array<string, mixed> both lists with their totals, for GET /debts */
    public function overview(User $user, Carbon $now): array
    {
        $owe = $this->owe($user, $now);
        $owed = $this->owed($user, $now);

        return [
            'owe' => $owe->values(),
            'owed' => $owed->values(),
            'owe_uzs' => $owe->sum('amount_uzs'),
            'owed_uzs' => $owed->sum('amount_uzs'),
            'method' => $this->method($user),
        ];
    }

    public function method(User $user): string
    {
        return $user->setting('debt_method', 'avalanche') === 'snowball' ? 'snowball' : 'avalanche';
    }

    /**
     * Debts not paid off yet, in the order to pay them off in.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function owe(User $user, Carbon $now, ?string $method = null): Collection
    {
        $balances = Balances::forUser($user);
        $plan = $this->planner->plan($user, $now);
        $rows = $this->accounts($user, 'debt')
            ->filter(fn (Account $a) => ($balances[$a->id] ?? 0) < 0)
            ->map(function (Account $account) use ($balances, $plan, $now) {
                $amount = -$balances[$account->id];
                $state = $plan->states->first(fn (GoalState $s) => $s->goal->account_id === $account->id && $s->goal->isActive());
                $rate = $state?->goal->rate === null ? null : (float) $state->goal->rate;

                return [
                    'account_id' => $account->id,
                    'name' => $account->name,
                    'currency' => $account->currency,
                    'amount' => $amount,
                    'amount_uzs' => $this->inUzs($amount, $account->currency, $now),
                    'rate' => $rate,
                    'goal' => $state === null ? null : [
                        'id' => $state->goal->id,
                        'monthly' => $state->monthly,
                        'monthly_uzs' => $state->monthlyUzs,
                        'deadline' => $state->goal->deadlineDate()?->toDateString(),
                        'paid_off_on' => $state->forecastPlan?->toDateString(),
                        'interest' => $rate > 0 && $state->monthly > 0 ? GoalMath::interest($amount, $state->monthly, $rate) : null,
                        'due' => $state->due,
                    ],
                ];
            });

        return self::order($rows, $method ?? $this->method($user));
    }

    /**
     * Money lent and not back yet, the one promised soonest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function owed(User $user, Carbon $now): Collection
    {
        $balances = Balances::forUser($user);
        $today = $now->copy()->startOfDay();

        return $this->accounts($user, 'lent')
            ->filter(fn (Account $a) => ($balances[$a->id] ?? 0) > 0)
            ->map(function (Account $account) use ($balances, $today, $now) {
                $first = $this->firstLoan($account);

                return [
                    'account_id' => $account->id,
                    'name' => $account->name,
                    'currency' => $account->currency,
                    'amount' => $balances[$account->id],
                    'amount_uzs' => $this->inUzs($balances[$account->id], $account->currency, $now),
                    'due_on' => $account->dueDate()?->toDateString(),
                    'overdue' => $account->dueDate()?->lt($today) ?? false,
                    'lent_on' => $first?->occurred_at->copy()->timezone(config('app.timezone'))->toDateString(),
                    'from_account_id' => $first?->account_id,
                ];
            })
            ->sortBy(fn (array $row) => $row['due_on'] ?? '9999-12-31')
            ->values();
    }

    /**
     * Avalanche: the highest rate first, the smaller debt among equal ones. Snowball: the smallest debt first.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  with `amount_uzs` and `rate`
     * @return Collection<int, array<string, mixed>>
     */
    public static function order(Collection $rows, string $method): Collection
    {
        $byRate = fn (array $a, array $b) => ($b['rate'] ?? 0) <=> ($a['rate'] ?? 0);
        $bySize = fn (array $a, array $b) => $a['amount_uzs'] <=> $b['amount_uzs'];

        return $rows->sort(fn (array $a, array $b) => $method === 'snowball'
            ? ($bySize($a, $b) ?: $byRate($a, $b))
            : ($byRate($a, $b) ?: $bySize($a, $b)))->values();
    }

    /**
     * «Дал в долг»: a transfer from one of the user's accounts to the person's `lent` account, a new one or
     * one they already owe on.
     *
     * @param  array<string, mixed>  $data  validated input (App\Http\Controllers\DebtController::lend)
     * @return array{0: Account, 1: Transaction}
     */
    public function lend(User $user, Account $from, array $data, Carbon $now): array
    {
        return DB::transaction(function () use ($user, $from, $data, $now) {
            $lent = empty($data['lent_account_id']) ? null : $user->accounts()->where('type', 'lent')->findOrFail($data['lent_account_id']);
            if ($lent === null) {
                $lent = $user->accounts()->create([
                    'name' => trim($data['name']),
                    'type' => 'lent',
                    'currency' => $from->currency,
                    'opening_balance' => 0,
                    'spendable' => false,
                    'is_cushion' => false,
                    'due_on' => $data['due_on'] ?? null,
                    'sort' => (int) $user->accounts()->max('sort') + 1,
                ]);
            } elseif (array_key_exists('due_on', $data)) {
                $lent->update(['due_on' => $data['due_on'], 'archived_at' => null]);
            }

            $transaction = $this->writer->save($user, new Transaction, [
                'type' => 'transfer',
                'account_id' => $from->id,
                'to_account_id' => $lent->id,
                'amount' => $data['amount'],
                'to_amount' => $data['to_amount'] ?? null,
                'note' => __('debts.lent_note', ['name' => $lent->name]),
                'occurred_at' => $data['occurred_at'] ?? $now->toDateTimeString(),
                'source' => $data['source'] ?? 'app',
            ]);

            return [$lent, $transaction];
        });
    }

    /**
     * «Взял в долг»: a debt account, with the money moved onto one of the user's accounts if it came now (an old
     * debt starts the account below zero instead), and a debt goal when there is a payment, a date or a rate.
     *
     * @param  array<string, mixed>  $data  validated input (App\Http\Controllers\DebtController::borrow)
     * @return array{0: Account, 1: ?Goal}
     */
    public function borrow(User $user, ?Account $to, array $data, Carbon $now): array
    {
        return DB::transaction(function () use ($user, $to, $data, $now) {
            $currency = $to?->currency ?? $data['currency'];
            $debt = $user->accounts()->create([
                'name' => trim($data['name']),
                'type' => 'debt',
                'currency' => $currency,
                'opening_balance' => $to ? 0 : -(int) $data['amount'],
                'spendable' => false,
                'is_cushion' => false,
                'sort' => (int) $user->accounts()->max('sort') + 1,
            ]);
            if ($to !== null) {
                $this->writer->save($user, new Transaction, [
                    'type' => 'transfer',
                    'account_id' => $debt->id,
                    'to_account_id' => $to->id,
                    'amount' => $data['amount'],
                    'note' => __('debts.borrowed_note', ['name' => $debt->name]),
                    'occurred_at' => $data['occurred_at'] ?? $now->toDateTimeString(),
                    'source' => $data['source'] ?? 'app',
                ]);
            }

            $planned = ! empty($data['monthly_amount']) || ! empty($data['deadline']) || ! empty($data['rate']);
            // A debt with only a date pays what the date needs: set as its payment, since a debt borrowed this
            // period is counted from zero until the next one (GoalPlanner) and would show none.
            $monthly = $data['monthly_amount'] ?? (empty($data['deadline']) ? null : GoalMath::roundUp(GoalMath::monthlyFor(
                (int) $data['amount'],
                GoalMath::monthsUntil($now, Carbon::parse($data['deadline'], config('app.timezone'))),
                isset($data['rate']) ? (float) $data['rate'] : null,
            ), $currency));

            return [$debt, $planned ? $this->goalFor($user, $debt, (int) $data['amount'], [
                'monthly_amount' => $monthly,
                'deadline' => $data['deadline'] ?? null,
                'rate' => $data['rate'] ?? null,
            ], $now) : null];
        });
    }

    /**
     * «Внести платёж» on a debt, «Вернул» on money lent: a transfer between it and one of the user's accounts.
     * Without a sum the whole of it. Settled in full, a lent account (or a debt with no goal) is archived.
     *
     * @param  array<string, mixed>  $data  validated input (App\Http\Controllers\DebtController::settle)
     */
    public function settle(User $user, Account $debt, Account $other, array $data, Carbon $now): Transaction
    {
        $balance = Balances::forUser($user)[$debt->id] ?? 0;
        $left = $debt->type === 'lent' ? $balance : -$balance;
        if ($left <= 0) {
            throw ValidationException::withMessages(['amount' => __($debt->type === 'lent' ? 'debts.already_returned' : 'debts.already_paid')]);
        }
        if ($other->id === $debt->id || in_array($other->type, ['debt', 'lent'], true)) {
            throw ValidationException::withMessages(['account_id' => __('debts.account_wrong')]);
        }
        // In the debt's own currency: what comes off it.
        $amount = (int) ($data['amount'] ?? $left);
        if ($amount > $left) {
            throw ValidationException::withMessages(['amount' => __('debts.too_much', ['amount' => Format::money($left, $debt->currency, app()->getLocale())])]);
        }
        $otherAmount = $other->currency === $debt->currency ? $amount : ($data['other_amount'] ?? null);
        if ($otherAmount === null) {
            throw ValidationException::withMessages(['other_amount' => __('money.to_amount_required')]);
        }

        return DB::transaction(function () use ($user, $debt, $other, $data, $now, $amount, $otherAmount, $left) {
            $lent = $debt->type === 'lent';
            $transaction = $this->writer->save($user, new Transaction, [
                'type' => 'transfer',
                'account_id' => $lent ? $debt->id : $other->id,
                'to_account_id' => $lent ? $other->id : $debt->id,
                'amount' => $lent ? $amount : $otherAmount,
                'to_amount' => $lent ? $otherAmount : $amount,
                'note' => __($lent ? 'debts.returned_note' : 'debts.paid_note', ['name' => $debt->name]),
                'occurred_at' => $data['occurred_at'] ?? $now->toDateTimeString(),
                'source' => $data['source'] ?? 'app',
            ]);
            if ($amount === $left) {
                $this->closed($user, $debt);
            }

            return $transaction;
        });
    }

    /**
     * Puts $extra more a month (in the debt's currency) on a debt's payment: its goal's chosen payment goes up,
     * or a debt goal with that payment is set for a debt without one. A debt goal asks for the bigger of what
     * its date needs and the chosen payment, so the plan and the daily limit follow.
     */
    public function addToPayment(User $user, Account $debt, int $extra, Carbon $now): Goal
    {
        $state = $this->planner->plan($user, $now)->states
            ->first(fn (GoalState $s) => $s->goal->account_id === $debt->id && $s->goal->isActive());
        if ($state !== null) {
            $state->goal->update(['monthly_amount' => $state->monthly + $extra]);

            return $state->goal->fresh();
        }

        return $this->goalFor($user, $debt, -(Balances::forUser($user)[$debt->id] ?? 0), ['monthly_amount' => $extra], $now);
    }

    /** «Направить»: the order is kept, and the extra goes to the first debt in it. */
    public function plan(User $user, string $method, int $extraUzs, Carbon $now): ?Goal
    {
        $user->update(['settings' => [...($user->settings ?? []), 'debt_method' => $method]]);
        if ($extraUzs <= 0) {
            return null;
        }
        $first = $this->owe($user, $now, $method)->first();
        if ($first === null) {
            throw ValidationException::withMessages(['extra_uzs' => __('debts.none')]);
        }
        $debt = $user->accounts()->findOrFail($first['account_id']);

        return $this->addToPayment($user, $debt, $this->fromUzs($extraUzs, $debt->currency, $now), $now);
    }

    /** The account money lent to someone came from, and when: the first transfer into it. */
    public function firstLoan(Account $lent): ?Transaction
    {
        return Transaction::query()->where('type', 'transfer')->where('to_account_id', $lent->id)->orderBy('occurred_at')->orderBy('id')->first();
    }

    /** A sum of som in a currency's minor units at today's rate, rounded up to a step that reads well. */
    public function fromUzs(int $uzs, string $currency, Carbon $now): int
    {
        $rate = $this->rates->rateFor($currency, $now) ?? throw ValidationException::withMessages(['amount' => __('goals.no_rate')]);

        return GoalMath::roundUp(Money::fromUzs($uzs, $currency, $rate), $currency);
    }

    /**
     * @param  array{monthly_amount?: ?int, deadline?: ?string, rate?: ?float}  $plan
     */
    private function goalFor(User $user, Account $debt, int $amount, array $plan, Carbon $now): Goal
    {
        return $user->goals()->create([
            'type' => 'debt',
            'title' => mb_substr($debt->name, 0, 80),
            'target_amount' => max(1, $amount),
            'currency' => $debt->currency,
            'account_id' => $debt->id,
            'start_amount' => 0,
            'deadline' => $plan['deadline'] ?? null,
            'monthly_amount' => $plan['monthly_amount'] ?? null,
            'rate' => $plan['rate'] ?? null,
            'started_on' => $now->toDateString(),
            'sort' => (int) $user->goals()->max('sort') + 1,
        ]);
    }

    /** Settled in full: reminders about it go, and an account nothing plans for leaves the lists. */
    private function closed(User $user, Account $debt): void
    {
        $user->insights()->where('rule', 'lent_due')->where('subject', 'a'.$debt->id)
            ->where('status', 'new')->whereNull('sent_via')->delete();
        $planned = $user->goals()->where('account_id', $debt->id)->whereNull('done_at')->whereNull('archived_at')->exists();
        if ($debt->type === 'lent' || ! $planned) {
            $debt->update(['archived_at' => now()]);
        }
    }

    /** @return Collection<int, Account> */
    private function accounts(User $user, string $type): Collection
    {
        return $user->accounts()->whereNull('archived_at')->where('type', $type)->orderBy('sort')->orderBy('id')->get();
    }

    private function inUzs(int $amount, string $currency, Carbon $now): int
    {
        $rate = $this->rates->rateFor($currency, $now);

        return $rate === null ? 0 : Money::toUzs($amount, $currency, $rate);
    }
}
