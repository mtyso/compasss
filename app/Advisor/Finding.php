<?php

namespace App\Advisor;

/** What a rule wants to say, before it is stored as an App\Models\Insight. */
final class Finding
{
    /** @param  array<string, mixed>  $payload  values for the text in lang/*\/advisor.php; `variant` picks the wording */
    public function __construct(
        public readonly string $rule,
        public readonly string $severity,
        public readonly string $periodKey,
        public readonly string $subject = '',
        public readonly array $payload = [],
    ) {}
}
