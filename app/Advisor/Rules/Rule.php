<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Advisor\Finding;

/** One advisor rule from docs/PLAN.md, 2.2. Rules only read the context; App\Advisor\Advisor stores what they find. */
interface Rule
{
    public const EVENT_TRANSACTION = 'transaction';

    public const EVENT_MORNING = 'morning';

    public const EVENT_EVENING = 'evening';

    public function key(): string;

    /** @return list<string> events the rule runs on */
    public function events(): array;

    /** @return list<Finding> */
    public function evaluate(AdvisorContext $context): array;
}
