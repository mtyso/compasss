<?php

namespace App\Http\Controllers;

use App\Models\IncomeSchedule;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class IncomeScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $schedules = $request->user()->incomeSchedules()->orderBy('day_of_month')->get();

        return response()->json($schedules->map(fn (IncomeSchedule $s) => $s->toApi())->values());
    }

    public function store(Request $request): JsonResponse
    {
        $schedule = $request->user()->incomeSchedules()->create($this->validated($request, true));

        return response()->json($schedule->toApi(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $schedule = $request->user()->incomeSchedules()->findOrFail($id);
        $schedule->update($this->validated($request, false));

        return response()->json($schedule->toApi());
    }

    public function destroy(Request $request, int $id): Response
    {
        $request->user()->incomeSchedules()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:60'],
            'day_of_month' => [$required, 'integer', 'between:1,31'],
            'expected_amount' => [$required, 'integer', 'min:0', 'max:1000000000000'],
            'currency' => [$required, Rule::in(array_keys(Money::CURRENCIES))],
            'account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
