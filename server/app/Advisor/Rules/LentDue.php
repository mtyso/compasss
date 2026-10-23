<?php

namespace App\Advisor\Rules;

use App\Advisor\AdvisorContext;
use App\Money\Balances;

/**
 * «Сегодня Бекзод обещал вернуть 500 000.» In the morning of the day money lent was promised back, and once
 * more three days later if it still is not (docs/PLAN.md, D29). «Вернул» on the insight records the money
 * coming back (Insight::returned); getting it back anywhere takes an unread reminder away.
 */
final class LentDue extends BaseRule
{
    // Days after the promised one when the second reminder comes
    private const DAYS_LATE = 3;

    public function key(): string
    {
        return 'lent_due';
    }

    public function events(): array
    {
        return [self::EVENT_MORNING];
    }

    public function evaluate(AdvisorContext $context): array
    {
        $today = $context->today;
        $accounts = $context->user->accounts()->whereNull('archived_at')->where('type', 'lent')->whereNotNull('due_on')->get();
        if ($accounts->isEmpty()) {
            return [];
        }

        $balances = Balances::forUser($context->user);
        $findings = [];
        foreach ($accounts as $account) {
            $due = $account->dueDate();
            $amount = $balances[$account->id] ?? 0;
            $stage = match (true) {
                $amount <= 0 => null,
                $due->eq($today) => 'today',
                $due->copy()->addDays(self::DAYS_LATE)->lte($today) => 'late',
                default => null,
            };
            if ($stage === null) {
                continue;
            }

            $findings[] = $this->finding(
                $stage === 'late' ? 'warn' : 'info',
                'd'.$due->toDateString().($stage === 'late' ? ':late' : ''),
                'a'.$account->id,
                [
                    'variant' => $stage,
                    'account_id' => $account->id,
                    'name' => $account->name,
                    'amount' => $amount,
                    'currency' => $account->currency,
                    'due_on' => $due->toDateString(),
                ],
            );
        }

        return $findings;
    }
}
