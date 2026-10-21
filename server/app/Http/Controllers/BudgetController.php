<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\User;
use App\Money\MonthSpending;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Monthly limits per main expense category, with what is spent, the pace marker and a forecast. */
class BudgetController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $user = $request->user();

        $this->copyPreviousIfEmpty($user, $data['month']);

        return response()->json($this->payload($user, $data['month']));
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
            foreach ($data['items'] as $item) {
                if ($item['limit_uzs'] > 0) {
                    $user->budgets()->create(['month' => $month, 'category_id' => $item['category_id'], 'limit_uzs' => $item['limit_uzs']]);
                }
            }
        });

        return response()->json($this->payload($user, $month));
    }

    /** A month without a budget of its own starts from the latest earlier one (docs/PLAN.md, section 4). */
    private function copyPreviousIfEmpty(User $user, string $month): void
    {
        if ($month < now()->format('Y-m') || $user->budgets()->where('month', $month)->exists()) {
            return;
        }

        $source = $user->budgets()->where('month', '<', $month)->max('month');
        if (! $source) {
            return;
        }

        foreach ($user->budgets()->where('month', $source)->get() as $budget) {
            $user->budgets()->create(['month' => $month, 'category_id' => $budget->category_id, 'limit_uzs' => $budget->limit_uzs]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(User $user, string $month): array
    {
        $spending = MonthSpending::byCategory($user, $month);
        [$daysGone, $daysInMonth] = $this->daysGone($month, now());

        $items = $user->budgets()->where('month', $month)->get()->map(function (Budget $budget) use ($spending, $daysGone, $daysInMonth) {
            $spent = $spending[$budget->category_id] ?? ['total' => 0, 'one_off' => 0];
            // One-off spending happened once; only the regular part is projected to the month end.
            $forecast = $daysGone > 0 && $daysGone < $daysInMonth
                ? $spent['one_off'] + (int) round(($spent['total'] - $spent['one_off']) * $daysInMonth / $daysGone)
                : null;

            return [
                'category_id' => $budget->category_id,
                'limit_uzs' => $budget->limit_uzs,
                'spent_uzs' => $spent['total'],
                'one_off_uzs' => $spent['one_off'],
                'forecast_uzs' => $forecast,
            ];
        })->values();

        return [
            'month' => $month,
            // Share of the month gone by: where a category «should be» today on its bar.
            'pace' => round($daysGone / $daysInMonth, 4),
            'items' => $items,
            'limit_uzs' => $items->sum('limit_uzs'),
            'spent_uzs' => $items->sum('spent_uzs'),
            'unbudgeted_uzs' => collect($spending)->except($items->pluck('category_id'))->sum('total'),
        ];
    }

    /** @return array{0: int, 1: int} days of the month gone by (today included) and its length */
    private function daysGone(string $month, Carbon $now): array
    {
        [$start, $end] = MonthSpending::range($month);
        $length = $start->daysInMonth;

        return [match (true) {
            $now->lt($start) => 0,
            $now->gte($end) => $length,
            // Today counts as gone by: on the last day the marker reaches the end of the bar.
            default => $now->day,
        }, $length];
    }
}
