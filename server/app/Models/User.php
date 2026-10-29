<?php

namespace App\Models;

use App\Money\DefaultCategories;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['telegram_id', 'telegram_username', 'name', 'locale', 'timezone', 'base_currency', 'settings'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    public const LOCALES = ['ru', 'uz'];

    /** Settings the app may change, with their validation rules. */
    public const SETTINGS = [
        // Money that must stay on the spending accounts whatever happens (docs/PLAN.md, 2.1)
        'min_balance_uzs' => ['integer', 'min:0', 'max:1000000000000'],
        // The advisor (docs/PLAN.md, 2.5): how often and how firmly it speaks, and its thresholds
        'advisor_strictness' => ['string', 'in:soft,normal,strict'],
        'budget_alert_percent' => ['integer', 'min:50', 'max:100'],
        'large_spend_factor' => ['numeric', 'min:1.5', 'max:10'],
        'cushion_target_months' => ['integer', 'min:1', 'max:12'],
        // Goals (D25): `first` sets this period's contributions aside before the daily limit, `leftover` saves what is left
        'goal_mode' => ['string', 'in:first,leftover'],
        // Debts (D29): which one gets money beyond its payment first, the dearest (`avalanche`) or the smallest (`snowball`)
        'debt_method' => ['string', 'in:avalanche,snowball'],
        // «Не показывать такое»: rules switched off
        'muted_rules' => ['array', 'max:20'],
        'muted_rules.*' => ['string', 'distinct', 'in:'.Insight::RULES_LIST],
    ];

    protected static function booted(): void
    {
        // Every account starts with the ready-made category set (docs/PLAN.md, section 8).
        static::created(fn (User $user) => DefaultCategories::seedFor($user));
    }

    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'settings' => 'array',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function incomeSchedules(): HasMany
    {
        return $this->hasMany(IncomeSchedule::class);
    }

    public function recurringPayments(): HasMany
    {
        return $this->hasMany(RecurringPayment::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    public function habitPauses(): HasMany
    {
        return $this->hasMany(HabitPause::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(Insight::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function routineBlocks(): HasMany
    {
        return $this->hasMany(RoutineBlock::class);
    }

    /** A user setting with its default (docs/PLAN.md, 2.5: thresholds are settings). */
    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    public function isAllowed(): bool
    {
        return in_array((string) $this->telegram_id, config('telegram.allowed_ids'), true);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'telegram_username' => $this->telegram_username,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'base_currency' => $this->base_currency,
            'settings' => (object) ($this->settings ?? []),
        ];
    }
}
