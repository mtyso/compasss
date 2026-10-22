<?php

namespace App\Money;

use App\Models\Account;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Оплачено» on a recurring payment, wherever it is tapped (the app, a reminder, the bot): the expense
 * linked to the payment, then the next due date a month later.
 */
class PaymentPayer
{
    public function __construct(private TransactionWriter $writer) {}

    /**
     * @param  Account|null  $account  the payment's own account when null
     * @param  int|null  $amount  the payment's sum when null, which needs an account in its currency
     */
    public function pay(User $user, RecurringPayment $payment, ?Account $account = null, ?int $amount = null, ?Carbon $at = null, string $source = 'app'): Transaction
    {
        $account ??= $payment->account_id ? $user->accounts()->whereNull('archived_at')->find($payment->account_id) : null;
        if ($account === null) {
            throw ValidationException::withMessages(['account_id' => __('money.pay_account_required')]);
        }
        if ($amount === null && $account->currency !== $payment->currency) {
            throw ValidationException::withMessages(['amount' => __('money.pay_amount_required')]);
        }

        return DB::transaction(function () use ($user, $payment, $account, $amount, $at, $source) {
            $transaction = $this->writer->save($user, new Transaction(['recurring_payment_id' => $payment->id]), [
                'type' => 'expense',
                'account_id' => $account->id,
                'amount' => $amount ?? $payment->amount,
                'category_id' => $payment->category_id,
                'note' => $payment->name,
                'occurred_at' => ($at ?? now())->toDateTimeString(),
                'source' => $source,
            ]);
            $payment->advance();

            return $transaction;
        });
    }
}
