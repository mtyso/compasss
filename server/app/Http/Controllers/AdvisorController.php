<?php

namespace App\Http\Controllers;

use App\Advisor\CanAfford;
use App\Models\Category;
use App\Money\ExchangeRates;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdvisorController extends Controller
{
    /** «Можно ли это купить?» (docs/PLAN.md, 2.3). */
    public function canAfford(Request $request, CanAfford $canAfford, ExchangeRates $rates): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'currency' => ['required', Rule::in(['UZS', 'USD'])],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $user->id)->where('kind', 'expense')],
        ]);

        $rate = $rates->rateFor($data['currency'], now());
        if ($rate === null) {
            throw ValidationException::withMessages(['currency' => __('money.no_rate')]);
        }
        $category = isset($data['category_id']) ? Category::find($data['category_id']) : null;

        $verdict = $canAfford->check($user, Money::toUzs($data['amount'], $data['currency'], $rate), $category, now());

        return response()->json($verdict->toApi(app()->getLocale()));
    }
}
