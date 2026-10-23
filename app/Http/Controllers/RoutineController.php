<?php

namespace App\Http\Controllers;

use App\Models\RoutineBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Blocks of the daily routine (распорядок дня). */
class RoutineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $blocks = $request->user()->routineBlocks()->with('habit')->orderBy('start_time')->orderBy('id')->get();

        return response()->json($blocks->map->toApi()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $block = $request->user()->routineBlocks()->create($this->validated($request, null));

        return response()->json($block->toApi(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $block = $request->user()->routineBlocks()->findOrFail($id);
        $block->update($this->validated($request, $block));

        return response()->json($block->toApi());
    }

    public function destroy(Request $request, int $id): Response
    {
        $request->user()->routineBlocks()->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $block = $request->user()->routineBlocks()->onlyTrashed()->findOrFail($id);
        $block->restore();

        return response()->json($block->toApi());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?RoutineBlock $block): array
    {
        $required = $block ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$required, 'string', 'max:60'],
            'kind' => [$required, Rule::in(RoutineBlock::KINDS)],
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => [$required, 'date_format:H:i'],
            'days' => [$required, 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'remind' => ['sometimes', 'boolean'],
            // A habit the block shows and lets the user tick off; only one still in use
            'habit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('habits', 'id')
                ->where('user_id', $request->user()->id)->whereNull('deleted_at')->whereNull('archived_at')],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ]);

        // A block ending at its start would be no time at all, or the whole day; neither is a block.
        if (($data['start_time'] ?? $block?->start_time) === ($data['end_time'] ?? $block?->end_time)) {
            throw ValidationException::withMessages(['end_time' => __('things.routine_same_time')]);
        }
        if (isset($data['days'])) {
            $data['days'] = collect($data['days'])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();
        }
        if (array_key_exists('title', $data)) {
            $data['title'] = trim($data['title']);
        }

        return $data;
    }
}
