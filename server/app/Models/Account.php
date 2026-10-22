<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    // A debt account holds a negative balance; paying the debt is a transfer to it (docs/PLAN.md, D24)
    public const TYPES = ['card', 'cash', 'savings', 'deposit', 'debt', 'other'];

    protected $fillable = ['name', 'type', 'currency', 'opening_balance', 'spendable', 'is_cushion', 'sort', 'archived_at'];

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
            'sort' => $this->sort,
            'archived' => $this->archived_at !== null,
        ];
    }
}
