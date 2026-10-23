<?php

namespace App\Models;

use App\Support\MonthDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /** Paid or skipped this time: the next due date is a month later. */
    public function advance(): void
    {
        $this->update(['next_due_on' => MonthDay::nextMonth($this->dueDate(), $this->day_of_month)->toDateString()]);
        // A reminder nobody has read or been sent is no longer true (as App\Advisor\Rules\Withdraws does).
        Insight::where(['user_id' => $this->user_id, 'rule' => 'payment_due', 'subject' => 'p'.$this->id, 'status' => 'new'])
            ->whereNull('sent_via')
            ->delete();
    }

    /** The payment that settled the current due date was deleted: it is due again a month earlier. */
    public function rewind(): void
    {
        $this->update(['next_due_on' => MonthDay::previousMonth($this->dueDate(), $this->day_of_month)->toDateString()]);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
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
