<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Money\Balances;
use App\Money\ExchangeRates;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index(Request $request, ExchangeRates $rates): JsonResponse
    {
        $user = $request->user();
        $balances = Balances::forUser($user);
        $accounts = $user->accounts()->orderBy('sort')->orderBy('id')->get();

        $today = now();
        $totalUzs = 0;
        $spendableUzs = 0;
        $missingRate = false;
        foreach ($accounts->whereNull('archived_at') as $account) {
            $rate = $rates->rateFor($account->currency, $today);
            if ($rate === null) {
                $missingRate = true;

                continue;
            }
            $uzs = Money::toUzs($balances[$account->id] ?? 0, $account->currency, $rate);
            $totalUzs += $uzs;
            if ($account->spendable && ! $account->is_cushion) {
                $spendableUzs += $uzs;
            }
        }

        return response()->json([
            'accounts' => $accounts->map(fn (Account $a) => $a->toApi($balances[$a->id] ?? 0))->values(),
            'total_uzs' => $totalUzs,
            'spendable_uzs' => $spendableUzs,
            'missing_rate' => $missingRate,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $request->user()->accounts()->create($this->validated($request, creating: true));

        return response()->json($account->toApi($account->opening_balance), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $request->user()->accounts()->findOrFail($id);
        $data = $this->validated($request, creating: false);

        if (array_key_exists('archived', $data)) {
            $data['archived_at'] = $data['archived'] ? ($account->archived_at ?? now()) : null;
            unset($data['archived']);
        }
        $account->update($data);

        return response()->json($account->toApi(Balances::forUser($request->user())[$account->id] ?? 0));
    }

    /** An account with history is archived instead, so past operations keep their account. */
    public function destroy(Request $request, int $id): Response
    {
        $account = $request->user()->accounts()->findOrFail($id);

        $account->hasTransactions() ? $account->update(['archived_at' => now()]) : $account->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:60'],
            'type' => [$required, Rule::in(Account::TYPES)],
            // The currency is fixed once operations exist in it; changing it would rewrite history.
            'currency' => $creating ? ['required', Rule::in(array_keys(Money::CURRENCIES))] : ['prohibited'],
            'opening_balance' => ['sometimes', 'integer', 'min:-1000000000000', 'max:1000000000000'],
            'spendable' => ['sometimes', 'boolean'],
            'is_cushion' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'archived' => $creating ? ['prohibited'] : ['sometimes', 'boolean'],
        ]);
    }
}
