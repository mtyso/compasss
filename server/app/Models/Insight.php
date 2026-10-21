<?php

namespace App\Models;

use App\Advisor\Texts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One piece of advice (docs/PLAN.md, 2.2). Rendered from lang/{locale}/advisor.php when read. */
class Insight extends Model
{
    /** Rule keys (App\Advisor\Advisor::RULES), for muting in settings. */
    public const RULES = [
        'budget_threshold', 'category_pace', 'overspend_forecast', 'large_spend', 'small_things',
        'income_split', 'cushion', 'savings_rate', 'good_news', 'forgot_to_record',
    ];

    /** The same, for a validation rule inside a constant expression. */
    public const RULES_LIST = 'budget_threshold,category_pace,overspend_forecast,large_spend,small_things,income_split,cushion,savings_rate,good_news,forgot_to_record';

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

    /** What the insight lets you do right from it, if anything. */
    public function action(): ?string
    {
        return $this->rule === 'large_spend' && $this->status !== 'useful' ? 'mark_one_off' : null;
    }

    /** @return array<string, mixed> */
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
