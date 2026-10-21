<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;

/**
 * A rule whose findings can stop being true within their period, such as praise for spending less
 * that a later purchase undoes. After each run App\Advisor\Advisor removes such an insight while it
 * is still unread and has not been sent anywhere, so nobody is told something that is no longer so.
 */
interface Withdraws
{
    /** @return list<array{0: string, 1: string}> the (period_key, subject) pairs this run checked in full */
    public function checked(AdvisorContext $context): array;
}
