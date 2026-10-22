<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Something the user works towards (docs/PLAN.md, D24): collect a sum (`save`), collect and then
 * spend it (`buy`), pay a debt off (`debt`) or reach something without money (`other`). Money goals
 * keep their money on their own account: the jar, or the debt account with a negative balance.
 * A purchase on installment collects only the down payment; the rest becomes a debt goal at «Купил».
 */
class Goal extends Model
{
    public const TYPES = ['save', 'buy', 'debt', 'other'];

    public const MONEY_TYPES = ['save', 'buy', 'debt'];

    protected $fillable = [
        'type', 'title', 'icon', 'target_amount', 'currency', 'account_id', 'start_amount', 'deadline',
        'monthly_amount', 'rate', 'price', 'down_percent', 'loan_months', 'unit', 'start_value', 'target_value', 'sort', 'started_on', 'done_at', 'archived_at',
    ];

    // `deadline` and `started_on` stay uncast and are always written as Y-m-d.
    protected function casts(): array
    {
        return [
            'target_amount' => 'integer',
            'start_amount' => 'integer',
            'monthly_amount' => 'integer',
            'rate' => 'decimal:2',
            'price' => 'integer',
            'down_percent' => 'integer',
            'loan_months' => 'integer',
            'start_value' => 'decimal:2',
            'target_value' => 'decimal:2',
            'sort' => 'integer',
            'done_at' => 'datetime',
            'archived_at' => 'datetime',
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

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(GoalCheckin::class);
    }

    /** A purchase where only a share of the price is collected and the rest is paid in installments. */
    public function onInstallment(): bool
    {
        return $this->type === 'buy' && $this->price !== null && $this->down_percent !== null;
    }

    public function hasMoney(): bool
    {
        return in_array($this->type, self::MONEY_TYPES, true);
    }

    /** Still being worked on: not finished, archived or deleted. */
    public function isActive(): bool
    {
        return $this->done_at === null && $this->archived_at === null;
    }

    public function deadlineDate(): ?Carbon
    {
        return $this->deadline ? Carbon::parse($this->deadline, config('app.timezone'))->startOfDay() : null;
    }

    public function startDate(): Carbon
    {
        return Carbon::parse($this->started_on, config('app.timezone'))->startOfDay();
    }
}
