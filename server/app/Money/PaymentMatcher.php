<?php

namespace App\Money;

use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * An expense written by hand that pays a recurring payment counts as that payment (docs/PLAN.md, D28):
 * «Интернет 150 000» typed into the app or the bot closes «Интернет», so the limit stops setting the
 * money aside a second time. Deleting the expense makes the payment due again.
 */
class PaymentMatcher
{
    /** How far the sum may be from the payment's, as a share of it; a note naming the payment allows more. */
    public const AMOUNT_TOLERANCE = 0.15;

    public const NAMED_TOLERANCE = 0.5;

    /** The due date may lie this many days before the operation (paid late) or after it (paid early). */
    public const DAYS_LATE = 25;

    public const DAYS_EARLY = 7;

    /** The unpaid payment a new expense settles, the one due first if several could. */
    public function find(User $user, Transaction $transaction): ?RecurringPayment
    {
        if ($transaction->type !== 'expense' || $transaction->is_one_off || $transaction->category_id === null) {
            return null;
        }
        $day = $transaction->occurred_at->copy()->timezone(config('app.timezone'))->startOfDay();
        $categories = self::parents($user);

        return $user->recurringPayments()
            ->where('active', true)
            ->where('currency', $transaction->currency)
            ->where('next_due_on', '>=', $day->copy()->subDays(self::DAYS_LATE)->toDateString())
            ->where('next_due_on', '<=', $day->copy()->addDays(self::DAYS_EARLY)->toDateString())
            ->orderBy('next_due_on')
            ->get()
            ->first(fn (RecurringPayment $p) => self::related($categories, $p->category_id, $transaction->category_id)
                && self::close($p, $transaction->amount, $transaction->note));
    }

    /** The same category, or one inside the other («Связь» and «Связь › Интернет»). */
    public static function related(Collection $parents, ?int $a, ?int $b): bool
    {
        return $a !== null && $b !== null && ($a === $b || $parents->get($a) === $b || $parents->get($b) === $a);
    }

    /** The sum is near the payment's; a note that names the payment lets a bill vary more. */
    public static function close(RecurringPayment $payment, int $amount, ?string $note = null): bool
    {
        $named = self::normalize($payment->name) !== '' && str_contains(self::normalize($note), self::normalize($payment->name));
        $tolerance = $named ? self::NAMED_TOLERANCE : self::AMOUNT_TOLERANCE;

        return abs($amount - $payment->amount) <= $payment->amount * $tolerance;
    }

    /** Lower case letters and single spaces, so «Netflix!» and «netflix» compare equal. */
    public static function normalize(?string $text): string
    {
        $letters = preg_replace('/[^\p{L}]+/u', ' ', mb_strtolower((string) $text));

        return trim(preg_replace('/\s+/', ' ', (string) $letters));
    }

    /**
     * The payment that paid the current due date is gone (deleted, or its link dropped): due again a month earlier.
     * Only the latest payment counts: deleting an older one changes nothing.
     */
    public static function released(Transaction $transaction): void
    {
        $payment = self::paymentOf($transaction);
        if ($payment !== null && ! self::laterExists($payment, $transaction)) {
            $payment->rewind();
        }
    }

    /** A deleted payment came back: it settles its due date again. */
    public static function restored(Transaction $transaction): void
    {
        $payment = self::paymentOf($transaction);
        if ($payment !== null && ! self::laterExists($payment, $transaction)) {
            $payment->advance();
        }
    }

    private static function paymentOf(Transaction $transaction): ?RecurringPayment
    {
        return $transaction->recurring_payment_id === null ? null : RecurringPayment::find($transaction->recurring_payment_id);
    }

    private static function laterExists(RecurringPayment $payment, Transaction $transaction): bool
    {
        return $payment->transactions()
            ->whereKeyNot($transaction->id)
            ->where('occurred_at', '>', $transaction->occurred_at)
            ->exists();
    }

    /** Category ids with their parents, for {@see related()}. */
    public static function parents(User $user): Collection
    {
        return $user->categories()->pluck('parent_id', 'id');
    }
}
