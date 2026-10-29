<?php

namespace App\Streaks;

use App\Goals\GoalMath;
use App\Goals\GoalPlanner;
use App\Goals\GoalsPlan;
use App\Goals\GoalState;
use App\Habits\HabitProgress;
use App\Models\Goal;
use App\Models\Record;
use App\Models\User;
use App\Money\ExchangeRates;
use App\Money\Plan;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * «Итог недели» (docs/PLAN.md, D39): plain numbers for a week from Monday, beside the week before, and the
 * records it brought. No score made of them: each number says what it is about.
 *
 *   limit   days within the daily limit, of the days the limit was written down
 *   habits  times done of times asked, over all habits
 *   goals   what went into the goals (not debts) in som, and what the main goal's part of it means in days
 *
 * On Monday «Сегодня» shows last week; on Sunday evening the bot sends the week still going.
 */
final class WeekSummary
{
    public function __construct(private Plan $plan, private GoalPlanner $planner, private ExchangeRates $rates) {}

    /** @return array<string, mixed> record lines in $locale, the user's own by default */
    public function for(User $user, Carbon $monday, Carbon $now, ?string $locale = null): array
    {
        $monday = $monday->copy()->startOfWeek(Carbon::MONDAY);
        $sunday = $monday->copy()->addDays(6);
        $goals = $this->planner->plan($user, $now);
        $locale ??= $user->locale ?? 'ru';

        return [
            'from' => $monday->toDateString(),
            'to' => $sunday->toDateString(),
            ...$this->numbers($user, $monday, $now, $goals),
            'prev' => $this->numbers($user, $monday->copy()->subWeek(), $now, $goals),
            'records' => $user->records()
                ->where('date', '>=', $monday->toDateString())->where('date', '<=', $sunday->toDateString())
                ->orderByDesc('date')->orderByDesc('id')->get()
                ->map(fn (Record $r) => $r->toApi($locale))->values()->all(),
        ];
    }

    /**
     * The week shown on «Сегодня» on a Monday: the one that just ended.
     *
     * @return array<string, mixed>
     */
    public function lastWeek(User $user, Carbon $now, ?string $locale = null): array
    {
        return $this->for($user, $now->copy()->startOfWeek(Carbon::MONDAY)->subWeek(), $now, $locale);
    }

    /** @return array{limit: array{kept: int, days: int}|null, habits: array{done: int, asked: int}|null, goals_uzs: int, main: array{id: int, title: string, days: int}|null} */
    private function numbers(User $user, Carbon $monday, Carbon $now, GoalsPlan $goals): array
    {
        $today = $now->copy()->startOfDay();
        $end = $monday->copy()->addDays(6)->min($today);

        $limit = null;
        if ($end->gte($monday)) {
            $rows = $user->dayResults()->whereNotNull('limit_uzs')
                ->where('date', '>=', $monday->toDateString())->where('date', '<=', $end->toDateString())
                ->pluck('limit_uzs', 'date')
                ->mapWithKeys(fn ($value, $date) => [substr((string) $date, 0, 10) => (int) $value]);
            if ($rows->isNotEmpty()) {
                $spent = $this->plan->everydaySpentByDay($user, $monday, $end);
                $limit = ['kept' => $rows->filter(fn (int $value, string $date) => ($spent[$date] ?? 0) <= $value)->count(), 'days' => $rows->count()];
            }
        }

        $done = $asked = 0;
        foreach ($user->habits()->whereNull('archived_at')->get() as $habit) {
            $week = HabitProgress::for($habit, $today)->week($monday);
            $done += $week['done'];
            $asked += $week['asked'];
        }

        // What went into each goal that week, in som at today's rate.
        $from = $monday->copy()->startOfDay();
        $to = $monday->copy()->addWeek()->startOfDay()->min($now->copy()->addSecond());
        $added = $goals->states
            ->filter(fn (GoalState $s) => $s->goal->hasMoney() && $s->goal->type !== 'debt' && $s->goal->account_id !== null)
            ->mapWithKeys(fn (GoalState $s) => [$s->goal->id => $this->inUzs(max(0, $this->planner->netChange($s->goal->account_id, $from, $to)), $s->goal, $now)]);

        $main = $goals->mainSaving();
        $mainAdded = $main === null ? 0 : (int) ($added[$main->goal->id] ?? 0);

        return [
            'limit' => $limit,
            'habits' => $asked > 0 ? ['done' => $done, 'asked' => $asked] : null,
            'goals_uzs' => (int) $added->sum(),
            // The same arithmetic as «Можно ли купить?»: a sum is that many days of the main goal's contributions.
            'main' => $main !== null && $mainAdded > 0
                ? ['id' => $main->goal->id, 'title' => $main->goal->title, 'days' => (int) round($mainAdded / $main->monthlyUzs * GoalMath::MONTH_DAYS)]
                : null,
        ];
    }

    private function inUzs(int $amount, Goal $goal, Carbon $now): int
    {
        $currency = $goal->currency ?? 'UZS';
        $rate = $this->rates->rateFor($currency, $now);

        return $rate === null ? 0 : Money::toUzs($amount, $currency, $rate);
    }
}
