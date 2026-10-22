<?php

namespace App\Http\Controllers;

use App\Money\BudgetStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Monthly limits per main expense category, with what is spent, the pace marker and a forecast. */
class BudgetController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return response()->json(BudgetStatus::for($request->user(), $data['month'], now())->toApi());
    }

    public function update(Request $request, string $month): JsonResponse
    {
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month), 404);
        $user = $request->user();

        $data = $request->validate([
            'items' => ['present', 'array'],
            'items.*.category_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('categories', 'id')->where('user_id', $user->id)->where('kind', 'expense')->whereNull('parent_id'),
            ],
            'items.*.limit_uzs' => ['required', 'integer', 'min:0', 'max:1000000000000'],
        ]);

        DB::transaction(function () use ($user, $month, $data) {
            $user->budgets()->where('month', $month)->delete();
            // Zero rows are kept: they mark the month as set, so it is not refilled from an earlier one.
            foreach ($data['items'] as $item) {
                $user->budgets()->create(['month' => $month, 'category_id' => $item['category_id'], 'limit_uzs' => $item['limit_uzs']]);
            }
        });

        return response()->json(BudgetStatus::for($user, $month, now())->toApi());
    }
}
