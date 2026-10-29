<?php

namespace App\Http\Controllers;

use App\Models\HabitPause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Pauses for all habits (docs/PLAN.md, D37): a trip or an illness, from one day to another. One can
 * start up to two weeks back (set after falling ill) and last up to 90 days; pauses do not overlap.
 */
class HabitPauseController extends Controller
{
    private const BACK_DAYS = 14;

    private const MAX_DAYS = 90;

    /** The pauses still to come or under way, and those that ended in the last two weeks. */
    public function index(Request $request): JsonResponse
    {
        $pauses = $request->user()->habitPauses()
            ->where('ends_on', '>=', now()->subDays(self::BACK_DAYS)->toDateString())
            ->orderBy('starts_on')->get();

        return response()->json($pauses->map->toApi()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $pause = $request->user()->habitPauses()->create($this->validated($request, null));

        return response()->json($pause->toApi(), 201);
    }

    /** Usually to end a pause early: `ends_on` today or yesterday. */
    public function update(Request $request, int $id): JsonResponse
    {
        $pause = $request->user()->habitPauses()->findOrFail($id);
        $pause->update($this->validated($request, $pause));

        return response()->json($pause->toApi());
    }

    public function destroy(Request $request, int $id): Response
    {
        $request->user()->habitPauses()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /** @return array{starts_on: string, ends_on: string} */
    private function validated(Request $request, ?HabitPause $pause): array
    {
        $required = $pause ? 'sometimes' : 'required';
        $data = $request->validate([
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => [$required, 'date_format:Y-m-d'],
        ]);
        $starts = $data['starts_on'] ?? substr((string) $pause?->starts_on, 0, 10);
        $ends = $data['ends_on'] ?? substr((string) $pause?->ends_on, 0, 10);
        $tz = config('app.timezone');

        if (isset($data['starts_on']) && $starts < now($tz)->subDays(self::BACK_DAYS)->toDateString()) {
            throw ValidationException::withMessages(['starts_on' => __('things.pause_back', ['days' => self::BACK_DAYS])]);
        }
        if ($ends < $starts) {
            throw ValidationException::withMessages(['ends_on' => __('things.pause_order')]);
        }
        if (Carbon::parse($starts, $tz)->diffInDays(Carbon::parse($ends, $tz)) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['ends_on' => __('things.pause_long', ['days' => self::MAX_DAYS])]);
        }
        $overlaps = $request->user()->habitPauses()
            ->when($pause, fn ($q) => $q->whereKeyNot($pause->id))
            ->where('starts_on', '<=', $ends)->where('ends_on', '>=', $starts)
            ->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['starts_on' => __('things.pause_overlap')]);
        }

        return ['starts_on' => $starts, 'ends_on' => $ends];
    }
}
