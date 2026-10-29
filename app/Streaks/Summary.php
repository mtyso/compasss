<?php

namespace App\Streaks;

use App\Habits\HabitProgress;
use App\Models\Habit;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The streaks in one place for the bot (docs/PLAN.md, D38): the money streaks, and the streaks of a week
 * or more that end tonight unless something is done today, with no spare left to cover the miss: a habit
 * not marked yet (a weekly one on Sunday) and a day with nothing recorded. A limit already overspent is in
 * `money.limit` (`today` over, `covered` false): nothing can be done about it tonight.
 */
final class Summary
{
    /** Shorter streaks are not worth an evening message. */
    public const AT_RISK_FROM = 7;

    public function __construct(private MoneyStreaks $money) {}

    /**
     * @return array{money: array<string, mixed>, at_risk: list<array{kind: string, id: int|null, name: string|null, current: int, unit: string}>}
     */
    public function for(User $user, Carbon $now): array
    {
        $today = $now->copy()->startOfDay();
        $money = $this->money->for($user, $now);

        $atRisk = [];
        foreach ($user->habits()->whereNull('archived_at')->orderBy('sort')->orderBy('id')->get() as $habit) {
            /** @var Habit $habit */
            $progress = HabitProgress::for($habit, $today);
            $streak = $progress->streaks();
            $lastChance = $streak['unit'] === 'day' || $today->isSunday();
            if ($lastChance && $progress->stateToday() === 'open' && $streak['current'] >= self::AT_RISK_FROM && $streak['spare'] === 0) {
                $atRisk[] = ['kind' => 'habit', 'id' => $habit->id, 'name' => $habit->name, 'current' => $streak['current'], 'unit' => $streak['unit']];
            }
        }

        $records = $money['records'];
        if ($records['today'] === 'open' && $records['current'] >= self::AT_RISK_FROM && $records['spare'] === 0) {
            $atRisk[] = ['kind' => 'records', 'id' => null, 'name' => null, 'current' => $records['current'], 'unit' => 'day'];
        }

        return ['money' => $money, 'at_risk' => $atRisk];
    }
}
