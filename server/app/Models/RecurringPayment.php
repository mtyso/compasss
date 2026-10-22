<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Rent, internet, subscriptions: a monthly payment the daily limit sets aside before it is due. */
class RecurringPayment extends Model
{
    protected $fillable = ['name', 'amount', 'currency', 'category_id', 'account_id', 'day_of_month', 'next_due_on', 'active'];

    // `next_due_on` stays uncast and is always written as Y-m-d: a `date` cast would store
    // "Y-m-d 00:00:00", which SQLite then fails to compare with plain dates (CLAUDE.md, Dates).
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'day_of_month' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function dueDate(): Carbon
    {
        return Carbon::parse($this->next_due_on, config('app.timezone'))->startOfDay();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return array<string, mixed> */
    public function toApi(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'category_id' => $this->category_id,
            'account_id' => $this->account_id,
            'day_of_month' => $this->day_of_month,
            'next_due_on' => $this->dueDate()->toDateString(),
            'overdue' => $this->dueDate()->lt($today),
            'active' => $this->active,
        ];
    }
}
