<?php

namespace App\Http\Controllers;

use App\Goals\GoalPlanner;
use App\Models\Insight;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The «Советы» feed: history of the advisor's insights and what the user thought of them. */
class InsightController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->insights()->latest('id')->limit(100)->get()->map->toApi(app()->getLocale())->values(),
            'new_count' => $user->insights()->where('status', 'new')->count(),
        ]);
    }

    /** The feed was opened: everything in it has been seen. */
    public function seen(Request $request): Response
    {
        $request->user()->insights()->where('status', 'new')->update(['status' => 'seen']);

        return response()->noContent();
    }

    /** «Полезно», «Скрыть» or «Не показывать такое» (the whole rule is muted). */
    public function feedback(Request $request, int $id): JsonResponse
    {
        $insight = $request->user()->insights()->findOrFail($id);
        $value = $request->validate(['value' => ['required', Rule::in(['useful', 'dismissed', 'mute'])]])['value'];

        if ($value === 'mute') {
            $insight->mute();
        } else {
            $insight->update(['status' => $value]);
        }

        return response()->json(['insight' => $insight->toApi(app()->getLocale()), 'settings' => (object) ($insight->user->settings ?? [])]);
    }

    /** The button on an insight: «Отметить разовой» on a large expense, «Отложить» into goals. */
    public function act(Request $request, GoalPlanner $planner, int $id): JsonResponse
    {
        $user = $request->user();
        $insight = $user->insights()->findOrFail($id);

        $data = match ($insight->action()) {
            'mark_one_off' => ['transaction' => $insight->markOneOff()?->toApi()],
            'contribute' => [
                'transactions' => array_map(fn (Transaction $t) => $t->toApi(), $insight->contribute(now())),
                'goals' => $planner->plan($user, now())->toApi(),
            ],
            default => throw ValidationException::withMessages(['action' => 'Nothing to do.']),
        };

        return response()->json(['insight' => $insight->toApi(app()->getLocale()), ...$data]);
    }
}
