<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An operation was created or changed, from the app, the web or the bot. Sent once the database has committed it. */
class TransactionSaved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Transaction $transaction) {}
}
