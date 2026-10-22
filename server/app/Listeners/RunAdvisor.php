<?php

namespace App\Listeners;

use App\Advisor\Advisor;
use App\Events\TransactionSaved;
use Throwable;

class RunAdvisor
{
    public function __construct(private Advisor $advisor) {}

    public function handle(TransactionSaved $event): void
    {
        // Advice is a bonus: a failing rule must never cost the user the operation they just saved.
        try {
            $this->advisor->afterTransaction($event->transaction);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
