<?php

namespace App\Models;

use App\Advisor\Rules\PaymentDue;
use App\Advisor\Texts;
use App\Goals\GoalContributor;
use App\Goals\GoalMath;
use App\Money\Balances;
use App\Money\Debts;
use App\Money\PaymentPayer;
use App\Money\SubscriptionFinder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One piece of advice (docs/PLAN.md, 2.2). Rendered from lang/{locale}/advisor.php when read. */
class Insight extends Model
{
    /** Rule keys (App\Advisor\Advisor::RULES), for muting in settings. */
    public const RULES = [
        'budget_threshold', 'category_pace', 'overspend_forecast', 'large_spend', 'small_things',
        'income_split', 'goal_due', 'goal_leftover', 'goal_pace', 'goal_reached', 'cushion', 'savings_rate', 'good_news', 'forgot_to_record',
        'payment_due', 'subscription_found', 'lent_due',
    ];

    /** The same, for a validation rule inside a constant expression. */
    public const RULES_LIST = 'budget_threshold,category_pace,overspend_forecast,large_spend,small_things,income_split,goal_due,goal_leftover,goal_pace,goal_reached,cushion,savings_rate,good_news,forgot_to_record,payment_due,subscription_found,lent_due';

    /** Most important first. */
    public const SEVERITIES = ['alert', 'warn', 'good', 'info'];

    public const STATUSES = ['new', 'seen', 'useful', 'dismissed'];

    protected $fillable = ['rule', 'severity', 'period_key', 'subject', 'payload', 'status', 'sent_via'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_via' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{title: string, body: string} title and text in the given language */
    public function text(?string $locale = null): array
    {
        $locale ??= $this->user?->locale ?? 'ru';
        $payload = $this->payload ?? [];
        $params = Texts::params($payload, $locale);
        $base = "advisor.rules.{$this->rule}.".($payload['variant'] ?? 'default');

        return [
            'title' => __("{$base}.title", $params, $locale),
            'body' => __("{$base}.body", $params, $locale),
        ];
    }

    /** What the insight lets you do right from it, if anything: once done, the button goes. */
    public function action(): ?string
    {
        return match (true) {
            $this->status === 'useful' => null,
            $this->rule === 'large_spend' => 'mark_one_off',
            in_array($this->rule, ['income_split', 'goal_due'], true) && ($this->payload['goals_uzs'] ?? 0) > 0,
            $this->rule === 'goal_leftover' => 'contribute',
            $this->rule === 'payment_due' && $this->payment() !== null => 'pay',
            $this->rule === 'subscription_found' && $this->status !== 'dismissed'
                && ! SubscriptionFinder::covered($this->user, $this->payload ?? []) => 'add_payment',
            $this->rule === 'lent_due' && $this->lent() !== null => 'returned',
            $this->rule === 'goal_reached' && ($this->payload['variant'] ?? null) === 'debt_roll' && $this->nextDebt() !== null => 'roll',
            default => null,
        };
    }

    /** What the action works on in the app: the payment to pay, or the suggestion to turn into one. */
    public function ref(): int|string|null
    {
        return match ($this->rule) {
            'payment_due' => $this->payload['payment_id'] ?? null,
            'subscription_found' => $this->subject,
            'lent_due' => $this->payload['account_id'] ?? null,
            default => null,
        };
    }

    /** The recurring payment a reminder is about, while it still waits for the reminded due date. */
    public function payment(): ?RecurringPayment
    {
        $payment = $this->user->recurringPayments()->find($this->payload['payment_id'] ?? 0);

        return $payment !== null && PaymentDue::stillDue($payment, $this->payload ?? []) ? $payment : null;
    }

    /** «Оплачено» on a reminder: the payment from its own account, as in the app (PaymentPayer). */
    public function pay(Carbon $now, string $source = 'app'): Transaction
    {
        $payment = $this->payment() ?? throw ValidationException::withMessages(['action' => __('money.pay_already')]);

        // Answered first, so paying does not take this reminder away with the unread ones.
        return DB::transaction(function () use ($payment, $now, $source) {
            $this->update(['status' => 'useful']);

            return app(PaymentPayer::class)->pay($this->user, $payment, at: $now, source: $source);
        });
    }

    /** The money a reminder says is due back, while some of it still is. */
    public function lent(): ?Account
    {
        $account = $this->user->accounts()->whereNull('archived_at')->where('type', 'lent')->find($this->payload['account_id'] ?? 0);

        return $account !== null && (Balances::forUser($this->user)[$account->id] ?? 0) > 0 ? $account : null;
    }

    /** The debt a paid-off one's payment is offered to, while it is still owed. */
    public function nextDebt(): ?Account
    {
        $debt = $this->user->accounts()->whereNull('archived_at')->where('type', 'debt')->find($this->payload['next_account_id'] ?? 0);

        return $debt !== null && (Balances::forUser($this->user)[$debt->id] ?? 0) < 0 ? $debt : null;
    }

    /** «Вернул» on a reminder: all of it, back to the account it was lent from (or the first one for spending). */
    public function returned(Carbon $now, string $source = 'app'): Transaction
    {
        $lent = $this->lent() ?? throw ValidationException::withMessages(['action' => __('debts.already_returned')]);
        $debts = app(Debts::class);
        $to = $this->user->accounts()->whereNull('archived_at')->whereNotIn('type', ['debt', 'lent'])->where('currency', $lent->currency)->find($debts->firstLoan($lent)?->account_id ?? 0)
            ?? $this->user->accounts()->whereNull('archived_at')->where('spendable', true)->where('is_cushion', false)->where('currency', $lent->currency)->orderBy('sort')->orderBy('id')->first()
            ?? throw ValidationException::withMessages(['action' => __('debts.no_account')]);

        return DB::transaction(function () use ($debts, $lent, $to, $now, $source) {
            $this->update(['status' => 'useful']);

            return $debts->settle($this->user, $lent, $to, ['source' => $source], $now);
        });
    }

    /** «Перенести платёж» when a debt is paid off: its payment goes on top of the next debt's (D29, the snowball). */
    public function roll(Carbon $now): Goal
    {
        $user = $this->user;
        $next = $this->nextDebt() ?? throw ValidationException::withMessages(['action' => __('debts.roll_nothing')]);
        $amount = app(GoalContributor::class)->inCurrency((int) $this->payload['payment_amount'], $this->payload['currency'] ?? 'UZS', $next->currency, $now);

        return DB::transaction(function () use ($user, $next, $amount, $now) {
            $this->update(['status' => 'useful']);
            // The paid-off debt is finished: its payment now goes to the next one.
            $user->goals()->whereKey($this->payload['goal_id'] ?? 0)->whereNull('done_at')->update(['done_at' => $now]);

            return app(Debts::class)->addToPayment($user, $next, GoalMath::roundUp($amount, $next->currency), $now);
        });
    }

    /** @return array<string, mixed> */
    /**
     * «Не показывать такое»: the whole rule goes into the user's muted_rules and its open insights
     * are dismissed. Shared by the «Советы» feed and the bot.
     */
    public function mute(): void
    {
        $user = $this->user;
        $muted = array_values(array_unique([...(array) $user->setting('muted_rules', []), $this->rule]));
        $user->update(['settings' => [...($user->settings ?? []), 'muted_rules' => $muted]]);
        $user->insights()->where('rule', $this->rule)->whereIn('status', ['new', 'seen'])->update(['status' => 'dismissed']);
        $this->refresh();
    }

    /** «Отметить разовой» on a large expense: the operation stops counting as everyday spending. */
    public function markOneOff(): ?Transaction
    {
        $transaction = $this->user->transactions()->find($this->payload['transaction_id'] ?? 0);
        $transaction?->update(['is_one_off' => true]);
        $this->update(['status' => 'useful']);

        return $transaction;
    }

    /**
     * «Отложить» on an income or a reminder: this period's contributions; on money left before an income:
     * that sum into the goal it names. From the account the income came to, or the first one for spending.
     *
     * @return list<Transaction>
     */
    public function contribute(Carbon $now): array
    {
        $user = $this->user;
        $from = $user->accounts()->whereNull('archived_at')->find($this->payload['account_id'] ?? 0)
            ?? $user->accounts()->whereNull('archived_at')->where('spendable', true)->where('is_cushion', false)->orderBy('sort')->orderBy('id')->first();
        if ($from === null) {
            throw ValidationException::withMessages(['account_id' => __('goals.no_account')]);
        }
        $contributor = app(GoalContributor::class);
        $goal = $this->rule === 'goal_leftover' ? $user->goals()->find($this->payload['goal_id'] ?? 0) : null;
        $transactions = $goal !== null
            ? $contributor->contribute($user, $from, [['goal' => $goal, 'amount' => (int) $this->payload['amount']]], $now)
            : $contributor->contributeDue($user, $from, $now);
        $this->update(['status' => 'useful']);

        return $transactions;
    }

    public function toApi(?string $locale = null): array
    {
        return [
            'id' => $this->id,
            'rule' => $this->rule,
            'severity' => $this->severity,
            'status' => $this->status,
            ...$this->text($locale),
            'action' => $this->action(),
            'ref' => $this->ref(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
