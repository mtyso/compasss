<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Support\Repeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /**
     * Open tasks and those finished in the last three days (the app filters them into Сегодня,
     * Неделя and Все itself); `?done=1` gives the finished ones, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->tasks();
        if ($request->boolean('done')) {
            $query->whereNotNull('done_at')->latest('done_at')->limit(200);
        } else {
            $query->where(fn ($q) => $q->whereNull('done_at')->orWhere('done_at', '>=', now()->subDays(3)))
                ->orderByRaw('due_on is null')->orderBy('due_on')->orderByDesc('priority')->orderBy('due_time')->orderBy('id');
        }

        return response()->json($query->get()->map->toApi()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $task = $request->user()->tasks()->create($this->validated($request, null));

        return response()->json($task->toApi(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $task = $request->user()->tasks()->findOrFail($id);
        $task->update($this->validated($request, $task));

        return response()->json($task->toApi());
    }

    public function destroy(Request $request, int $id): Response
    {
        $request->user()->tasks()->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $task = $request->user()->tasks()->onlyTrashed()->findOrFail($id);
        $task->restore();

        return response()->json($task->toApi());
    }

    /** Ticks a task off; a repeating one moves to its next date and the old one comes back for «Вернуть». */
    public function complete(Request $request, int $id): JsonResponse
    {
        $task = $request->user()->tasks()->findOrFail($id);
        $was = $task->complete(now());

        return response()->json(['task' => $task->toApi(), 'previous_due_on' => $task->repeat ? $was : null]);
    }

    /** Back to open: clears done_at, or puts a repeating task back on the date it had. */
    public function reopen(Request $request, int $id): JsonResponse
    {
        $task = $request->user()->tasks()->findOrFail($id);
        $data = $request->validate(['due_on' => ['sometimes', 'date_format:Y-m-d']]);
        $task->update(['done_at' => null, ...$data]);

        return response()->json($task->toApi());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Task $task): array
    {
        $required = $task ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$required, 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'priority' => ['sometimes', 'integer', Rule::in(Task::PRIORITIES)],
            'repeat' => ['sometimes', 'nullable', 'array'],
            'repeat.unit' => ['required_with:repeat', Rule::in(Repeat::UNITS)],
            'repeat.every' => ['sometimes', 'integer', 'between:1,365'],
            'repeat.days' => ['sometimes', 'array', 'max:7'],
            'repeat.days.*' => ['integer', 'between:1,7', 'distinct'],
        ]);

        if (! empty($data['repeat'])) {
            $repeat = ['unit' => $data['repeat']['unit'], 'every' => (int) ($data['repeat']['every'] ?? 1)];
            $due = $data['due_on'] ?? $task?->dueDate()?->toDateString() ?? now()->toDateString();
            if ($repeat['unit'] === 'week' && ! empty($data['repeat']['days'])) {
                $repeat['days'] = array_values(array_unique(array_map('intval', $data['repeat']['days'])));
                sort($repeat['days']);
            }
            if ($repeat['unit'] === 'month') {
                $repeat['day'] = (int) substr($due, 8, 2);
            }
            // A repeating task needs a date to repeat from.
            $data['repeat'] = $repeat;
            $data['due_on'] = $due;
        } elseif (array_key_exists('due_on', $data) && $data['due_on'] === null && $task?->repeat) {
            // Taking the date away stops the repeat too.
            $data['repeat'] = null;
        } elseif (! empty($data['due_on']) && ($task?->repeat['unit'] ?? null) === 'month') {
            // A monthly task moved to another day repeats on that day from now on.
            $data['repeat'] = [...$task->repeat, 'day' => (int) substr($data['due_on'], 8, 2)];
        }
        if (array_key_exists('title', $data)) {
            $data['title'] = trim($data['title']);
        }

        return $data;
    }
}
