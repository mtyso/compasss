<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Account extends Model
{
    // A debt account holds a negative balance; paying the debt is a transfer to it (docs/PLAN.md, D24).
    // A `lent` one is money someone owes the user: lending is a transfer to it, getting it back one from it (D29).
    public const TYPES = ['card', 'cash', 'savings', 'deposit', 'debt', 'lent', 'other'];

    protected $fillable = ['name', 'type', 'currency', 'opening_balance', 'spendable', 'is_cushion', 'due_on', 'sort', 'archived_at'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'spendable' => 'boolean',
            'is_cushion' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** `due_on` stays uncast and is always written as Y-m-d. */
    public function dueDate(): ?Carbon
    {
        return $this->due_on ? Carbon::parse($this->due_on, config('app.timezone'))->startOfDay() : null;
    }

    public function hasTransactions(): bool
    {
        return Transaction::withTrashed()
            ->where(fn ($q) => $q->where('account_id', $this->id)->orWhere('to_account_id', $this->id))
            ->exists();
    }

    /** @return array<string, mixed> */
    public function toApi(int $balance): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'currency' => $this->currency,
            'opening_balance' => $this->opening_balance,
            'balance' => $balance,
            'spendable' => $this->spendable,
            'is_cushion' => $this->is_cushion,
            'due_on' => $this->dueDate()?->toDateString(),
            'sort' => $this->sort,
            'archived' => $this->archived_at !== null,
        ];
    }
}
