<?php

namespace App\Listeners;

use App\Events\TransactionSaved;
use App\Streaks\RecordKeeper;
use Throwable;

/** After an operation: money streaks, goal steps and debts closed go into the records journal (docs/PLAN.md, D39). */
class KeepRecords
{
    public function __construct(private RecordKeeper $keeper) {}

    public function handle(TransactionSaved $event): void
    {
        // The journal is a bonus, as advice is: it must never cost the user the operation they just saved.
        try {
            $this->keeper->money($event->transaction->user, now());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
