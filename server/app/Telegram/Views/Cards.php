<?php

namespace App\Telegram\Views;

use App\Advisor\DailyLimit;
use App\Advisor\PurchaseVerdict;
use App\Calendar\Agenda;
use App\Habits\HabitProgress;
use App\Models\Category;
use App\Models\Habit;
use App\Models\Insight;
use App\Models\Record;
use App\Models\RecurringPayment;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Money\MonthReport;
use App\Money\MonthSpending;
use App\Money\Plan;
use App\Prayer\Prayers;
use App\Streaks\MoneyStreaks;
use App\Streaks\Summary;
use App\Streaks\WeekSummary;
use App\Telegram\Keyboard;
use App\Telegram\Phrase\ParsedPhrase;
use App\Telegram\Rich\Card;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Every card the bot sends, in the user's language. Layout notes: docs/BOT.md. */
class Cards
{
    public function __construct(
        private Plan $plan,
        private Agenda $agenda,
        private MoneyStreaks $streaks,
        private Summary $summary,
        private WeekSummary $weeks,
        private Prayers $prayers,
    ) {}

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

    // --- 21:00: «ничего не записано» and the streaks that end tonight ------------------

    /**
     * The one evening message (docs/BOT.md): the advisor's «Сегодня ничего не записано» when it came, and the
     * streaks of a week or more that end tonight unless marked, with no spare left (App\Streaks\Summary).
     * Null when there is neither.
     */
    public function evening(User $user, Carbon $now, ?Insight $forgot = null): ?Card
    {
        $l = $user->locale;
        $lines = array_map(fn (array $streak) => Card::e(trans_choice(match (true) {
            $streak['kind'] === 'records' => 'bot.evening.records',
            $streak['unit'] === 'week' => 'bot.evening.habit_week',
            default => 'bot.evening.habit',
        }, $streak['current'], ['count' => $streak['current'], 'name' => $streak['name'] ?? ''], $l)), $this->summary->for($user, $now)['at_risk']);

        if ($forgot === null && $lines === []) {
            return null;
        }

        $card = $forgot !== null ? $this->insight($forgot, $user) : Card::make()->heading(__('bot.evening.title', [], $l), 3);
        if ($lines !== []) {
            if ($forgot !== null) {
                $card->heading(__('bot.evening.at_risk', [], $l), 4);
            }
            $card->list($lines)->paragraph(Card::e(__('bot.evening.no_spare', [], $l)));
        }

        return $card;
    }

    /**
     * «Трат не было» while the day has nothing recorded, a mark for each habit at risk, then the question's
     * own buttons. The date in the buttons keeps a tap after midnight on the day it was meant for.
     */
    public function eveningKeyboard(User $user, Carbon $now, ?Insight $forgot = null): Keyboard
    {
        $l = $user->locale;
        $date = $now->toDateString();
        $atRisk = collect($this->summary->for($user, $now)['at_risk']);
        $keyboard = Keyboard::make();

        if ($forgot?->action() === 'no_spending' || $atRisk->contains('kind', 'records')) {
            $keyboard->row([Keyboard::callback('🙅 '.__('bot.buttons.no_spending', [], $l), "streak:nospend:{$date}", 'primary')]);
        }

        $streaks = $atRisk->where('kind', 'habit');
        $habits = $user->habits()->whereIn('id', $streaks->pluck('id'))->get()->keyBy('id');
        $keyboard->grid($streaks->filter(fn (array $s) => isset($habits[$s['id']]))
            ->map(fn (array $s) => Keyboard::callback(($habits[$s['id']]->type === 'check' ? '✓ ' : '+1 ').$habits[$s['id']]->name, "streak:habit:{$s['id']}:{$date}"))
            ->values()->all(), 2);

        if ($forgot !== null && in_array($forgot->status, ['new', 'seen'], true)) {
            $keyboard->row([
                Keyboard::callback('👍 '.__('bot.buttons.useful', [], $l), "ins:useful:{$forgot->id}"),
                Keyboard::callback('🔕 '.__('bot.buttons.mute', [], $l), "ins:mute:{$forgot->id}"),
            ]);
        }

        return $keyboard->row([Keyboard::webApp('🧭 '.__('bot.buttons.open_app', [], $l), '/')]);
    }

    // --- Milestones and records as they happen -----------------------------------------

    private const RECORD_EMOJI = [
        'streak_milestone' => '🔥', 'streak_record' => '🏆', 'goal_first' => '🎯', 'goal_step' => '🎯', 'goal_done' => '🎉',
        'debt_closed' => '✅', 'cushion' => '🛟', 'month_budget' => '📊', 'savings_rate' => '🏆',
    ];

    /** «🔥 Бег: 30 дней подряд», or several new lines under «Новое в рекордах». @param  Collection<int, Record>  $records */
    public function journal(Collection $records, User $user): Card
    {
        $l = $user->locale;
        if ($records->count() === 1) {
            return Card::make()->heading($this->recordLine($records->first(), $l), 3);
        }

        return Card::make()
            ->heading('🏆 '.__('bot.journal.title', [], $l), 3)
            ->list($records->map(fn (Record $r) => Card::e($this->recordLine($r, $l)))->values()->all());
    }

    /** The habit or goal of a single line, and the whole journal. @param  Collection<int, Record>  $records */
    public function journalKeyboard(Collection $records, User $user): Keyboard
    {
        $l = $user->locale;
        $ref = $records->count() === 1 ? $records->first()->toApi($l)['ref'] : null;

        return Keyboard::make()->row([
            match ($ref['type'] ?? null) {
                'habit' => Keyboard::webApp(__('bot.buttons.open_habit', [], $l), "/tasks/habits/{$ref['id']}"),
                'goal' => Keyboard::webApp(__('bot.buttons.open_goal', [], $l), "/goals/{$ref['id']}"),
                default => null,
            },
            Keyboard::webApp('🏆 '.__('bot.buttons.all_records', [], $l), '/goals/records'),
        ]);
    }

    private function recordLine(Record $record, string $l): string
    {
        return (self::RECORD_EMOJI[$record->kind] ?? '🏆').' '.$record->text($l);
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

        // The money streaks in one line, as under the limit in the app (docs/PLAN.md, D38); zeros left out.
        $streaks = $this->streaks->for($user, $now, $limit);
        $parts = array_filter([
            ($streaks['limit']['current'] ?? 0) > 0 ? __('bot.today.streak_limit', ['count' => $streaks['limit']['current']], $l) : null,
            $streaks['records']['current'] > 0 ? __('bot.today.streak_records', ['count' => $streaks['records']['current']], $l) : null,
        ]);
        if ($parts !== []) {
            $card->paragraph(Card::e('🔥 '.Str::ucfirst(implode(' · ', $parts))));
        }

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

        $plan = $this->planLines($user, $now, $l);
        if ($plan !== []) {
            $card->heading(__('bot.today.plan', [], $l), 4)->list($plan);
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

        // The five prayers in one line (docs/PLAN.md, D40): what is prayed, the one whose time is on, the rest.
        if (Prayers::on($user)) {
            $prayers = array_filter([$this->nightIsha($user, $now), ...$this->prayers->day($user, $today->toDateString(), $now)]);
            $card->heading(__('bot.prayer.today', [], $l), 4)
                ->paragraph(Card::e(implode(' · ', array_map(fn (array $p) => $this->prayerShort($p, $l), $prayers))));
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

        // The prayer whose time is on, if it is not marked yet: after midnight, yesterday's Isha.
        if (Prayers::on($user)) {
            $date = $today->toDateString();
            $prayers = array_filter([$this->nightIsha($user, $now ?? now()), ...array_map(fn (array $p) => $p + ['date' => $date], $this->prayers->day($user, $date, $now ?? now()))]);
            foreach ($prayers as $prayer) {
                if ($prayer['state'] === 'now') {
                    $keyboard->row([Keyboard::callback('🕌 '.__('bot.buttons.prayed_name', ['name' => __("prayer.names.{$prayer['name']}", [], $l)], $l), "prayer:t:{$prayer['date']}:{$prayer['name']}")]);
                }
            }
        }

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

    // --- Calendar ------------------------------------------------------------------

    private const PLAN_EVENTS = 5;

    /** Routine kinds (App\Models\RoutineBlock::KINDS) as the bot shows them. */
    private const ROUTINE_EMOJI = [
        'sleep' => '😴', 'work' => '💼', 'study' => '📚', 'sport' => '🏃', 'meal' => '🍽',
        'road' => '🚗', 'rest' => '🛋', 'family' => '👨‍👩‍👧', 'other' => '🕘',
    ];

    /**
     * «План дня» in /today and the morning summary, as on «Сегодня» in the app (TodayPlan.vue): the routine
     * block on now and the next one, then the events not over yet, timed by start and all-day last.
     *
     * @return list<string> html lines, none when nothing is on
     */
    private function planLines(User $user, Carbon $now, string $l): array
    {
        $now = $now->copy()->timezone(config('app.timezone'));
        $date = $now->toDateString();
        $minute = $now->hour * 60 + $now->minute;
        $day = $this->agenda->day($user, $now->copy()->startOfDay(), $now, ['event']);

        $current = $next = null;
        foreach ($day['routine'] as $block) {
            [$start, $end] = self::span($block, $date);
            // The shortest of the ones on now: a block inside a longer one is what is going on.
            if ($start <= $minute && $minute < $end && ($current === null || $start > $current[1] || ($start === $current[1] && $end < $current[2]))) {
                $current = [$block, $start, $end];
            }
            if ($start > $minute && ($next === null || $start < $next[1])) {
                $next = [$block, $start];
            }
        }

        $lines = [];
        if ($current !== null) {
            $lines[] = $this->routineLine($current[0], 'bot.today.plan_now', ['time' => $current[0]['end']], $l);
        }
        if ($next !== null) {
            $lines[] = $this->routineLine($next[0], 'bot.today.plan_next', ['time' => $next[0]['start']], $l);
        }

        $timed = collect($day['items'])->reject(fn (array $e) => self::allDay($e))
            ->map(fn (array $e) => [$e, ...self::span($e, $date)])
            ->filter(fn (array $s) => $s[2] > $minute)
            ->sortBy(fn (array $s) => $s[1])
            ->pluck(0);
        $events = $timed->concat(collect($day['items'])->filter(fn (array $e) => self::allDay($e)))->values();

        foreach ($events->take(self::PLAN_EVENTS) as $event) {
            $time = self::allDay($event) ? Str::ucfirst(__('bot.calendar.all_day', [], $l)) : self::hours($event['start'], $event['end']);
            $lines[] = '<b>'.Card::e($time).'</b> '.Card::e($event['title']);
        }
        if ($events->count() > self::PLAN_EVENTS) {
            $lines[] = Card::e(__('bot.today.tasks_more', ['count' => $events->count() - self::PLAN_EVENTS], $l));
        }

        return $lines;
    }

    /** «💼 Сейчас: Работа, до 18:00». */
    private function routineLine(array $block, string $key, array $replace, string $l): string
    {
        return (self::ROUTINE_EMOJI[$block['kind']] ?? self::ROUTINE_EMOJI['other']).' '
            .Card::e(__($key, ['title' => $block['title'], ...$replace], $l));
    }

    /**
     * A calendar reminder (App\Calendar\Reminders): what, and when, with «через 10 мин» while it is still
     * ahead today. A routine block reminds as it starts, under its own icon, so it only needs its hours.
     *
     * @param  array{kind: string, block_kind?: string|null, title: string, date: string, start: string|null, end: string|null, all_day: bool}  $reminder
     */
    public function reminder(array $reminder, User $user, ?Carbon $now = null): Card
    {
        if ($reminder['kind'] === 'prayer') {
            return $this->prayerReminder($reminder, $user, $now ?? now());
        }
        $l = $user->locale;
        $zone = config('app.timezone');
        $now = ($now ?? now())->copy()->timezone($zone);
        $date = Carbon::parse($reminder['date'], $zone);
        $allDay = $reminder['all_day'] || $reminder['start'] === null;
        $hours = $allDay ? __('bot.calendar.all_day', [], $l) : self::hours($reminder['start'], $reminder['end']);

        if ($reminder['kind'] === 'routine') {
            return Card::make()
                ->heading((self::ROUTINE_EMOJI[$reminder['block_kind'] ?? 'other'] ?? self::ROUTINE_EMOJI['other']).' '.$reminder['title'], 3)
                ->paragraph(Card::e(__('bot.calendar.routine', ['time' => $hours], $l)));
        }

        $when = __('bot.calendar.when', ['day' => match (true) {
            $date->isSameDay($now) => __('bot.calendar.today', [], $l),
            $date->isSameDay($now->copy()->addDay()) => __('bot.calendar.tomorrow', [], $l),
            default => Fmt::longDate($date, $l),
        }, 'time' => $hours], $l);

        if (! $allDay && $date->isSameDay($now)) {
            $minutes = (int) ceil($now->diffInMinutes(Carbon::parse("{$reminder['date']} {$reminder['start']}", $zone), false));
            $when .= ' · '.match (true) {
                $minutes < 0 => __('bot.calendar.started', [], $l),
                $minutes === 0 => __('bot.calendar.now', [], $l),
                $minutes < 60 => __('bot.calendar.in_minutes', ['m' => $minutes], $l),
                $minutes % 60 === 0 => __('bot.calendar.in_hours', ['h' => intdiv($minutes, 60)], $l),
                default => __('bot.calendar.in_hours_minutes', ['h' => intdiv($minutes, 60), 'm' => $minutes % 60], $l),
            };
        }

        return Card::make()
            ->heading('⏰ '.$reminder['title'], 3)
            ->paragraph(Card::e($when));
    }

    /** «Календарь»: the reminder's day in the Mini App. */
    public function reminderKeyboard(array $reminder, User $user): Keyboard
    {
        if ($reminder['kind'] === 'prayer') {
            return Keyboard::make()->row([
                Keyboard::callback('✅ '.__('bot.buttons.prayed', [], $user->locale), "prayer:r:{$reminder['date']}:{$reminder['prayer']}", 'success'),
                Keyboard::webApp('🕌 '.__('bot.buttons.prayer', [], $user->locale), '/tasks/prayer'),
            ]);
        }
        $path = $reminder['kind'] === 'routine' ? '/tasks/calendar/routine' : "/tasks/calendar?view=day&date={$reminder['date']}";

        return Keyboard::make()->row([Keyboard::webApp('📅 '.__('bot.buttons.calendar', [], $user->locale), $path)]);
    }

    /** «16:00–17:00», or «16:00» without an end. */
    private static function hours(string $start, ?string $end): string
    {
        return $end === null ? $start : "{$start}–{$end}";
    }

    private static function allDay(array $item): bool
    {
        return ($item['all_day'] ?? false) || ($item['start'] ?? null) === null;
    }

    /**
     * Where an item lies in a day, in minutes: from 0 when it began the day before, to 1440 when it runs
     * past midnight (lib/calendar daySegments in the app).
     *
     * @return array{0: int, 1: int}
     */
    private static function span(array $item, string $date): array
    {
        $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $start = $item['date'] < $date ? 0 : $minutes($item['start']);
        $end = match (true) {
            ($item['end_date'] ?? $item['date']) > $date, $item['end'] === '00:00' => 1440,
            $item['end'] === null => $start + 60,
            default => $minutes($item['end']),
        };

        return [$start, $end];
    }

    // --- Prayers (docs/PLAN.md, D40) ---------------------------------------------

    private const PRAYER_EMOJI = ['done' => '✅', 'qaza' => '☑️', 'now' => '⏳', 'missed' => '⚠️', 'later' => '▫️', 'skipped' => '▫️'];

    /**
     * The time to pray has come, or 20 minutes are left of it and the prayer is not marked yet. The minutes are
     * counted to the end, so a reminder sent late (prayers turned on a little before it) still tells the truth.
     */
    private function prayerReminder(array $reminder, User $user, Carbon $now): Card
    {
        $l = $user->locale;
        $isha = $reminder['prayer'] === 'isha';
        if ($reminder['phase'] === 'end') {
            $left = isset($reminder['until']) ? max(1, (int) ceil($now->diffInSeconds($reminder['until'], false) / 60)) : $reminder['remind_before'];

            return Card::make()
                ->heading(__($isha ? 'bot.prayer.last_title_isha' : 'bot.prayer.last_title', ['name' => $reminder['title'], 'm' => min($left, $reminder['remind_before'])], $l), 3)
                ->paragraph(Card::e(__($isha ? 'bot.prayer.last_text_isha' : 'bot.prayer.last_text', ['end' => $reminder['end']], $l)));
        }

        return Card::make()
            ->heading('🕌 '.$reminder['title'], 3)
            ->paragraph(Card::e(__($isha ? 'bot.prayer.time_isha' : 'bot.prayer.time', ['start' => $reminder['start'], 'end' => $reminder['end']], $l)));
    }

    /** What a reminder becomes after «Прочитал»: the prayer done, and when it was marked. */
    public function prayerDone(User $user, string $prayer, string $status, ?Carbon $markedAt = null): Card
    {
        $l = $user->locale;
        $name = __("prayer.names.{$prayer}", [], $l);

        return Card::make()
            ->heading(__($status === 'qaza' ? 'bot.prayer.qaza_title' : 'bot.prayer.done_title', ['name' => $name], $l), 3)
            ->paragraph(Card::e(__('bot.prayer.done_at', ['time' => ($markedAt ?? now())->copy()->timezone(config('app.timezone'))->format('H:i')], $l)));
    }

    /** /namaz while prayers are off: they are turned on in the app. */
    public function prayerOff(User $user): Card
    {
        return Card::make()
            ->heading(__('bot.prayer.today', [], $user->locale), 3)
            ->paragraph(Card::e(__('bot.prayer.off', [], $user->locale)));
    }

    public function prayerDoneKeyboard(User $user): Keyboard
    {
        return Keyboard::make()->row([Keyboard::webApp('🕌 '.__('bot.buttons.prayer', [], $user->locale), '/tasks/prayer')]);
    }

    /** /namaz: today's five with their times and marks, the streak and how many to make up. */
    public function prayerDay(User $user, ?Carbon $now = null): Card
    {
        $l = $user->locale;
        $now ??= now();
        $today = $now->copy()->timezone(config('app.timezone'))->startOfDay();
        $prayers = array_filter([$this->nightIsha($user, $now), ...$this->prayers->day($user, $today->toDateString(), $now)]);
        $card = Card::make()
            ->heading(__('bot.prayer.title', ['city' => Prayers::city($user)['name']], $l), 3)
            ->list(array_map(fn (array $p) => $this->prayerLine($p, $l), $prayers));

        $streak = $this->prayers->history($user, $now)['streak']['current'];
        if ($streak > 0) {
            $card->paragraph(Card::e(__('bot.prayer.streak', ['days' => Fmt::days($streak, $l)], $l)));
        }
        $missed = count($this->prayers->missed($user, $now));
        if ($missed > 0) {
            $card->paragraph(Card::e(__('bot.prayer.missed', ['count' => $missed], $l)));
        }

        return $card->footer(Card::e(Fmt::longDate($today, $l)));
    }

    /** «✅ Аср» for every prayer of today whose time is on or over and that is not marked yet, and the night's Isha. */
    public function prayerDayKeyboard(User $user, ?Carbon $now = null): Keyboard
    {
        $l = $user->locale;
        $now ??= now();
        $date = $now->copy()->timezone(config('app.timezone'))->toDateString();
        $prayers = array_filter([$this->nightIsha($user, $now), ...array_map(fn (array $p) => $p + ['date' => $date], $this->prayers->day($user, $date, $now))]);
        $keyboard = Keyboard::make()->grid(collect($prayers)
            ->filter(fn (array $p) => in_array($p['state'], ['now', 'missed'], true))
            ->map(fn (array $p) => Keyboard::callback('✅ '.__("prayer.names.{$p['name']}", [], $l), "prayer:d:{$p['date']}:{$p['name']}"))
            ->values()->all(), 3);

        return $keyboard->row([Keyboard::webApp('🕌 '.__('bot.buttons.prayer', [], $l), '/tasks/prayer')]);
    }

    /**
     * Yesterday's Isha while its time lasts and it is not marked: from midnight to Fajr, the prayer whose time is on.
     *
     * @return array{name: string, start: string, end: string, state: string, date: string}|null
     */
    private function nightIsha(User $user, Carbon $now): ?array
    {
        $yesterday = $now->copy()->timezone(config('app.timezone'))->subDay()->toDateString();
        $isha = collect($this->prayers->day($user, $yesterday, $now))->firstWhere('name', 'isha');

        return $isha !== null && $isha['state'] === 'now' ? $isha + ['date' => $yesterday] : null;
    }

    /** «✅ Бомдод 05:32», «⏳ Аср 15:39 — сейчас, до 17:25». */
    private function prayerLine(array $prayer, string $l): string
    {
        $state = match ($prayer['state']) {
            'now' => ' — '.__('bot.prayer.state_now', ['end' => $prayer['end']], $l),
            'missed' => ' — '.__('bot.prayer.state_missed', [], $l),
            'qaza' => ' — '.__('bot.prayer.state_qaza', [], $l),
            default => '',
        };

        return Card::e(self::PRAYER_EMOJI[$prayer['state']].' '.__("prayer.names.{$prayer['name']}", [], $l).' '.$prayer['start'].$state);
    }

    /** The same in a word or two, for the line in /today: «✅ Бомдод», «⏳ Аср до 17:25», «Шом 17:25». */
    private function prayerShort(array $prayer, string $l): string
    {
        $name = __("prayer.names.{$prayer['name']}", [], $l);

        return match ($prayer['state']) {
            'done', 'qaza', 'missed' => self::PRAYER_EMOJI[$prayer['state']].' '.$name,
            'now' => '⏳ '.$name.' '.__('bot.prayer.state_now', ['end' => $prayer['end']], $l),
            default => $name.' '.$prayer['start'],
        };
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

    /**
     * Sunday evening: the last seven days' spending against the seven before them, then «Итог недели»
     * (App\Streaks\WeekSummary): days in the limit, habits and goals beside the week before, plain numbers
     * with no score, and the week's records. Null when there is nothing to say.
     */
    public function weekly(User $user, ?Carbon $now = null): ?Card
    {
        $l = $user->locale;
        $now ??= now();
        $end = $now->copy()->startOfDay()->addDay();
        $start = $end->copy()->subDays(7);
        $before = $start->copy()->subDays(7);

        $spent = fn (Carbon $from, Carbon $to) => (int) $user->transactions()->where('type', 'expense')
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->sum('amount_uzs');
        $thisWeek = $spent($start, $end);
        $lastWeek = $spent($before, $start);
        $week = $this->weeks->for($user, $now->copy()->startOfWeek(Carbon::MONDAY), $now, $l);
        $facts = $this->weekFacts($week, $l);

        $spending = $thisWeek > 0 || $lastWeek > 0;
        if (! $spending && $facts === [] && $week['records'] === []) {
            return null;
        }

        $card = Card::make();
        if ($spending) {
            $this->weeklySpending($card, $user, $start, $end, $thisWeek, $lastWeek, $l);
        }
        if ($facts !== [] || ! $spending) {
            $card->heading(__('bot.week.title', [], $l), $spending ? 4 : 2)->facts($facts);
        }
        if ($week['main'] !== null) {
            $card->paragraph(Card::e(__('bot.week.closer', ['goal' => $week['main']['title'], 'count' => $week['main']['days']], $l)));
        }
        if ($week['records'] !== []) {
            $card->heading(__('bot.week.records', [], $l), 4)
                ->list(array_map(fn (array $r) => Card::e((self::RECORD_EMOJI[$r['kind']] ?? '🏆').' '.$r['text']), array_slice($week['records'], 0, self::WEEK_RECORDS_SHOWN)));
        }

        return $card->footer(Card::e(Fmt::date($start, $l).' — '.Fmt::date($end->copy()->subDay(), $l)));
    }

    private const WEEK_RECORDS_SHOWN = 5;

    /** «Неделя: потрачено …», against the week before, and the top three categories. */
    private function weeklySpending(Card $card, User $user, Carbon $start, Carbon $end, int $thisWeek, int $lastWeek, string $l): void
    {
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

        $card->heading(__('bot.digest.weekly_title', ['amount' => Fmt::uzs($thisWeek, $l)], $l), 2)
            ->paragraph(Card::e($compare))
            ->heading(__('bot.digest.weekly_top', [], $l), 4)
            ->facts($top->map(fn ($row) => [
                Fmt::categoryEmoji($categories[$row->root_id] ?? null).' '.Fmt::categoryName($categories[$row->root_id] ?? null, $l),
                Card::e(Fmt::uzs((int) $row->total, $l)),
            ])->all());
    }

    /**
     * «В лимите 5 из 7 дн. · неделей раньше 4 из 7 дн.», the same for habits and for what went into goals.
     * A number the week does not have is left out.
     *
     * @param  array<string, mixed>  $week
     * @return list<array{0: string, 1: string}>
     */
    private function weekFacts(array $week, string $l): array
    {
        $days = fn (?array $v) => $v === null ? null : __('bot.week.days_of', ['kept' => $v['kept'], 'days' => $v['days']], $l);
        $habits = fn (?array $v) => $v === null ? null : __('bot.week.of', ['done' => $v['done'], 'asked' => $v['asked']], $l);
        $goals = fn (int $v) => $v > 0 ? Fmt::uzs($v, $l) : null;
        $row = fn (string $label, ?string $value, ?string $before) => $value === null ? null : [
            __($label, [], $l),
            Card::e($before === null ? $value : __('bot.week.before', ['value' => $value, 'before' => $before], $l)),
        ];
        $prev = $week['prev'];

        return array_values(array_filter([
            $row('bot.week.limit', $days($week['limit']), $days($prev['limit'])),
            $row('bot.week.habits', $habits($week['habits']), $habits($prev['habits'])),
            $row('bot.week.goals', $goals($week['goals_uzs']) ?? ($prev['goals_uzs'] > 0 ? Fmt::uzs(0, $l) : null), $goals($prev['goals_uzs'])),
        ]));
    }

    /**
     * «Итоги месяца» on the 1st (docs/PLAN.md, D30): what was kept of the income, spending against the usual
     * level and the budget, and the goals. Null when nothing was recorded that month.
     */
    public function monthly(User $user, ?Carbon $now = null): ?Card
    {
        $l = $user->locale;
        $now ??= now();
        $month = $now->copy()->startOfMonth()->subMonthNoOverflow();
        $report = app(MonthReport::class)->for($user, $month->format('Y-m'), $now);
        if ($report['income_uzs'] === 0 && $report['expense_uzs'] === 0) {
            return null;
        }

        $saved = $report['saved_uzs'];
        $summary = $saved < 0
            ? __('bot.digest.monthly_deficit', ['amount' => Fmt::uzs(-$saved, $l)], $l)
            : __($report['rate'] === null ? 'bot.digest.monthly_saved_plain' : 'bot.digest.monthly_saved', ['amount' => Fmt::uzs($saved, $l), 'rate' => $report['rate']], $l);
        $prevRate = $report['prev']['rate'] ?? null;
        if ($saved >= 0 && $prevRate !== null) {
            $summary .= ' '.__('bot.digest.monthly_prev', ['rate' => $prevRate], $l);
        }

        $card = Card::make()
            ->heading('📊 '.__('bot.digest.monthly_title', ['month' => Fmt::monthName($month, $l)], $l), 2)
            ->paragraph(Card::e($summary))
            ->facts(array_values(array_filter([
                [__('bot.digest.monthly_income', [], $l), Card::e(Fmt::uzs($report['income_uzs'], $l))],
                [__('bot.digest.monthly_expense', [], $l), Card::e(Fmt::uzs($report['expense_uzs'], $l))],
                $report['one_off_uzs'] > 0 ? [__('bot.digest.monthly_one_off', [], $l), Card::e(Fmt::uzs($report['one_off_uzs'], $l))] : null,
            ])));

        if ($report['usual_uzs']) {
            $diff = (int) round(($report['expense_uzs'] - $report['one_off_uzs'] - $report['usual_uzs']) * 100 / $report['usual_uzs']);
            $card->paragraph(Card::e(match (true) {
                $diff < 0 => __('bot.digest.monthly_less', ['n' => -$diff, 'usual' => Fmt::uzs($report['usual_uzs'], $l)], $l),
                $diff > 0 => __('bot.digest.monthly_more', ['n' => $diff, 'usual' => Fmt::uzs($report['usual_uzs'], $l)], $l),
                default => __('bot.digest.monthly_same', [], $l),
            }));
            $categories = $user->categories()->get()->keyBy('id');
            foreach (MonthReport::movers($report['categories']) as $side => $rows) {
                if ($rows === []) {
                    continue;
                }
                $card->heading(__("bot.digest.monthly_{$side}", [], $l), 4)->facts(array_map(fn (array $m) => [
                    Fmt::categoryEmoji($categories[$m['category_id']] ?? null).' '.Fmt::categoryName($categories[$m['category_id']] ?? null, $l),
                    Card::e(Fmt::uzs(abs($m['diff']), $l, $side === 'up' ? '+' : '−')),
                ], $rows));
            }
        }

        if ($report['budget'] !== null) {
            $budget = $report['budget'];
            $card->paragraph(Card::e($budget['kept'] === $budget['count']
                ? __('bot.digest.monthly_budget_all', [], $l)
                : __('bot.digest.monthly_budget_kept', ['kept' => $budget['kept'], 'count' => $budget['count']], $l)));
        }

        $goals = array_values(array_filter($report['goals'], fn (array $g) => $g['added'] > 0));
        if ($goals !== []) {
            $card->heading(__('bot.digest.monthly_goals', [], $l), 4)->facts(array_map(fn (array $g) => [
                ($g['type'] === 'debt' ? '💳 ' : '🎯 ').$g['title'],
                Card::e('+'.Fmt::money($g['added'], $g['currency'], $l).' · '.round($g['progress_to'] * 100).'%'),
            ], $goals));
        }

        return $card;
    }

    /** «Открыть итоги»: the month's report in the Mini App (YYYY-MM). */
    public function reportKeyboard(User $user, string $month): Keyboard
    {
        return Keyboard::make()->row([Keyboard::webApp('📊 '.__('bot.buttons.open_report', [], $user->locale), "/money/report/{$month}", 'primary')]);
    }
}
