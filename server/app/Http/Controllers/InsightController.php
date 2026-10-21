<?php

namespace App\Http\Controllers;

use App\Models\Insight;
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
        $user = $request->user();
        $insight = $user->insights()->findOrFail($id);
        $value = $request->validate(['value' => ['required', Rule::in(['useful', 'dismissed', 'mute'])]])['value'];

        if ($value === 'mute') {
            $muted = array_values(array_unique([...(array) $user->setting('muted_rules', []), $insight->rule]));
            $user->update(['settings' => [...($user->settings ?? []), 'muted_rules' => $muted]]);
            $user->insights()->where('rule', $insight->rule)->whereIn('status', ['new', 'seen'])->update(['status' => 'dismissed']);
            $insight->refresh();
        } else {
            $insight->update(['status' => $value]);
        }

        return response()->json(['insight' => $insight->toApi(app()->getLocale()), 'settings' => (object) ($user->settings ?? [])]);
    }

    /** The button on an insight, such as «Отметить разовой» on a large expense. */
    public function act(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $insight = $user->insights()->findOrFail($id);

        if ($insight->action() !== 'mark_one_off') {
            throw ValidationException::withMessages(['action' => 'Nothing to do.']);
        }
        $transaction = $user->transactions()->find($insight->payload['transaction_id'] ?? 0);
        $transaction?->update(['is_one_off' => true]);
        $insight->update(['status' => 'useful']);

        return response()->json(['insight' => $insight->toApi(app()->getLocale()), 'transaction' => $transaction?->toApi()]);
    }
}
