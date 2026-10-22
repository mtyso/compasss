<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Money\SubscriptionFinder;

/**
 * «Netflix похоже на подписку: три месяца подряд по 120 000 сум.» Once for each spending that repeats every
 * month and is no recurring payment yet. «Добавить» in the app opens the payment form already filled in.
 */
final class SubscriptionFound extends BaseRule
{
    /** At most this many new ones a morning, so a first import does not flood the advice. */
    private const PER_RUN = 2;

    public function __construct(private SubscriptionFinder $finder) {}

    public function key(): string
    {
        return 'subscription_found';
    }

    public function events(): array
    {
        return [self::EVENT_MORNING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $known = $context->user->insights()->where('rule', $this->key())->pluck('subject')->all();

        return collect($this->finder->find($context->user, $context->now))
            ->reject(fn (array $s) => in_array($s['key'], $known, true))
            ->take(self::PER_RUN)
            ->map(fn (array $s) => $this->finding('info', 'sub', $s['key'], [
                'name' => $s['name'],
                'amount' => $s['amount'],
                'currency' => $s['currency'],
                'category_id' => $s['category_id'],
                'count' => $s['count'],
            ]))
            ->values()
            ->all();
    }
}
