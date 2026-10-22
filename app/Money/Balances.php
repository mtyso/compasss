<?php

namespace App\Money;

use App\Models\Transaction;
use App\Models\User;

final class Balances
{
    /**
     * Current balance of every account of the user, in the account's own minor units.
     *
     * @return array<int, int> account id => balance
     */
    public static function forUser(User $user): array
    {
        $balances = $user->accounts()->pluck('opening_balance', 'id')->map(fn ($v) => (int) $v)->all();

        $outgoing = Transaction::where('user_id', $user->id)
            ->selectRaw("account_id, SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END) AS total")
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        $incoming = Transaction::where('user_id', $user->id)
            ->where('type', 'transfer')
            ->selectRaw('to_account_id, SUM(to_amount) AS total')
            ->groupBy('to_account_id')
            ->pluck('total', 'to_account_id');

        foreach ([$outgoing, $incoming] as $sums) {
            foreach ($sums as $accountId => $total) {
                $balances[$accountId] = ($balances[$accountId] ?? 0) + (int) $total;
            }
        }

        return $balances;
    }
}
