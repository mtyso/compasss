<?php

namespace App\Http\Controllers;

use App\Calendar\Agenda;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The calendar's feed for the days on screen: events, everything else with a date, and the routine. */
class CalendarController extends Controller
{
    /** The longest range at once: a quarter. */
    private const MAX_DAYS = 92;

    public function __invoke(Request $request, Agenda $agenda): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);
        $from = Event::day($data['from']);
        $to = Event::day($data['to']);
        $days = Event::daysBetween($from, $to) + 1;
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('things.calendar_range')]);
        }
        $now = now();
        $feed = $agenda->between($request->user(), $from, $to, $now);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => $now->toDateString(),
            'now' => $now->format('H:i'),
            'items' => $feed['items'],
            'routine' => $feed['routine'],
            // An object keyed by event id, also when empty.
            'series' => (object) $feed['series'],
        ]);
    }
}
