<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Models\RecurringPayment;
use App\Money\Balances;

/**
 * «Завтра: Интернет, 150 000 сум.» A reminder in the evening before a recurring payment is due (or in the
 * morning of the day, if the evening passed without one), and once more the morning after if it is still
 * not marked paid. When its account holds less than the payments due from it, the reminder says how much
 * is missing. «Оплачено» on the reminder pays it (Insight::pay); paying it anywhere takes the button away.
 */
final class PaymentDue extends BaseRule
{
    public function key(): string
    {
        return 'payment_due';
    }

    public function events(): array
    {
        return [self::EVENT_MORNING, self::EVENT_EVENING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $today = $context->today;
        $payments = $context->user->recurringPayments()->where('active', true)->orderBy('next_due_on')->orderBy('id')->get();
        if ($payments->isEmpty()) {
            return [];
        }

        $accounts = $context->user->accounts()->get()->keyBy('id');
        $balances = Balances::forUser($context->user);
        $findings = [];
        foreach ($payments as $payment) {
            $due = $payment->dueDate();
            $stage = match (true) {
                $context->event === self::EVENT_EVENING && $due->eq($today->copy()->addDay()) => 'tomorrow',
                $context->event === self::EVENT_MORNING && $due->eq($today) => 'today',
                $context->event === self::EVENT_MORNING && $due->eq($today->copy()->subDay()) => 'late',
                default => null,
            };
            // What is due sooner from the same account comes out of its balance first.
            $account = $accounts->get($payment->account_id);
            $short = 0;
            if ($account !== null && $account->currency === $payment->currency) {
                $balances[$account->id] = ($balances[$account->id] ?? 0) - $payment->amount;
                $short = $due->lte($today->copy()->addDay()) ? max(0, -$balances[$account->id]) : 0;
                $balances[$account->id] = max(0, $balances[$account->id]);
            }
            if ($stage === null) {
                continue;
            }

            $findings[] = $this->finding(
                $stage === 'late' || $short > 0 ? 'warn' : 'info',
                // The reminder before or on the day is one insight; the one after is another.
                'd'.$due->toDateString().($stage === 'late' ? ':late' : ''),
                'p'.$payment->id,
                [
                    'variant' => $stage === 'late' ? 'late' : $stage.($short > 0 ? '_short' : ''),
                    'payment_id' => $payment->id,
                    'name' => $payment->name,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'due_on' => $due->toDateString(),
                    'account' => $account?->name,
                    'short_amount' => $short,
                ],
            );
        }

        return $findings;
    }

    /** The payment a reminder is about, while it still waits for this due date. */
    public static function stillDue(RecurringPayment $payment, array $payload): bool
    {
        return $payment->active && $payment->dueDate()->toDateString() === ($payload['due_on'] ?? null);
    }
}
