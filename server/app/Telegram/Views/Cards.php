<?php

namespace App\Telegram\Views;

use App\Advisor\DailyLimit;
use App\Advisor\PurchaseVerdict;
use App\Habits\HabitProgress;
use App\Models\Category;
use App\Models\Habit;
use App\Models\Insight;
use App\Models\RecurringPayment;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Money\MonthSpending;
use App\Money\Plan;
use App\Telegram\Keyboard;
use App\Telegram\Phrase\ParsedPhrase;
use App\Telegram\Rich\Card;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Every card the bot sends, in the user's language. Layout notes: docs/BOT.md. */
class Cards
{
    public function __construct(private Plan $plan) {}

    public function start(User $user): Card
    {
        $l = $user->locale;

        return Card::make()
            ->heading(__('bot.start.title', [], $l), 2)
            ->paragraph(Card::e(__('bot.start.lead', [], $l)))
            ->list(array_map(fn (string $example) => '<code>'.Card::e($example).'</code>', __('bot.start.examples', [], $l)))
            ->paragraph(Card::e(__('bot.start.commands', [], $l)))
            // Without https there is no «Компас» button to point at (local tests): bot.start.footer_no_app.
            ->footer(Card::e(__(Keyboard::webAppAvailable() ? 'bot.start.footer' : 'bot.start.footer_no_app', [], $l)));
    }

    public function startKeyboard(User $user): Keyboard
    {
        return Keyboard::make()->row([Keyboard::webApp('🧭 '.__('bot.buttons.open_app', [], $user->locale), '/', 'primary')]);
    }

    public function message(string $html): Card
    {
        return Card::make()->paragraph($html);
    }

    public function noAccounts(User $user): Card
    {
        return $this->message(Card::e(__('bot.record.no_accounts', [], $user->locale)));
    }

    public function noAccountsKeyboard(User $user): Keyboard
    {
        return Keyboard::make()->row([Keyboard::webApp('➕ '.__('bot.buttons.add_account', [], $user->locale), '/more/accounts/new', 'primary')]);
    }

    // --- Operations -------------------------------------------------------------

    /** @param  iterable<Insight>  $insights  what the advisor found about this operation */
    public function transaction(Transaction $tx, User $user, ?Carbon $now = null, iterable $insights = []): Card
    {
        $l = $user->locale;
        $now ??= now();
        $category = $tx->category;
        $sign = $tx->type === 'income' ? '+' : '−';
        $what = $tx->note ?: Fmt::categoryName($category, $l);

        $card = Card::make()
            ->heading(__('bot.record.title', ['amount' => Fmt::money($tx->amount, $tx->currency, $l, $sign), 'what' => $what], $l), 2);

        $details = Card::e(implode('  ·  ', array_filter([
            Fmt::categoryLabel($category, $l),
            Fmt::accountLabel($tx->account),
            $tx->is_one_off ? __('bot.record.one_off', [], $l) : null,
        ])));

        if ($tx->trashed()) {
            return $card
                ->paragraph("<s>{$details}</s>")
                ->quote(Card::e(__('bot.record.undone', [], $l)));
        }

        $card->paragraph($details);
        // Recorded by hand, it paid a recurring payment (App\Money\PaymentMatcher): say so, and when it is due next.
        $payment = $tx->recurring_payment_id ? $user->recurringPayments()->find($tx->recurring_payment_id) : null;
        if ($payment !== null) {
            $card->paragraph('✅ '.Card::e(__('bot.record.counted_as_payment', ['name' => $payment->name, 'date' => Fmt::date($payment->dueDate(), $l)], $l)));
        }
        if ($tx->currency !== 'UZS') {
            $card->paragraph('<i>'.Card::e(__('bot.record.converted', ['uzs' => Fmt::uzs($tx->amount_uzs, $l)], $l)).'</i>');
        }

        $card->quote($this->limitNote($user, $tx->type, $now));

        return $this->withInsights($card, $insights, $l)
            ->footer(Card::e(__('bot.record.source', [], $l).' · '.Fmt::when($tx->occurred_at, $l, $now)));
    }

    public function transactionKeyboard(Transaction $tx, User $user): Keyboard
    {
        $l = $user->locale;

        if ($tx->trashed()) {
            return Keyboard::make()->row([Keyboard::callback('↩️ '.__('bot.buttons.restore', [], $l), "tx:restore:{$tx->id}", 'primary')]);
        }

        return Keyboard::make()
            ->row([$this->awaitsOneOff($tx, $user) ? Keyboard::callback('☝️ '.__('bot.buttons.one_off', [], $l), "tx:oneoff:{$tx->id}", 'primary') : null])
            ->row([$this->openContribution($tx, $user) ? Keyboard::callback('🎯 '.__('bot.buttons.contribute', [], $l), "tx:save:{$tx->id}", 'success') : null])
            ->row([
                Keyboard::callback('🗂 '.__('bot.buttons.category', [], $l), "tx:cats:{$tx->id}"),
                Keyboard::callback('💳 '.__('bot.buttons.account', [], $l), "tx:accs:{$tx->id}"),
            ])
            ->row([
                Keyboard::callback('✖️ '.__('bot.buttons.undo', [], $l), "tx:del:{$tx->id}", 'danger'),
                Keyboard::webApp('🧭 '.__('bot.buttons.open_app', [], $l), '/money'),
            ]);
    }

    /** The advisor called it a large expense and nobody has answered yet (Insight::action). */
    private function awaitsOneOff(Transaction $tx, User $user): bool
    {
        return ! $tx->is_one_off && $user->insights()
            ->where('rule', 'large_spend')
            ->whereIn('status', ['new', 'seen'])
            ->where('payload->transaction_id', $tx->id)
            ->exists();
    }

    /** The advisor's split of this income with goal contributions still to make: its «Отложить» (Insight::action). */
    public function openContribution(Transaction $tx, User $user): ?Insight
    {
        if ($tx->type !== 'income') {
            return null;
        }

        $insight = $user->insights()
            ->where('rule', 'income_split')
            ->where('period_key', "t{$tx->id}")
            ->whereIn('status', ['new', 'seen'])
            ->first();

        return $insight?->action() === 'contribute' ? $insight : null;
    }

    /** Main categories of the operation's kind; one with subcategories opens them. */
    public function categoryKeyboard(Transaction $tx, User $user): Keyboard
    {
        $categories = $user->categories()->where('kind', $tx->type)->whereNull('archived_at')->orderBy('sort')->get();
        $parents = $categories->whereNull('parent_id');
        $withChildren = $categories->whereNotNull('parent_id')->pluck('parent_id')->unique()->flip();

        return Keyboard::make()
            ->grid($parents->map(fn (Category $c) => Keyboard::callback(
                Fmt::categoryEmoji($c).' '.Fmt::categoryName($c, $user->locale),
                $withChildren->has($c->id) ? "tx:sub:{$tx->id}:{$c->id}" : "tx:cat:{$tx->id}:{$c->id}",
                $c->id === $tx->category_id || $c->id === $tx->category?->parent_id ? 'primary' : null,
            ))->values()->all(), 2)
            ->row([Keyboard::callback(__('bot.buttons.back', [], $user->locale), "tx:card:{$tx->id}")]);
    }

    public function subcategoryKeyboard(Transaction $tx, User $user, Category $parent): Keyboard
    {
        $l = $user->locale;
        $children = $user->categories()->where('parent_id', $parent->id)->whereNull('archived_at')->orderBy('sort')->get();

        return Keyboard::make()
            ->grid($children->map(fn (Category $c) => Keyboard::callback(
                Fmt::categoryEmoji($c, $parent).' '.Fmt::categoryName($c, $l),
                "tx:cat:{$tx->id}:{$c->id}",
                $c->id === $tx->category_id ? 'primary' : null,
            ))->values()->all(), 2)
            ->row([Keyboard::callback(Fmt::categoryEmoji($parent).' '.__('bot.buttons.whole', ['name' => Fmt::categoryName($parent, $l)], $l), "tx:cat:{$tx->id}:{$parent->id}")])
            ->row([Keyboard::callback(__('bot.buttons.back', [], $l), "tx:cats:{$tx->id}")]);
    }

    /** Accounts in the operation's currency: switching between currencies would change the amount. */
    public function accountKeyboard(Transaction $tx, User $user): Keyboard
    {
        $accounts = $user->accounts()->whereNull('archived_at')->where('currency', $tx->currency)->orderBy('sort')->orderBy('id')->get();

        return Keyboard::make()
            ->grid($accounts->map(fn ($a) => Keyboard::callback(
                Fmt::accountLabel($a),
                "tx:acc:{$tx->id}:{$a->id}",
                $a->id === $tx->account_id ? 'primary' : null,
            ))->values()->all(), 2)
            ->row([Keyboard::callback(__('bot.buttons.back', [], $user->locale), "tx:card:{$tx->id}")]);
    }

    /** The line under a new operation: what is left for today, or by how much it is over. */
    private function limitNote(User $user, string $type, Carbon $now): string
    {
        $l = $user->locale;
        $limit = $this->plan->limit($user, $now);

        if ($type === 'income') {
            $lines = [__('bot.record.limit_now', ['limit' => Card::e(Fmt::uzs($limit->limitUzs, $l))], $l)];
        } elseif ($limit->leftTodayUzs >= 0) {
            $lines = [__('bot.record.left_today', ['left' => Card::e(Fmt::uzs($limit->leftTodayUzs, $l)), 'limit' => Card::e(Fmt::uzs($limit->limitUzs, $l))], $l)];
        } else {
            $lines = [__('bot.record.over_today', ['over' => Card::e(Fmt::uzs(-$limit->leftTodayUzs, $l))], $l)];
            if ($limit->daysLeft > 1) {
                // Tomorrow starts with what is free now, spread over one day less.
                $lines[] = Card::e(__('bot.record.tomorrow', ['amount' => Fmt::uzs(intdiv(max(0, $limit->freeUzs), $limit->daysLeft - 1), $l)], $l));
            }
        }
        $lines[] = Card::e($this->untilText($limit, $l));

        return implode(' ', $lines);
    }

    private function untilText(DailyLimit $limit, string $l): string
    {
        $days = Fmt::days($limit->daysLeft, $l);

        return $limit->estimatedEnd
            ? __('bot.record.until_month', ['days' => $days], $l)
            : __('bot.record.until', ['date' => Fmt::date($limit->nextIncome, $l), 'days' => $days], $l);
    }

    // --- «Можно ли купить?» (App\Advisor\CanAfford) ---------------------------

    private const VERDICT_EMOJI = ['yes' => '🟢', 'yes_but' => '🟡', 'wait' => '🟠', 'no' => '🔴'];

    public function verdict(User $user, ParsedPhrase $phrase, PurchaseVerdict $verdict): Card
    {
        $l = $user->locale;
        $answer = $verdict->toApi($l);
        $amount = Fmt::money($phrase->amount, $phrase->currency, $l);
        if ($phrase->currency !== 'UZS') {
            $amount .= ' ≈ '.Fmt::uzs($verdict->amountUzs, $l);
        }

        return Card::make()
            ->heading(self::VERDICT_EMOJI[$verdict->verdict].' '.$answer['title'], 2)
            ->paragraph(Card::e($phrase->note !== ''
                ? __('bot.question.what', ['what' => $phrase->note, 'amount' => $amount], $l)
                : __('bot.question.what_plain', ['amount' => $amount], $l)))
            ->list(array_map(fn (string $reason) => Card::e($reason), $answer['reasons']))
            ->footer(Card::e(__('bot.question.footer', [], $l)));
    }

    /** «Купил — записать» keeps the phrase for two days under $key (RecordFlow::onBuy). */
    public function verdictKeyboard(User $user, PurchaseVerdict $verdict, ?string $key): Keyboard
    {
        $l = $user->locale;

        return Keyboard::make()
            ->row([$key ? Keyboard::callback('✅ '.__('bot.buttons.bought', [], $l), "buy:{$key}", in_array($verdict->verdict, ['yes', 'yes_but'], true) ? 'success' : null) : null])
            ->row([Keyboard::webApp('🧭 '.__('bot.buttons.open_app', [], $l), '/')]);
    }

    // --- The advisor's insights ----------------------------------------------------

    private const SEVERITY_EMOJI = ['alert' => '🔴', 'warn' => '🟠', 'good' => '🟢', 'info' => '💡'];

    /** One insight on its own (App\Telegram\Notifier). */
    public function insight(Insight $insight, User $user): Card
    {
        $l = $user->locale;
        ['title' => $title, 'body' => $body] = $insight->text($l);

        $card = Card::make()
            ->heading(self::SEVERITY_EMOJI[$insight->severity].' '.$title, 3)
            ->paragraph(Card::e($body));

        if ($insight->rule === 'forgot_to_record') {
            // In the chat the answer is one phrase away.
            $card->paragraph(__('bot.insight.record_hint', [], $l));
        }

        return $card;
    }

    public function insightKeyboard(Insight $insight, User $user, bool $answered = false): Keyboard
    {
        $l = $user->locale;
        $keyboard = Keyboard::make();

        if (! $answered) {
            $keyboard
                ->row([match ($insight->action()) {
                    'mark_one_off' => Keyboard::callback('☝️ '.__('bot.buttons.one_off', [], $l), "ins:oneoff:{$insight->id}", 'primary'),
                    'contribute' => Keyboard::callback('🎯 '.__('bot.buttons.contribute', [], $l), "ins:save:{$insight->id}", 'success'),
                    'pay' => Keyboard::callback('✅ '.__('bot.buttons.paid', ['name' => $insight->payload['name'] ?? ''], $l), "ins:pay:{$insight->id}", 'success'),
                    'returned' => Keyboard::callback('✅ '.__('bot.buttons.returned', [], $l), "ins:returned:{$insight->id}", 'success'),
                    'roll' => Keyboard::callback('➡️ '.__('bot.buttons.roll', [], $l), "ins:roll:{$insight->id}", 'primary'),
                    default => null,
                }])
                ->row([
                    Keyboard::callback('👍 '.__('bot.buttons.useful', [], $l), "ins:useful:{$insight->id}"),
                    Keyboard::callback('🔕 '.__('bot.buttons.mute', [], $l), "ins:mute:{$insight->id}"),
                ]);
        }

        return $keyboard->row([Keyboard::webApp('🧭 '.__('bot.buttons.open_app', [], $l), '/advice')]);
    }

    /** @param  iterable<Insight>  $insights */
    private function withInsights(Card $card, iterable $insights, string $l): Card
    {
        foreach ($insights as $insight) {
            ['title' => $title, 'body' => $body] = $insight->text($l);
            $card->heading(self::SEVERITY_EMOJI[$insight->severity].' '.$title, 4)->paragraph(Card::e($body));
        }

        return $card;
    }

    // --- /today and the morning digest -------------------------------------------

    /** @param  iterable<Insight>  $insights  the morning summary carries the night's advice */
    public function today(User $user, ?Carbon $now = null, bool $morning = false, iterable $insights = []): Card
    {
        $l = $user->locale;
        $now ??= now();
        $today = $now->copy()->startOfDay();
        $limit = $this->plan->limit($user, $now);

        $title = match (true) {
            $morning && $limit->leftTodayUzs > 0 => __('bot.digest.morning_title', ['amount' => Fmt::uzs($limit->leftTodayUzs, $l)], $l),
            $morning => __('bot.digest.morning_over', [], $l),
            $limit->leftTodayUzs >= 0 => __('bot.today.title_left', ['amount' => Fmt::uzs($limit->leftTodayUzs, $l)], $l),
            default => __('bot.today.title_over', ['amount' => Fmt::uzs(-$limit->leftTodayUzs, $l)], $l),
        };

        $card = Card::make()
            ->heading($title, 2)
            ->paragraph(Card::e(__('bot.today.spent', ['limit' => Fmt::uzs($limit->limitUzs, $l), 'spent' => Fmt::uzs($limit->spentTodayUzs, $l)], $l)))
            ->facts(array_values(array_filter([
                [__('bot.today.free', [], $l), '<b>'.Card::e(Fmt::uzs($limit->freeUzs, $l)).'</b>'],
                $limit->estimatedEnd
                    ? [__('bot.today.until_month', [], $l), Card::e(Fmt::days($limit->daysLeft, $l))]
                    : [__('bot.today.until', [], $l), Card::e(__('bot.today.until_value', ['date' => Fmt::date($limit->nextIncome, $l), 'days' => Fmt::days($limit->daysLeft, $l)], $l))],
                $limit->upcomingUzs > 0 ? [__('bot.today.reserved', [], $l), Card::e(Fmt::uzs($limit->upcomingUzs, $l))] : null,
            ])));

        $payments = $this->paymentsBeforeIncome($user, $today, $limit);
        if ($payments->isNotEmpty()) {
            $card->heading(__('bot.today.payments', [], $l), 4)
                ->list($payments->map(fn (RecurringPayment $p) => Card::e(__('bot.today.payment_line', [
                    'name' => $p->name,
                    'amount' => Fmt::money($p->amount, $p->currency, $l),
                    'date' => $p->dueDate()->lt($today) ? __('bot.today.overdue', [], $l) : Fmt::date($p->dueDate(), $l),
                ], $l)))->all());
        }

        if ($limit->estimatedEnd) {
            $card->quote(Card::e(__('bot.today.no_paydays', [], $l)));
        }

        $tasks = $this->tasksForToday($user, $today);
        if ($tasks->isNotEmpty()) {
            $shown = $tasks->take(self::TASKS_SHOWN);
            $lines = $shown->map(fn (Task $t) => $this->taskLine($t, $today, $l))->all();
            if ($tasks->count() > $shown->count()) {
                $lines[] = Card::e(__('bot.today.tasks_more', ['count' => $tasks->count() - $shown->count()], $l));
            }
            $card->heading(__('bot.today.tasks', [], $l), 4)->list($lines);
        }

        $habits = $this->habitsForToday($user, $today);
        if ($habits->isNotEmpty()) {
            $card->heading(__('bot.today.habits', [], $l), 4)
                ->list($habits->map(fn (array $h) => $this->habitLine($h['habit'], $h['progress'], $today, $l))->all());
        }

        return $this->withInsights($card, $insights, $l)->footer(Card::e(Fmt::longDate($today, $l)));
    }

    /**
     * «Оплачено» for what is due today or overdue, the first tasks to tick off, a tap for the habits
     * still open today, then the app.
     */
    public function todayKeyboard(User $user, ?Carbon $now = null): Keyboard
    {
        $l = $user->locale;
        $today = ($now ?? now())->copy()->startOfDay();

        $keyboard = Keyboard::make();
        $user->recurringPayments()->where('active', true)->orderBy('next_due_on')->get()
            ->filter(fn (RecurringPayment $p) => $p->dueDate()->lte($today))
            ->take(3)
            ->each(fn (RecurringPayment $p) => $keyboard->row([Keyboard::callback('✅ '.__('bot.buttons.paid', ['name' => $p->name], $l), "pay:{$p->id}", 'success')]));

        $this->tasksForToday($user, $today)->take(3)
            ->each(fn (Task $t) => $keyboard->row([Keyboard::callback('☑️ '.$t->title, "task:done:{$t->id}")]));

        // A check habit already done needs no button; a count or limit one can always take one more.
        $keyboard->grid($this->habitsForToday($user, $today)
            ->reject(fn (array $h) => $h['habit']->type === 'check' && $h['progress']->stateToday() === 'done')
            ->take(4)
            ->map(fn (array $h) => Keyboard::callback(($h['habit']->type === 'check' ? '✓ ' : '+1 ').$h['habit']->name, "habit:inc:{$h['habit']->id}"))
            ->values()->all(), 2);

        return $keyboard->row([Keyboard::webApp('🧭 '.__('bot.buttons.open_app', [], $l), '/')]);
    }

    private const TASKS_SHOWN = 6;

    /**
     * Open tasks due today or earlier, in the order «Сегодня» shows them (TodayController).
     *
     * @return Collection<int, Task>
     */
    private function tasksForToday(User $user, Carbon $today): Collection
    {
        return $user->tasks()->whereNull('done_at')->whereNotNull('due_on')->where('due_on', '<=', $today->toDateString())
            ->orderBy('due_on')->orderByDesc('priority')->orderBy('due_time')->orderBy('id')
            ->get();
    }

    /**
     * Habits that are part of today, as «Сегодня» picks them: not the ones off today.
     *
     * @return Collection<int, array{habit: Habit, progress: HabitProgress}>
     */
    private function habitsForToday(User $user, Carbon $today): Collection
    {
        return $user->habits()->whereNull('archived_at')->orderBy('sort')->orderBy('id')->get()
            ->map(fn (Habit $h) => ['habit' => $h, 'progress' => HabitProgress::for($h, $today)])
            ->reject(fn (array $h) => $h['progress']->stateToday() === 'off')
            ->values();
    }

    /** «❗ 10:00 Позвонить в банк · просрочено». */
    private function taskLine(Task $task, Carbon $today, string $l): string
    {
        $line = ($task->priority > 0 ? str_repeat('❗', $task->priority).' ' : '')
            .($task->due_time ? '<b>'.Card::e(substr($task->due_time, 0, 5)).'</b> ' : '')
            .Card::e($task->title);

        return $task->dueDate()?->lt($today) ? $line.' · <i>'.Card::e(__('bot.today.task_overdue', [], $l)).'</i>' : $line;
    }

    /** «✅ Зарядка · 🔥 5 дней подряд», «⬜ Вода — 3 из 8 стаканов», «⚠️ Кофе — 3 при лимите 2». */
    private function habitLine(Habit $habit, HabitProgress $progress, Carbon $today, string $l): string
    {
        $state = $progress->stateToday();
        $mark = ['done' => '✅', 'over' => '⚠️'][$state] ?? '⬜';
        $text = $mark.' '.$habit->name;
        if ($habit->type !== 'check') {
            $text .= ' — '.$this->habitProgress($habit, $progress->value($today), $l);
        }

        $streak = $progress->streaks();
        if ($streak['current'] >= 2) {
            $text .= ' · 🔥 '.trans_choice($streak['unit'] === 'week' ? 'bot.today.streak_weeks' : 'bot.today.streak_days', $streak['current'], ['count' => $streak['current']], $l);
        }

        return Card::e($text);
    }

    /** «3 из 8 стаканов» for a count habit, «1, не больше 2» / «3 при лимите 2» for a limit. */
    public function habitProgress(Habit $habit, int $value, string $l): string
    {
        $key = match (true) {
            $habit->type === 'count' => 'bot.today.habit_count',
            $habit->keeps($value) => 'bot.today.habit_limit',
            default => 'bot.today.habit_over',
        };

        return trim(__($key, ['value' => $value, 'target' => $habit->target], $l).' '.($habit->unit ?? ''));
    }

    /** @return Collection<int, RecurringPayment> */
    private function paymentsBeforeIncome(User $user, Carbon $today, DailyLimit $limit): Collection
    {
        // The same ones the limit sets aside (App\Http\Controllers\TodayController).
        return $this->plan->unpaidPayments($user)
            ->filter(fn (RecurringPayment $p) => $p->dueDate()->lt($today->copy()->addDays($limit->daysLeft)))
            ->take(6)
            ->values();
    }

    // --- /month ------------------------------------------------------------------

    public function month(User $user, ?Carbon $now = null): Card
    {
        $l = $user->locale;
        $now ??= now();
        $month = $now->format('Y-m');
        [$start, $end] = MonthSpending::range($month);

        $spending = collect(MonthSpending::byCategory($user, $month))->sortByDesc('total');
        $total = $spending->sum('total');
        $income = (int) $user->transactions()->where('type', 'income')
            ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->sum('amount_uzs');

        $card = Card::make()->heading(__('bot.month.title', ['month' => Fmt::monthName($now, $l), 'amount' => Fmt::uzs($total, $l)], $l), 2);

        if ($spending->isEmpty() && $income === 0) {
            return $card->paragraph(Card::e(__('bot.month.empty', [], $l)));
        }

        $card->paragraph(Card::e(implode(' · ', array_filter([
            $income > 0 ? __('bot.month.income', ['amount' => Fmt::uzs($income, $l)], $l) : null,
            $spending->sum('one_off') > 0 ? __('bot.month.one_off', ['amount' => Fmt::uzs($spending->sum('one_off'), $l)], $l) : null,
        ]))));

        $categories = $user->categories()->whereIn('id', $spending->keys())->get()->keyBy('id');
        $budgets = $user->budgets()->where('month', $month)->pluck('limit_uzs', 'category_id');

        return $card
            ->table(
                [__('bot.month.category', [], $l), __('bot.month.spent', [], $l), __('bot.month.budget', [], $l)],
                $spending->take(12)->map(function (array $row, int $id) use ($categories, $budgets, $l) {
                    $category = $categories[$id] ?? null;
                    $budget = $budgets[$id] ?? null;
                    $share = $budget ? (int) round($row['total'] * 100 / max(1, $budget)) : null;

                    return [
                        Card::e(Fmt::categoryEmoji($category).' '.Fmt::categoryName($category, $l)),
                        Card::e(Fmt::number($row['total'])),
                        $share === null ? '—' : ($share > 100 ? "<b>{$share}%</b>" : "{$share}%"),
                    ];
                })->values()->all(),
            )
            ->footer(Card::e(Fmt::longDate($now->copy()->startOfDay(), $l)));
    }

    // --- Digests -----------------------------------------------------------------

    /** The last seven days against the seven before them. Null when both weeks are empty. */
    public function weekly(User $user, ?Carbon $now = null): ?Card
    {
        $l = $user->locale;
        $end = ($now ?? now())->copy()->startOfDay()->addDay();
        $start = $end->copy()->subDays(7);
        $before = $start->copy()->subDays(7);

        $spent = fn (Carbon $from, Carbon $to) => (int) $user->transactions()->where('type', 'expense')
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->sum('amount_uzs');
        $thisWeek = $spent($start, $end);
        $lastWeek = $spent($before, $start);

        if ($thisWeek === 0 && $lastWeek === 0) {
            return null;
        }

        $diff = $thisWeek - $lastWeek;
        $compare = match (true) {
            $diff < 0 => __('bot.digest.weekly_less', ['amount' => Fmt::uzs(-$diff, $l)], $l),
            $diff > 0 => __('bot.digest.weekly_more', ['amount' => Fmt::uzs($diff, $l)], $l),
            default => __('bot.digest.weekly_same', [], $l),
        };

        $top = $user->transactions()->where('transactions.type', 'expense')
            ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->selectRaw('COALESCE(categories.parent_id, categories.id) AS root_id, SUM(transactions.amount_uzs) AS total')
            ->groupBy('root_id')->orderByDesc('total')->limit(3)
            ->toBase()->get();
        $categories = $user->categories()->whereIn('id', $top->pluck('root_id'))->get()->keyBy('id');

        return Card::make()
            ->heading(__('bot.digest.weekly_title', ['amount' => Fmt::uzs($thisWeek, $l)], $l), 2)
            ->paragraph(Card::e($compare))
            ->heading(__('bot.digest.weekly_top', [], $l), 4)
            ->facts($top->map(fn ($row) => [
                Fmt::categoryEmoji($categories[$row->root_id] ?? null).' '.Fmt::categoryName($categories[$row->root_id] ?? null, $l),
                Card::e(Fmt::uzs((int) $row->total, $l)),
            ])->all())
            ->footer(Card::e(Fmt::date($start, $l).' — '.Fmt::date($end->copy()->subDay(), $l)));
    }
}
