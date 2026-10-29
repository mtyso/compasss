<?php

namespace App\Streaks;

use App\Advisor\DailyLimit;
use App\Goals\GoalPlanner;
use App\Goals\GoalsPlan;
use App\Goals\GoalState;
use App\Models\ContributionPeriod;
use App\Models\User;
use App\Money\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The money streaks (docs/PLAN.md, D38), counted by StreakCounter like a habit's:
 *
 *   limit          days whose everyday spending stayed within that day's limit, from the first day written
 *                  down by streaks:close-day; a day not written down counts neither way
 *   records        days with an operation or «Трат не было», from the first operation
 *   contributions  pay periods whose contributions to goals were all made before the next income; no spares,
 *                  a missed contribution moves the goal for real
 *
 * Today never breaks a streak while it lasts: a kept day or period adds to it, an open one waits.
 */
final class MoneyStreaks
{
    /** How far back a streak is counted, as for habits. */
    public const HORIZON_DAYS = 400;

    public function __construct(private Plan $plan, private GoalPlanner $planner) {}

    /**
     * All three, for «Сегодня» and the bot. $limit and $goals when the caller already has them.
     *
     * @return array{limit: array<string, mixed>|null, records: array<string, mixed>, contributions: array<string, mixed>}
     */
    public function for(User $user, Carbon $now, ?DailyLimit $limit = null, ?GoalsPlan $goals = null): array
    {
        $goals ??= $this->planner->plan($user, $now);
        $hasAccounts = $user->accounts()->whereNull('archived_at')->exists();

        return [
            'limit' => $hasAccounts ? $this->limit($user, $now, $limit ?? $this->plan->limit($user, $now, $goals)) : null,
            'records' => $this->records($user, $now),
            'contributions' => $this->contributions($user, $goals),
        ];
    }

    /** @return array<string, mixed> with `today`: within or over, and `covered`: a spare will take today's overrun */
    public function limit(User $user, Carbon $now, DailyLimit $limit): array
    {
        $today = $now->copy()->startOfDay();
        $rows = $user->dayResults()->whereNotNull('limit_uzs')
            ->where('date', '>=', $today->copy()->subDays(self::HORIZON_DAYS)->toDateString())
            ->where('date', '<', $today->toDateString())
            ->orderBy('date')
            ->pluck('limit_uzs', 'date')
            ->mapWithKeys(fn ($value, $date) => [substr((string) $date, 0, 10) => (int) $value]);

        $periods = [];
        if ($rows->isNotEmpty()) {
            $first = Carbon::parse($rows->keys()->first());
            $spent = $this->plan->everydaySpentByDay($user, $first, $today->copy()->subDay());
            for ($day = $first->copy(); $day->lt($today); $day->addDay()) {
                $date = $day->toDateString();
                $periods[] = match (true) {
                    ! $rows->has($date) => StreakCounter::PAUSED,
                    ($spent[$date] ?? 0) <= $rows[$date] => StreakCounter::KEPT,
                    default => StreakCounter::MISSED,
                };
            }
        }
        $periods[] = StreakCounter::OPEN;

        $streak = $this->streak($periods, 'day');
        $over = $limit->leftTodayUzs < 0;

        return [...$streak, 'today' => $over ? 'over' : 'within', 'covered' => $over && $streak['spare'] > 0];
    }

    /** @return array<string, mixed> with `today`: kept or open, and `no_spending`: today is marked «Трат не было» */
    public function records(User $user, Carbon $now): array
    {
        $today = $now->copy()->startOfDay();
        $horizon = $today->copy()->subDays(self::HORIZON_DAYS);
        $days = $user->transactions()
            ->where('occurred_at', '>=', $horizon)
            ->where('occurred_at', '<', $today->copy()->addDay())
            ->pluck('occurred_at')
            ->map(fn ($at) => Carbon::parse($at)->toDateString());
        $marks = $user->dayResults()->where('no_spending', true)
            ->where('date', '>=', $horizon->toDateString())
            ->where('date', '<=', $today->toDateString())
            ->pluck('date')
            ->map(fn ($date) => substr((string) $date, 0, 10));
        $kept = array_flip([...$days->all(), ...$marks->all()]);

        $periods = [];
        if ($kept !== []) {
            for ($day = Carbon::parse(min(array_keys($kept))); $day->lte($today); $day->addDay()) {
                $date = $day->toDateString();
                $periods[] = isset($kept[$date]) ? StreakCounter::KEPT : ($day->eq($today) ? StreakCounter::OPEN : StreakCounter::MISSED);
            }
        }
        $todayKey = $today->toDateString();

        return [
            ...$this->streak($periods, 'day'),
            'today' => isset($kept[$todayKey]) ? 'kept' : 'open',
            'no_spending' => $marks->contains($todayKey),
        ];
    }

    /** All goals together: a period is kept when every goal got its contribution. @return array<string, mixed> */
    public function contributions(User $user, GoalsPlan $goals): array
    {
        $asked = $this->asked($goals);
        $current = match (true) {
            $asked->isEmpty() => StreakCounter::PAUSED,
            $asked->sum('due') === 0 => StreakCounter::KEPT,
            default => StreakCounter::OPEN,
        };
        $periods = $this->pastPeriods($user, $goals)
            ->map(fn (ContributionPeriod $p) => match (true) {
                $p->planned_uzs <= 0 => StreakCounter::PAUSED,
                $p->due_uzs <= 0 => StreakCounter::KEPT,
                default => StreakCounter::MISSED,
            })
            ->push($current)
            ->all();

        return [...$this->streak($periods, 'period'), 'today' => $this->todayOf($current)];
    }

    /** One goal's contributions, for its screen. @return array<string, mixed> */
    public function goal(User $user, GoalsPlan $goals, int $goalId): array
    {
        $state = $this->asked($goals)->first(fn (GoalState $s) => $s->goal->id === $goalId);
        $current = match (true) {
            $state === null => StreakCounter::PAUSED,
            $state->due === 0 => StreakCounter::KEPT,
            default => StreakCounter::OPEN,
        };
        $periods = $this->pastPeriods($user, $goals)
            ->map(function (ContributionPeriod $p) use ($goalId) {
                $entry = collect($p->goals ?? [])->firstWhere('id', $goalId);

                return match (true) {
                    $entry === null || ($entry['planned'] ?? 0) <= 0 => StreakCounter::PAUSED,
                    ($entry['due'] ?? 0) <= 0 => StreakCounter::KEPT,
                    default => StreakCounter::MISSED,
                };
            })
            ->push($current)
            ->all();

        return [...$this->streak($periods, 'period'), 'today' => $this->todayOf($current)];
    }

    /** The goals' plan for the API with the contribution streak beside it. @return array<string, mixed> */
    public function goalsApi(User $user, GoalsPlan $goals): array
    {
        return [...$goals->toApi(), 'streak' => $this->contributions($user, $goals)];
    }

    /** @return Collection<int, GoalState> money goals the period asks a contribution of */
    private function asked(GoalsPlan $goals): Collection
    {
        return $goals->states->filter(fn (GoalState $s) => $s->goal->hasMoney() && $s->goal->isActive() && $s->planned > 0)->values();
    }

    /** @return Collection<int, ContributionPeriod> periods over before the current one, oldest first */
    private function pastPeriods(User $user, GoalsPlan $goals): Collection
    {
        return $user->contributionPeriods()
            ->where('starts_on', '<', $goals->period->start->toDateString())
            ->orderByDesc('starts_on')
            ->limit(120)
            ->get()
            ->reverse()
            ->values();
    }

    private function todayOf(string $current): ?string
    {
        return match ($current) {
            StreakCounter::KEPT => 'kept',
            StreakCounter::OPEN => 'open',
            default => null,
        };
    }

    /**
     * @param  list<string>  $periods
     * @return array{current: int, best: int, previous_best: int, unit: string, spare: int, next: int|null}
     */
    private function streak(array $periods, string $unit): array
    {
        $count = $unit === 'day'
            ? StreakCounter::count($periods, StreakCounter::SPARE_EVERY_DAYS, StreakCounter::SPARE_MAX_DAYS)
            : StreakCounter::count($periods, 0, 0);

        return [
            'current' => $count['current'],
            'best' => $count['best'],
            'previous_best' => $count['previous'],
            'unit' => $unit,
            'spare' => $count['spare'],
            'next' => $unit === 'day' ? StreakCounter::nextMilestone($count['current'], 'day') : null,
        ];
    }
}
