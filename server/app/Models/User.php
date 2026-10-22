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
