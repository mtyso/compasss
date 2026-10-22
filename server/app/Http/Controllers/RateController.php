<?php

namespace App\Http\Controllers;

use App\Money\ExchangeRates;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RateController extends Controller
{
    public function __invoke(Request $request, ExchangeRates $rates): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = isset($data['date']) ? Carbon::parse($data['date'], config('app.timezone')) : now();

        $result = [];
        foreach (array_keys(Money::CURRENCIES) as $currency) {
            if ($currency !== 'UZS') {
                $result[$currency] = $rates->rateFor($currency, $date);
            }
        }

        return response()->json(['date' => $date->toDateString(), 'rates' => $result]);
    }
}
