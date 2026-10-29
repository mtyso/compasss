<?php

namespace App\Http\Controllers;

use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The records journal (docs/PLAN.md, D39). */
class RecordController extends Controller
{
    /** Newest first. A record line on the day it meets a milestone of the same streak says the same: one is enough. */
    public function index(Request $request): JsonResponse
    {
        $records = $request->user()->records()->orderByDesc('date')->orderByDesc('id')->limit(500)->get();
        $milestones = $records->where('kind', 'streak_milestone')->map(fn (Record $r) => "{$r->subject}|{$r->value}|{$r->date}")->flip();

        return response()->json([
            'data' => $records
                ->reject(fn (Record $r) => $r->kind === 'streak_record' && $milestones->has("{$r->subject}|{$r->value}|{$r->date}"))
                ->map(fn (Record $r) => $r->toApi(app()->getLocale()))
                ->values(),
        ]);
    }

    /** Takes a line out of the journal for good: a check will not write it again. */
    public function destroy(Request $request, int $id): Response
    {
        $request->user()->records()->findOrFail($id)->delete();

        return response()->noContent();
    }
}
