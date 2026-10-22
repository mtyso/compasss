<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    public const TYPES = ['expense', 'income', 'transfer'];

    public const SOURCES = ['app', 'web', 'bot'];

    protected $fillable = [
        'type', 'account_id', 'to_account_id', 'amount', 'currency', 'to_amount', 'rate', 'amount_uzs',
        'category_id', 'note', 'occurred_at', 'is_one_off', 'source', 'recurring_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'to_amount' => 'integer',
            'amount_uzs' => 'integer',
            'rate' => 'decimal:4',
            'occurred_at' => 'datetime',
            'is_one_off' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'account_id' => $this->account_id,
            'to_account_id' => $this->to_account_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'to_amount' => $this->to_amount,
            'rate' => $this->rate,
            'amount_uzs' => $this->amount_uzs,
            'category_id' => $this->category_id,
            'note' => $this->note,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'is_one_off' => $this->is_one_off,
            'source' => $this->source,
        ];
    }
}
