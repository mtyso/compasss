<?php

namespace App\Money;

use App\Events\TransactionSaved;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Fills the derived fields of an operation: currency, rate, sum in som, transfer amount. */
class TransactionWriter
{
    public function __construct(private ExchangeRates $rates, private PaymentMatcher $payments) {}

    /** @param  array<string, mixed>  $data  validated input (App\Http\Requests\TransactionRequest) */
    public function save(User $user, Transaction $transaction, array $data): Transaction
    {
        $account = $user->accounts()->findOrFail($data['account_id']);
        $occurredAt = Carbon::parse($data['occurred_at'], config('app.timezone'))->timezone(config('app.timezone'));

        $rate = isset($data['rate'])
            ? Money::rate($data['rate'])
            : $this->rates->rateFor($account->currency, $occurredAt);

        if ($rate === null) {
            throw ValidationException::withMessages(['rate' => __('money.no_rate')]);
        }

        $transaction->fill([
            'type' => $data['type'],
            'account_id' => $account->id,
            'to_account_id' => null,
            'to_amount' => null,
            'amount' => $data['amount'],
            'currency' => $account->currency,
            'rate' => $account->currency === 'UZS' ? Money::rate(1) : $rate,
            'category_id' => $data['type'] === 'transfer' ? null : $data['category_id'],
            'note' => $data['note'] ?? null,
            'occurred_at' => $occurredAt,
            'is_one_off' => (bool) ($data['is_one_off'] ?? false),
        ]);
        $transaction->amount_uzs = Money::toUzs($transaction->amount, $account->currency, $transaction->rate);

        if ($data['type'] === 'transfer') {
            $this->fillTransfer($user, $transaction, $account, $data);
        }

        if (! $transaction->exists) {
            $transaction->user_id = $user->id;
            $transaction->source = $data['source'] ?? 'app';
        }

        // An expense that pays a recurring payment counts as it (D28). A link set by the caller
        // («Оплачено») is left alone: the caller moves the due date itself.
        $payment = $transaction->recurring_payment_id === null && $account->spendable
            && (! $transaction->exists || $transaction->isDirty(['type', 'category_id', 'amount', 'occurred_at']))
            ? $this->payments->find($user, $transaction)
            : null;

        DB::transaction(function () use ($transaction, $payment) {
            $transaction->recurring_payment_id ??= $payment?->id;
            $transaction->save();
            $payment?->advance();
        });
        TransactionSaved::dispatch($transaction);

        return $transaction;
    }

    private function fillTransfer(User $user, Transaction $transaction, Account $from, array $data): void
    {
        $to = $user->accounts()->findOrFail($data['to_account_id']);
        $transaction->to_account_id = $to->id;

        if ($to->currency === $from->currency) {
            $transaction->to_amount = $transaction->amount;

            return;
        }
        if (empty($data['to_amount'])) {
            throw ValidationException::withMessages(['to_amount' => __('money.to_amount_required')]);
        }
        $transaction->to_amount = (int) $data['to_amount'];
    }
}
