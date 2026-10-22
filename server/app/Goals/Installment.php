<?php

namespace App\Goals;

use App\Models\Goal;
use App\Support\Money;

/**
 * A purchase on installment (docs/PLAN.md, 2.6): the goal collects the down payment, the rest of the
 * price is paid monthly after «Купил». Before buying it shows the payment and the overpayment, and what
 * a bigger down payment would change. Money is in minor units of the goal's currency.
 */
final class Installment
{
    /** The share of the price paid at once, rounded up to a step that reads well. */
    public static function downPayment(int $price, int $percent, string $currency): int
    {
        return min($price, GoalMath::roundUp((int) ceil($price * $percent / 100), $currency));
    }

    /**
     * What has to be repaid in all: the rest of the price, plus interest paid as an annuity.
     *
     * @return array{payment: int, total: int}
     */
    public static function repay(int $loan, int $months, ?float $ratePercent, string $currency): array
    {
        $exact = GoalMath::monthlyFor($loan, $months, $ratePercent);

        return [
            'payment' => GoalMath::roundUp($exact, $currency),
            'total' => $ratePercent !== null && $ratePercent > 0 ? max($loan, $exact * $months) : $loan,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function for(Goal $goal, ?string $fxRate): ?array
    {
        if (! $goal->onInstallment()) {
            return null;
        }
        $rate = $goal->rate === null ? null : (float) $goal->rate;
        $option = fn (int $percent) => self::option($goal->price, $percent, $goal->loan_months, $rate, $goal->currency ?? 'UZS', $fxRate);

        return [
            'price' => $goal->price,
            'months' => $goal->loan_months,
            'rate' => $rate,
            ...$option($goal->down_percent),
            // A bigger down payment: a longer wait now, a smaller payment and overpayment later
            'options' => array_values(array_map($option, array_filter(
                [$goal->down_percent + 10, $goal->down_percent + 20],
                fn (int $p) => $p <= 90,
            ))),
        ];
    }

    /** @return array<string, mixed> */
    private static function option(int $price, int $percent, ?int $months, ?float $rate, string $currency, ?string $fxRate): array
    {
        $down = self::downPayment($price, $percent, $currency);
        $loan = $price - $down;
        $repay = $months ? self::repay($loan, $months, $rate, $currency) : null;

        return [
            'down_percent' => $percent,
            'down_amount' => $down,
            'loan' => $loan,
            'payment' => $repay['payment'] ?? null,
            'payment_uzs' => $repay !== null && $fxRate !== null ? Money::toUzs($repay['payment'], $currency, $fxRate) : null,
            'overpay' => $repay === null ? null : $repay['total'] - $loan,
        ];
    }
}
