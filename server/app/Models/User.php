<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['telegram_id', 'telegram_username', 'name', 'locale', 'timezone', 'base_currency', 'settings'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    public const LOCALES = ['ru', 'uz'];

    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'settings' => 'array',
        ];
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
