<?php

namespace App\Models;

use App\Advisor\Texts;
use App\Goals\GoalContributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** One piece of advice (docs/PLAN.md, 2.2). Rendered from lang/{locale}/advisor.php when read. */
class Insight extends Model
{
    /** Rule keys (App\Advisor\Advisor::RULES), for muting in settings. */
    public const RULES = [
        'budget_threshold', 'category_pace', 'overspend_forecast', 'large_spend', 'small_things',
        'income_split', 'goal_due', 'goal_leftover', 'goal_pace', 'goal_reached', 'cushion', 'savings_rate', 'good_news', 'forgot_to_record',
    ];

    /** The same, for a validation rule inside a constant expression. */
    public const RULES_LIST = 'budget_threshold,category_pace,overspend_forecast,large_spend,small_things,income_split,goal_due,goal_leftover,goal_pace,goal_reached,cushion,savings_rate,good_news,forgot_to_record';

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
            default => null,
        };
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
