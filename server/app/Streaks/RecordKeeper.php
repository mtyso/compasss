<?php

namespace App\Streaks;

use App\Advisor\AdvisorContext;
use App\Goals\GoalPlanner;
use App\Goals\GoalsPlan;
use App\Goals\GoalState;
use App\Habits\HabitProgress;
use App\Models\Account;
use App\Models\Habit;
use App\Models\Record;
use App\Models\User;
use App\Money\Balances;
use App\Money\MonthReport;
use App\Prayer\Prayers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Writes the records journal (docs/PLAN.md, D39): facts worth keeping, each once, dated when it happened.
 *
 *   streaks  a milestone (30, 100, 365 days; 4, 12, 52 weeks; 3, 6, 12 periods) and a new record, that is a
 *            run longer than every earlier one, from 7 days (4 weeks, 3 periods); a record line follows its
 *            run as it grows. Habits, the money streaks and the prayers (D40)
 *   goals    the first contribution, 25 / 50 / 75 / 100% collected (only steps above where the goal started),
 *            reached or bought; a debt goal its 25 / 50 / 75% paid
 *   money    a debt paid off, the cushion at 1 / 3 / 6 months of usual spending, last month in budget, last
 *            month's savings rate above every earlier month's
 *
 * Runs after a habit or a prayer is marked, after an operation and every hour with streaks:close-day. Every check is safe
 * to repeat: a fact is found by its `ref`, a line the user removed included.
 */
final class RecordKeeper
{
    public const MILESTONES = ['day' => [30, 100, 365], 'week' => [4, 12, 52], 'period' => [3, 6, 12]];

    public const RECORD_FROM = ['day' => 7, 'week' => 4, 'period' => 3];

    public const GOAL_STEPS = [25, 50, 75, 100];

    public const CUSHION_MONTHS = [1, 3, 6];

    /** Last month is judged from the 2nd: the 1st is left to record what its last day left out. */
    private const MONTH_FROM_DAY = 2;

    /** @var Collection<int, Record> */
    private Collection $added;

    public function __construct(private MoneyStreaks $streaks, private GoalPlanner $planner, private MonthReport $report, private Prayers $prayers)
    {
        $this->added = collect();
    }

    /** Everything, from streaks:close-day. @return Collection<int, Record> the lines added */
    public function all(User $user, Carbon $now): Collection
    {
        $this->added = collect();
        foreach ($user->habits()->whereNull('archived_at')->get() as $habit) {
            $this->checkHabit($user, $habit, $now);
        }
        $this->checkPrayer($user, $now);
        $goals = $this->planner->plan($user, $now);
        $this->checkMoney($user, $now, $goals);
        $this->checkCushion($user, $now);
        $this->checkMonth($user, $now);

        return $this->added;
    }

    /** One habit, right after it is marked. @return Collection<int, Record> */
    public function habit(User $user, Habit $habit, Carbon $now): Collection
    {
        $this->added = collect();
        $this->checkHabit($user, $habit, $now);

        return $this->added;
    }

    /** The prayer streak, right after a prayer is marked. @return Collection<int, Record> */
    public function prayer(User $user, Carbon $now): Collection
    {
        $this->added = collect();
        $this->checkPrayer($user, $now);

        return $this->added;
    }

    /** The money streaks, goals and debts, right after an operation. @return Collection<int, Record> */
    public function money(User $user, Carbon $now): Collection
    {
        $this->added = collect();
        $this->checkMoney($user, $now, $this->planner->plan($user, $now));

        return $this->added;
    }

    private function checkHabit(User $user, Habit $habit, Carbon $now): void
    {
        $run = HabitProgress::for($habit, $now)->run();
        $this->streak($user, 'h'.$habit->id, $run, ['name' => $habit->name]);
    }

    private function checkPrayer(User $user, Carbon $now): void
    {
        if (Prayers::on($user)) {
            $this->streak($user, 'prayer', $this->prayers->history($user, $now)['run']);
        }
    }

    private function checkMoney(User $user, Carbon $now, GoalsPlan $goals): void
    {
        foreach ($this->streaks->runs($user, $now, $goals) as $subject => $run) {
            $this->streak($user, $subject, $run);
        }
        $this->goals($user, $now, $goals);
        $this->debts($user, $now);
    }

    /**
     * @param  array{current: int, previous: int, unit: string, kept: list<string>}  $run
     * @param  array<string, mixed>  $payload
     */
    private function streak(User $user, string $subject, array $run, array $payload = []): void
    {
        $unit = $run['unit'];
        $payload['unit'] = $unit;
        foreach (self::MILESTONES[$unit] as $milestone) {
            if ($run['current'] >= $milestone) {
                $this->add($user, 'streak_milestone', $subject, "streak_milestone|{$subject}|{$milestone}", $milestone, $run['kept'][$milestone - 1], $payload);
            }
        }

        // A record beats every earlier run; the first run has nothing to beat. Its line is named by the run it
        // beat, which no later run shares, and follows this run while it grows.
        if ($run['previous'] === 0 || $run['current'] <= $run['previous'] || $run['current'] < self::RECORD_FROM[$unit]) {
            return;
        }
        $ref = "streak_record|{$subject}|{$run['previous']}";
        $date = $run['kept'][$run['current'] - 1];
        $line = $user->records()->withTrashed()->where('ref', $ref)->first();
        if ($line === null) {
            $this->add($user, 'streak_record', $subject, $ref, $run['current'], $date, $payload);
        } elseif (! $line->trashed() && $line->value < $run['current']) {
            $line->update(['value' => $run['current'], 'date' => $date, 'payload' => $payload]);
        }
    }

    private function goals(User $user, Carbon $now, GoalsPlan $plan): void
    {
        foreach ($plan->states as $state) {
            /** @var GoalState $state */
            $goal = $state->goal;
            $target = (int) $goal->target_amount;
            if (! $goal->hasMoney() || $target <= 0) {
                continue;
            }
            $subject = 'g'.$goal->id;
            $payload = ['name' => $goal->title, 'type' => $goal->type, 'currency' => $goal->currency ?? 'UZS'];
            $debt = $goal->type === 'debt';

            if ($goal->done_at !== null) {
                // A debt goal closes with its debt: that is «Долг закрыт».
                if (! $debt) {
                    $this->add($user, 'goal_done', $subject, "goal_done|{$subject}", null, $goal->done_at->toDateString(), $payload);
                }

                continue;
            }

            $start = $debt ? 0 : (int) $goal->start_amount;
            if (! $debt && $state->saved > $start) {
                $first = $goal->account_id === null ? null : $user->transactions()
                    ->where('type', 'transfer')->where('to_account_id', $goal->account_id)
                    ->where('occurred_at', '>=', $goal->startDate())
                    ->orderBy('occurred_at')->orderBy('id')->first();
                $this->add($user, 'goal_first', $subject, "goal_first|{$subject}", null,
                    $first?->occurred_at->toDateString() ?? $now->toDateString(),
                    [...$payload, 'amount' => $first !== null ? (int) $first->to_amount : $state->saved - $start]);
            }

            // Only the highest step crossed since the goal began: one line when a big sum crosses several.
            $percent = (int) floor($state->progress * 100);
            $from = intdiv($start * 100, $target);
            $step = collect(self::GOAL_STEPS)->filter(fn (int $s) => $s <= $percent && $s > $from && ! ($debt && $s === 100))->max();
            if ($step !== null && ! $user->records()->withTrashed()->where('kind', 'goal_step')->where('subject', $subject)->where('value', '>=', $step)->exists()) {
                $this->add($user, 'goal_step', $subject, "goal_step|{$subject}|{$step}", $step, $now->toDateString(), [...$payload, 'amount' => $state->saved]);
            }
        }
    }

    /** A debt is closed when its account, once owed on, is back to zero: dated by the last payment into it. */
    private function debts(User $user, Carbon $now): void
    {
        $accounts = $user->accounts()->where('type', 'debt')->get();
        if ($accounts->isEmpty()) {
            return;
        }
        $balances = Balances::forUser($user);
        foreach ($accounts as $account) {
            /** @var Account $account */
            if (($balances[$account->id] ?? 0) < 0) {
                continue;
            }
            $last = $user->transactions()
                ->where(fn ($q) => $q->where('account_id', $account->id)->orWhere('to_account_id', $account->id))
                ->max('occurred_at');
            if ($account->opening_balance >= 0 && $last === null) {
                continue;
            }
            $date = $last !== null ? Carbon::parse($last, config('app.timezone'))->toDateString() : $now->toDateString();
            $this->add($user, 'debt_closed', 'a'.$account->id, "debt_closed|a{$account->id}", null, $date, ['name' => $account->name]);
        }
    }

    /** The cushion against usual monthly spending, as the advisor measures it. */
    private function checkCushion(User $user, Carbon $now): void
    {
        $context = new AdvisorContext($user, $now, 'check');
        $typical = $context->typicalMonthly();
        if (! $typical || $context->cushionAccounts()->isEmpty()) {
            return;
        }
        $months = $context->cushionUzs() / $typical;
        $reached = collect(self::CUSHION_MONTHS)->filter(fn (int $m) => $m <= $months)->max();
        if ($reached !== null && ! $user->records()->withTrashed()->where('kind', 'cushion')->where('value', '>=', $reached)->exists()) {
            $this->add($user, 'cushion', '', "cushion|{$reached}", $reached, $now->toDateString());
        }
    }

    /** Last month, once, from the 2nd: in budget, and a savings rate above every earlier month the app saw whole. */
    private function checkMonth(User $user, Carbon $now): void
    {
        $month = $now->copy()->startOfMonth()->subMonthNoOverflow()->format('Y-m');
        $checked = "records:month:{$user->id}:{$month}";
        if ($now->day < self::MONTH_FROM_DAY || Cache::has($checked)) {
            return;
        }
        $report = $this->report->for($user, $month, $now);
        Cache::put($checked, true, $now->copy()->addDays(40));
        $context = new AdvisorContext($user, $now, 'check');
        $first = $context->firstTransactionAt();
        if (! $report['tracked'] || $first === null) {
            return;
        }
        $closed = $now->copy()->startOfMonth()->toDateString();

        $budget = $report['budget'];
        if ($budget !== null && $budget['spent_uzs'] <= $budget['limit_uzs']) {
            $this->add($user, 'month_budget', $month, "month_budget|{$month}", (int) round($budget['spent_uzs'] * 100 / max(1, $budget['limit_uzs'])), $closed,
                ['spent_uzs' => $budget['spent_uzs'], 'limit_uzs' => $budget['limit_uzs']]);
        }

        $rate = $report['rate'];
        if ($rate === null || $rate <= 0) {
            return;
        }
        $earlier = [];
        $tracked = $first->day <= 5 ? $first->copy()->startOfMonth() : $first->copy()->startOfMonth()->addMonth();
        for ($m = Carbon::createFromFormat('Y-m-d', "{$month}-01", config('app.timezone'))->subMonthNoOverflow(); $m->gte($tracked); $m->subMonthNoOverflow()) {
            ['income' => $income, 'expense' => $expense] = $context->monthTotals($m->format('Y-m'));
            $earlier[] = MonthReport::rate($income, $expense);
        }
        $earlier = array_filter($earlier, fn (?int $r) => $r !== null);
        if ($earlier !== [] && $rate > max($earlier)) {
            $this->add($user, 'savings_rate', $month, "savings_rate|{$month}", $rate, $closed);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function add(User $user, string $kind, string $subject, string $ref, ?int $value, string $date, array $payload = []): void
    {
        if ($user->records()->withTrashed()->where('ref', $ref)->exists()) {
            return;
        }
        $this->added->push($user->records()->create([
            'kind' => $kind,
            'subject' => $subject,
            'ref' => $ref,
            'value' => $value,
            'date' => $date,
            'payload' => $payload === [] ? null : $payload,
        ]));
    }
}
