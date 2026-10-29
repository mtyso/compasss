<?php

namespace App\Http\Controllers;

use App\Advisor\AdvisorContext;
use App\Goals\GoalMath;
use App\Habits\HabitProgress;
use App\Models\Category;
use App\Models\Habit;
use App\Models\User;
use App\Streaks\RecordKeeper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class HabitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $habits = $request->user()->habits()->orderByRaw('archived_at is not null')->orderBy('sort')->orderBy('id')->get();

        return response()->json($habits->map->toApi()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request, null);
        $habit = $user->habits()->create([
            'started_on' => now()->toDateString(),
            'sort' => (int) $user->habits()->max('sort') + 1,
            ...$data,
        ]);

        return response()->json($habit->toApi(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $habit = $request->user()->habits()->findOrFail($id);
        $habit->update($this->validated($request, $habit));

        return response()->json($habit->toApi());
    }

    public function destroy(Request $request, int $id): Response
    {
        $request->user()->habits()->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $habit = $request->user()->habits()->onlyTrashed()->findOrFail($id);
        $habit->restore();

        return response()->json($habit->toApi());
    }

    /**
     * A habit's screen (docs/PLAN.md, D37): the habit, every day of the last 53 weeks for the map, its
     * numbers, and what the money it saved means for the main goal.
     */
    public function history(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $habit = $user->habits()->findOrFail($id);
        $today = now()->startOfDay();
        $history = HabitProgress::for($habit, $today, wholeHistory: true)->history($today->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(52));

        return response()->json([
            'habit' => $habit->toApi($today),
            ...$history,
            'goal' => $this->goalDays($user, $history['stats'], now()),
        ]);
    }

    /**
     * «= N дней накоплений на Dongfeng»: the money a habit saved, in days of the main goal's
     * contributions, the same arithmetic as «Можно ли купить?».
     *
     * @param  array<string, mixed>  $stats
     * @return array{id: int, title: string, days: int, streak_days: int}|null
     */
    private function goalDays(User $user, array $stats, Carbon $now): ?array
    {
        if (! $stats['saved_uzs']) {
            return null;
        }
        $main = (new AdvisorContext($user, $now, 'check'))->goals()->mainSaving();
        if ($main === null) {
            return null;
        }
        $days = fn (?int $uzs) => (int) round(($uzs ?? 0) / $main->monthlyUzs * GoalMath::MONTH_DAYS);

        return ['id' => $main->goal->id, 'title' => $main->goal->title, 'days' => $days($stats['saved_uzs']), 'streak_days' => $days($stats['streak_saved_uzs'])];
    }

    /** Sets how much was done on a day (one tap on a check habit sends 1, tapping again 0). */
    public function log(Request $request, RecordKeeper $keeper, int $id, string $date): JsonResponse
    {
        $habit = $request->user()->habits()->findOrFail($id);
        $value = (int) $request->validate(['value' => ['required', 'integer', 'min:0', 'max:100000']])['value'];
        $day = Carbon::createFromFormat('!Y-m-d', $date, config('app.timezone'));
        if ($day === null || $day->format('Y-m-d') !== $date || $day->gt(now()) || $day->lt(now()->subDays(400))) {
            throw ValidationException::withMessages(['date' => __('things.log_date')]);
        }
        if ($habit->type === 'check') {
            $value = min($value, 1);
        }

        if ($value === 0) {
            $habit->logs()->where('date', $date)->delete();
        } else {
            $habit->logs()->updateOrCreate(['date' => $date], ['value' => $value, 'user_id' => $habit->user_id]);
        }
        // A day logged before the habit was added moves its start back, so it counts.
        if ($value > 0 && $day->lt($habit->startDate())) {
            $habit->update(['started_on' => $date]);
        }
        // A milestone or a record goes into the journal at the tap that makes it (docs/PLAN.md, D39).
        try {
            $keeper->habit($request->user(), $habit, now());
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json($habit->toApi());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Habit $habit): array
    {
        $required = $habit ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:60'],
            'type' => [$required, Rule::in(Habit::TYPES)],
            'target' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'goal_id' => ['sometimes', 'nullable', 'integer', Rule::exists('goals', 'id')->where('user_id', $request->user()->id)],
            // A money habit: what a kept day saves, in som
            'saves_uzs' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'schedule' => [$required, 'array'],
            'schedule.days' => [Rule::requiredIf($request->has('schedule') && ! $request->has('schedule.per_week')), 'array', 'min:1', 'max:7'],
            'schedule.days.*' => ['integer', 'between:1,7', 'distinct'],
            'schedule.per_week' => [Rule::requiredIf($request->has('schedule') && ! $request->has('schedule.days')), 'integer', 'between:1,7'],
            'color' => ['sometimes', Rule::in(Category::COLORS)],
            'icon' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65000'],
            'archived' => ['sometimes', 'boolean'],
        ]);

        $type = $data['type'] ?? $habit?->type;
        if (isset($data['schedule'])) {
            $data['schedule'] = isset($data['schedule']['days'])
                ? ['days' => collect($data['schedule']['days'])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all()]
                : ['per_week' => (int) $data['schedule']['per_week']];
        }
        if ($type === 'limit' && isset(($data['schedule'] ?? $habit?->schedule)['per_week'])) {
            throw ValidationException::withMessages(['schedule' => __('things.limit_daily')]);
        }
        // A check habit is one tap a day; a count habit needs something to reach.
        if ($type === 'check') {
            $data['target'] = 1;
        } elseif ($type === 'count' && ($data['target'] ?? $habit?->target ?? 0) < 1) {
            throw ValidationException::withMessages(['target' => __('things.count_target')]);
        } elseif ($type === 'limit' && ! isset($data['target']) && ! $habit) {
            $data['target'] = 0;
        }
        if (array_key_exists('archived', $data)) {
            $data['archived_at'] = $data['archived'] ? ($habit?->archived_at ?? now()) : null;
            unset($data['archived']);
        }
        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
        }

        return $data;
    }
}
