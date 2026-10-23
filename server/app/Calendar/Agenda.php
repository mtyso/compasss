<?php

namespace App\Calendar;

use App\Models\Account;
use App\Models\Event;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\RecurringPayment;
use App\Models\RoutineBlock;
use App\Models\Task;
use App\Models\User;
use App\Money\Balances;
use App\Support\MonthDay;
use App\Support\Repeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the calendar shows for a range of days: the user's own events and, on the same grid, the dates the app
 * already knows about (tasks, recurring payments, paydays, goal deadlines, money lent and promised back, and for
 * a day or a week the habits), with the daily routine to lay underneath. One query per kind. Every date is
 * compared with the `$now` passed in, so the bot and the tests can ask about any moment.
 */
class Agenda
{
    public const TYPES = ['event', 'task', 'payment', 'income', 'goal', 'lent', 'habit'];

    /** Habits are shown day by day, so only in a range this short. */
    private const HABIT_DAYS = 7;

    /**
     * Items keyed uniquely in the feed, routine occurrences, and the events behind the event items by id
     * (the series an exception belongs to as well).
     *
     * @param  list<string>|null  $types  the item types to gather, all by default; the routine always comes
     * @return array{items: list<array<string, mixed>>, routine: list<array<string, mixed>>, series: array<int, array<string, mixed>>}
     */
    public function between(User $user, Carbon $from, Carbon $to, Carbon $now, ?array $types = null): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $today = $now->copy()->startOfDay();
        $wants = fn (string $type) => $types === null || in_array($type, $types, true);
        $blocks = $user->routineBlocks()->with('habit')->orderBy('start_time')->orderBy('id')->get();

        [$items, $series] = $wants('event') ? $this->events($user, $from, $to) : [[], []];
        $items = [
            ...$items,
            ...($wants('task') ? $this->tasks($user, $from, $to, $today) : []),
            ...($wants('payment') ? $this->payments($user, $from, $to, $today) : []),
            ...($wants('income') ? $this->incomes($user, $from, $to) : []),
            ...($wants('goal') ? $this->goals($user, $from, $to) : []),
            ...($wants('lent') ? $this->lent($user, $from, $to, $today) : []),
            ...($wants('habit') && Event::daysBetween($from, $to) < self::HABIT_DAYS ? $this->habits($user, $from, $to, $today, $blocks) : []),
        ];
        usort($items, fn (array $a, array $b) => [$a['date'], isset($a['start']), $a['start'] ?? '', $a['key']]
            <=> [$b['date'], isset($b['start']), $b['start'] ?? '', $b['key']]);

        return ['items' => $items, 'routine' => $this->routine($blocks, $from, $to), 'series' => $series];
    }

    /**
     * One day: all-day things first, then by the time they start that day (a night from the day before at 00:00).
     *
     * @param  list<string>|null  $types
     * @return array{items: list<array<string, mixed>>, routine: list<array<string, mixed>>, series: array<int, array<string, mixed>>}
     */
    public function day(User $user, Carbon $day, Carbon $now, ?array $types = null): array
    {
        $agenda = $this->between($user, $day, $day, $now, $types);
        $date = $day->toDateString();
        $order = fn (array $item) => [
            isset($item['start']),
            isset($item['start']) ? ($item['date'] < $date ? '00:00' : $item['start']) : '',
            $item['key'],
        ];
        usort($agenda['items'], fn (array $a, array $b) => $order($a) <=> $order($b));

        return $agenda;
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function events(User $user, Carbon $from, Carbon $to): array
    {
        // An occurrence of a long event may start weeks before the range and still reach into it.
        $events = $user->events()
            ->where('starts_on', '<=', $to->toDateString())
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('repeat')->where('ends_on', '>=', $from->toDateString()))
                ->orWhere(fn ($q) => $q->whereNotNull('repeat')->where(fn ($q) => $q
                    ->whereNull('repeat_until')->orWhere('repeat_until', '>=', $from->copy()->subDays(Event::MAX_SPAN_DAYS)->toDateString()))))
            ->get();

        $items = [];
        $series = [];
        foreach ($events as $event) {
            foreach (Occurrences::of($event, $from, $to) as $occurrence) {
                $series[$event->id] ??= $event->toApi();
                $items[] = [
                    'type' => 'event',
                    'key' => "e{$event->id}:{$occurrence['date']}",
                    'date' => $occurrence['date'],
                    'title' => $event->title,
                    'event_id' => $event->id,
                    'series_id' => $event->series_id,
                    'end_date' => $occurrence['end_date'],
                    'start' => $event->start_time,
                    'end' => $event->end_time,
                    'all_day' => $event->allDay(),
                    'color' => $event->color,
                    'repeating' => $event->repeating() || $event->series_id !== null,
                    'remind_before' => $event->remind_before,
                    'notes' => $event->notes,
                ];
            }
        }

        // The series an exception belongs to, for its sheet, even when the series itself has nothing in the range.
        $missing = collect($series)->pluck('series_id')->filter()->unique()->reject(fn (int $id) => isset($series[$id]));
        if ($missing->isNotEmpty()) {
            $loaded = $events->whereIn('id', $missing->all());
            $rest = $missing->diff($loaded->pluck('id'));
            foreach ($loaded->concat($rest->isEmpty() ? [] : $user->events()->whereIn('id', $rest->all())->get()) as $parent) {
                $series[$parent->id] = $parent->toApi();
            }
        }

        return [$items, $series];
    }

    /** @return list<array<string, mixed>> */
    private function tasks(User $user, Carbon $from, Carbon $to, Carbon $today): array
    {
        // Tasks dated in the range, and open repeating ones from before it that come round again inside it.
        $tasks = $user->tasks()
            ->where('due_on', '<=', $to->toDateString())
            ->where(fn ($q) => $q
                ->where('due_on', '>=', $from->toDateString())
                ->orWhere(fn ($q) => $q->whereNull('done_at')->whereNotNull('repeat')))
            ->get();

        $items = [];
        foreach ($tasks as $task) {
            $due = $task->dueDate();
            $open = $task->done_at === null;
            if ($due->between($from, $to)) {
                $items[] = $this->taskItem($task, $due, done: ! $open, overdue: $open && $due->lt($today), projected: false);
            }
            if ($open && $task->repeat) {
                // Where it moves once ticked off (Task::complete), and on from there.
                foreach (Occurrences::dates($task->repeat, Repeat::nextAfter($task->repeat, $due, $today), $from, $to) as $date) {
                    $items[] = $this->taskItem($task, $date, done: false, overdue: false, projected: true);
                }
            }
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function taskItem(Task $task, Carbon $date, bool $done, bool $overdue, bool $projected): array
    {
        return [
            'type' => 'task',
            'key' => "t{$task->id}:{$date->toDateString()}",
            'date' => $date->toDateString(),
            'title' => $task->title,
            'task_id' => $task->id,
            'start' => $task->due_time,
            'priority' => $task->priority,
            'done' => $done,
            'overdue' => $overdue,
            'projected' => $projected,
            'repeating' => $task->repeat !== null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function payments(User $user, Carbon $from, Carbon $to, Carbon $today): array
    {
        $payments = $user->recurringPayments()->get()->keyBy('id');
        if ($payments->isEmpty()) {
            return [];
        }

        $items = [];
        foreach ($payments->filter(fn (RecurringPayment $p) => $p->active) as $payment) {
            $due = $payment->dueDate();
            // The pending due date, then the months after it as they come once it is paid (MonthDay::nextMonth).
            $monthly = ['unit' => 'month', 'every' => 1, 'day' => $payment->day_of_month];
            foreach (Occurrences::dates($monthly, $due, $from, $to) as $date) {
                $projected = ! $date->eq($due);
                $items[] = $this->paymentItem($payment, "p{$payment->id}:{$date->toDateString()}", $date->toDateString(), [
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'overdue' => ! $projected && $date->lt($today),
                    'projected' => $projected,
                    'paid' => false,
                ]);
            }
        }

        $paid = $user->transactions()->whereNotNull('recurring_payment_id')
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to->copy()->addDay())
            ->get();
        foreach ($paid as $transaction) {
            $payment = $payments->get($transaction->recurring_payment_id);
            if ($payment === null) {
                continue;
            }
            $items[] = $this->paymentItem($payment, "px{$transaction->id}", $transaction->occurred_at->copy()->timezone(config('app.timezone'))->toDateString(), [
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'overdue' => false,
                'projected' => false,
                'paid' => true,
                'transaction_id' => $transaction->id,
            ]);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function paymentItem(RecurringPayment $payment, string $key, string $date, array $fields): array
    {
        return ['type' => 'payment', 'key' => $key, 'date' => $date, 'title' => $payment->name, 'payment_id' => $payment->id, ...$fields];
    }

    /** @return list<array<string, mixed>> */
    private function incomes(User $user, Carbon $from, Carbon $to): array
    {
        $items = [];
        foreach ($user->incomeSchedules()->where('active', true)->get() as $income) {
            for ($month = $from->copy()->startOfMonth(); $month->lte($to); $month->addMonth()) {
                $date = MonthDay::in($month->year, $month->month, $income->day_of_month);
                if ($date->between($from, $to)) {
                    $items[] = [
                        'type' => 'income',
                        'key' => "i{$income->id}:{$date->toDateString()}",
                        'date' => $date->toDateString(),
                        'title' => $income->name,
                        'income_id' => $income->id,
                        'amount' => $income->expected_amount,
                        'currency' => $income->currency,
                    ];
                }
            }
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function goals(User $user, Carbon $from, Carbon $to): array
    {
        return $user->goals()->whereNull('done_at')->whereNull('archived_at')
            ->whereBetween('deadline', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(fn (Goal $goal) => [
                'type' => 'goal',
                'key' => "g{$goal->id}",
                'date' => $goal->deadlineDate()->toDateString(),
                'title' => $goal->title,
                'goal_id' => $goal->id,
                'goal_type' => $goal->type,
                'icon' => $goal->icon,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> «Мне должны»: the day money lent was promised back, while some is still out. */
    private function lent(User $user, Carbon $from, Carbon $to, Carbon $today): array
    {
        $accounts = $user->accounts()->where('type', 'lent')->whereNull('archived_at')
            ->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])
            ->get();
        if ($accounts->isEmpty()) {
            return [];
        }
        $balances = Balances::forUser($user);

        return $accounts
            ->filter(fn (Account $account) => ($balances[$account->id] ?? 0) > 0)
            ->map(fn (Account $account) => [
                'type' => 'lent',
                'key' => "l{$account->id}",
                'date' => $account->dueDate()->toDateString(),
                'title' => $account->name,
                'account_id' => $account->id,
                'amount' => $balances[$account->id],
                'currency' => $account->currency,
                'overdue' => $account->dueDate()->lt($today),
            ])
            ->values()
            ->all();
    }

    /**
     * Habits on the days they are part of: a habit on weekdays on those days; a «N раз в неделю» one on the
     * days it was done, and today while this week still needs it.
     *
     * @param  Collection<int, RoutineBlock>  $blocks
     * @return list<array<string, mixed>>
     */
    private function habits(User $user, Carbon $from, Carbon $to, Carbon $today, Collection $blocks): array
    {
        $habits = $user->habits()->whereNull('archived_at')->orderBy('sort')->orderBy('id')->get();
        if ($habits->isEmpty()) {
            return [];
        }
        // From the Monday of this week as well, for the weekly count.
        $monday = $today->copy()->startOfWeek(Carbon::MONDAY);
        $values = [];
        HabitLog::whereIn('habit_id', $habits->pluck('id'))
            ->whereBetween('date', [$from->min($monday)->toDateString(), $to->min($today)->toDateString()])
            ->get()
            ->each(function (HabitLog $log) use (&$values) {
                $values[$log->habit_id][substr((string) $log->date, 0, 10)] = $log->value;
            });

        $items = [];
        foreach ($habits as $habit) {
            $logged = $values[$habit->id] ?? [];
            $perWeek = $habit->perWeek();
            $since = $habit->startDate()->max($monday)->toDateString();
            $keptThisWeek = count(array_filter(
                $logged,
                fn (int $value, string $date) => $date >= $since && $date <= $today->toDateString() && $value > 0 && $habit->keeps($value),
                ARRAY_FILTER_USE_BOTH,
            ));

            for ($day = $from->copy()->max($habit->startDate()); $day->lte($to); $day->addDay()) {
                $date = $day->toDateString();
                $value = $logged[$date] ?? 0;
                $shown = $perWeek === null
                    ? $habit->isScheduledOn($day)
                    : isset($logged[$date]) || ($day->eq($today) && ! $habit->keeps($value) && $keptThisWeek < $perWeek);
                if (! $shown) {
                    continue;
                }
                $block = $blocks->first(fn (RoutineBlock $b) => $b->habit_id === $habit->id && $b->startsOn($day));
                $items[] = [
                    'type' => 'habit',
                    'key' => "h{$habit->id}:{$date}",
                    'date' => $date,
                    'title' => $habit->name,
                    'habit_id' => $habit->id,
                    'icon' => $habit->icon,
                    'color' => $habit->color,
                    'habit_type' => $habit->type,
                    'value' => $value,
                    'target' => $habit->target,
                    'unit' => $habit->unit,
                    'start' => $block?->start_time,
                    'state' => $this->habitState($habit, $day, $today, $value),
                ];
            }
        }

        return $items;
    }

    /** future (not loggable yet), done, over (a limit broken), open (still to do) or missed. */
    private function habitState(Habit $habit, Carbon $day, Carbon $today, int $value): string
    {
        if ($day->gt($today)) {
            return 'future';
        }
        // A limit habit is kept on a day nothing was logged.
        if ($habit->type === 'limit') {
            return $habit->keeps($value) ? 'done' : 'over';
        }
        if ($habit->keeps($value)) {
            return 'done';
        }

        // A weekly habit is never missed on a single day.
        return $day->eq($today) || $habit->perWeek() !== null ? 'open' : 'missed';
    }

    /**
     * Routine blocks on each day of the range, with the night from the day before reaching into it.
     *
     * @param  Collection<int, RoutineBlock>  $blocks
     * @return list<array<string, mixed>>
     */
    private function routine(Collection $blocks, Carbon $from, Carbon $to): array
    {
        $routine = [];
        for ($day = $from->copy()->subDay(); $day->lte($to); $day->addDay()) {
            foreach ($blocks as $block) {
                if (! $block->startsOn($day)) {
                    continue;
                }
                // One ending at 00:00 ends at midnight of its own day.
                $end = $block->overnight() && $block->end_time !== '00:00' ? $day->copy()->addDay() : $day->copy();
                if ($end->lt($from)) {
                    continue;
                }
                $routine[] = [
                    'block_id' => $block->id,
                    'key' => "r{$block->id}:{$day->toDateString()}",
                    'date' => $day->toDateString(),
                    'end_date' => $end->toDateString(),
                    'start' => $block->start_time,
                    'end' => $block->end_time,
                    'title' => $block->title,
                    'kind' => $block->kind,
                    'habit_id' => $block->activeHabitId(),
                    'remind' => $block->remind,
                ];
            }
        }

        return $routine;
    }
}
