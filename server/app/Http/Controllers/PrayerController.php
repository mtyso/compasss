<?php

namespace App\Http\Controllers;

use App\Models\PrayerLog;
use App\Prayer\Cities;
use App\Prayer\Prayers;
use App\Prayer\PrayerTimes;
use App\Streaks\RecordKeeper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** The five daily prayers (docs/PLAN.md, D40): today's times and marks, the week, the streak and what to make up. */
class PrayerController extends Controller
{
    /** How far back a prayer can still be marked: the list of missed ones goes back as far. */
    private const MARK_DAYS = Prayers::MISSED_DAYS;

    public function show(Request $request, Prayers $prayers): JsonResponse
    {
        return response()->json($this->state($request, $prayers));
    }

    /** The year by days, for the map: how many of the five were prayed in their time. */
    public function year(Request $request, Prayers $prayers): JsonResponse
    {
        $history = $prayers->history($request->user(), now());

        return response()->json(['days' => $history['days'], 'stats' => $history['stats']]);
    }

    public function cities(): JsonResponse
    {
        return response()->json(Cities::list(app()->getLocale()));
    }

    /**
     * Marks a prayer as prayed. Without a status, one marked while its time lasts is in time and one marked
     * later is qaza; a prayer whose time has not come yet cannot be marked.
     */
    public function mark(Request $request, Prayers $prayers, RecordKeeper $keeper, string $date, string $prayer): JsonResponse
    {
        $user = $request->user();
        $this->checkDay($date);
        $status = $request->validate(['status' => ['sometimes', Rule::in(PrayerLog::STATUSES)]])['status'] ?? Prayers::statusAt($user, $date, $prayer, now());
        if (now()->lt(Prayers::windows($user, $date)[$prayer]['start'])) {
            throw ValidationException::withMessages(['prayer' => __('prayer.later')]);
        }

        $prayers->mark($user, $date, $prayer, $status);
        // A milestone of the streak goes into the journal at the tap that makes it (D39).
        try {
            $keeper->prayer($user, now());
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json($this->state($request, $prayers));
    }

    public function unmark(Request $request, Prayers $prayers, string $date, string $prayer): JsonResponse
    {
        $this->checkDay($date);
        $prayers->unmark($request->user(), $date, $prayer);

        return response()->json($this->state($request, $prayers));
    }

    private function checkDay(string $date): void
    {
        $day = Carbon::createFromFormat('!Y-m-d', $date, config('app.timezone'));
        if ($day === null || $day->format('Y-m-d') !== $date || $day->gt(now()) || $day->lt(now()->startOfDay()->subDays(self::MARK_DAYS))) {
            throw ValidationException::withMessages(['date' => __('prayer.mark_date')]);
        }
    }

    /** @return array<string, mixed> */
    private function state(Request $request, Prayers $prayers): array
    {
        $user = $request->user();
        $now = now();
        $today = $now->toDateString();
        $history = $prayers->history($user, $now);
        $city = Prayers::city($user, app()->getLocale());

        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i)->toDateString();
            $week[] = ['date' => $date, 'prayers' => array_map(fn (array $p) => ['name' => $p['name'], 'state' => $p['state']], $prayers->day($user, $date, $now))];
        }

        return [
            'on' => Prayers::on($user),
            // The coordinates and the declination are for the Qibla compass.
            'city' => array_diff_key($city, ['offset' => true]),
            'remind' => Prayers::remindMode($user),
            'mute' => array_values((array) $user->setting('prayer_mute', [])),
            'date' => $today,
            // Fajr, sunrise, Dhuhr, Asr, Maghrib, Isha and the middle of the night, «05:32».
            'times' => Prayers::clocks($user, $today),
            'prayers' => $prayers->day($user, $today, $now),
            'week' => $week,
            'moment' => $prayers->moment($user, $now),
            'streak' => $history['streak'],
            'stats' => $history['stats'],
            'missed' => $prayers->missed($user, $now),
            'names' => PrayerTimes::NAMES,
        ];
    }
}
