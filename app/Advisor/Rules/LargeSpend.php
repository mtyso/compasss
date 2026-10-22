<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Models\Transaction;

/**
 * «Это заметно больше обычного для «Одежды». Отметить как разовую?»
 * An everyday expense at least `large_spend_factor` times the usual one in its category over the last 90 days.
 */
final class LargeSpend extends BaseRule
{
    private const MIN_HISTORY = 5;

    public function key(): string
    {
        return 'large_spend';
    }

    public function events(): array
    {
        return [self::EVENT_TRANSACTION];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $trigger = $context->trigger;
        if ($trigger === null || $trigger->type !== 'expense' || ! AdvisorContext::isEveryday($trigger)) {
            return [];
        }
        $root = $context->rootOf($trigger->category_id);
        if ($root === null) {
            return [];
        }
        $ids = $context->categories()->filter(fn ($c) => $c->id === $root->id || $c->parent_id === $root->id)->keys();

        $usual = $context->expensesBetween($trigger->occurred_at->copy()->subDays(90), $trigger->occurred_at->copy()->addSecond())
            ->filter(fn (Transaction $t) => $t->id !== $trigger->id && $ids->contains($t->category_id) && AdvisorContext::isEveryday($t))
            ->pluck('amount_uzs')
            ->all();
        if (count($usual) < self::MIN_HISTORY) {
            return [];
        }
        $typical = AdvisorContext::median($usual);
        $factor = (float) $context->setting('large_spend_factor', 2);
        // A coffee three times the usual one is not news: the difference itself must be noticeable.
        $minDifference = $context->pick(300_000, 200_000, 100_000);
        if ($trigger->amount_uzs < $typical * $factor || $trigger->amount_uzs - $typical < $minDifference) {
            return [];
        }

        return [$this->finding('info', 't'.$trigger->id, '', [
            'category_id' => $root->id,
            'amount_uzs' => $trigger->amount_uzs,
            'typical_uzs' => $typical,
            'transaction_id' => $trigger->id,
        ])];
    }
}
