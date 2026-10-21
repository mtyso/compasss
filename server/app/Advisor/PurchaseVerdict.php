<?php

namespace App\Advisor;

use Illuminate\Support\Carbon;

/** The answer to «Можно ли это купить?» (docs/PLAN.md, 2.3). */
final class PurchaseVerdict
{
    public const VERDICTS = ['yes', 'yes_but', 'wait', 'no'];

    /** @param  list<array{key: string, params: array<string, mixed>}>  $reasons  texts in lang/*\/advisor.php, can_afford.reasons */
    public function __construct(
        public readonly string $verdict,
        public readonly int $amountUzs,
        public readonly int $limitBeforeUzs,
        public readonly int $limitAfterUzs,
        public readonly int $daysLeft,
        public readonly array $reasons,
        public readonly ?Carbon $waitUntil = null,
    ) {}

    /** @return array<string, mixed> */
    public function toApi(string $locale): array
    {
        return [
            'verdict' => $this->verdict,
            'title' => __("advisor.can_afford.verdicts.{$this->verdict}", [], $locale),
            'reasons' => array_map(
                fn (array $r) => __("advisor.can_afford.reasons.{$r['key']}", Texts::params($r['params'], $locale), $locale),
                $this->reasons,
            ),
            'amount_uzs' => $this->amountUzs,
            'limit_before_uzs' => $this->limitBeforeUzs,
            'limit_after_uzs' => $this->limitAfterUzs,
            'days_left' => $this->daysLeft,
            'wait_until' => $this->waitUntil?->toDateString(),
        ];
    }
}
