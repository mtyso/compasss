<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One attempt to sign in through the Telegram bot (docs/PLAN.md, section 6).
 *
 * pending  -> the app waits; the bot may have shown the confirm buttons
 * approved -> confirmed in the bot; the app can collect a token once
 * used     -> the token was issued
 * denied   -> declined in the bot
 */
class LoginRequest extends Model
{
    use HasUlids, MassPrunable;

    public const STATUSES = ['pending', 'approved', 'used', 'denied'];

    public const TTL_MINUTES = 5;

    protected $fillable = ['code_hash', 'secret_hash', 'status', 'telegram_id', 'user_id', 'device_name', 'ip_address', 'expires_at', 'approved_at'];

    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return array{0: self, 1: string, 2: string} the request, the deep link code and the app secret
     */
    public static function open(string $deviceName, ?string $ip): array
    {
        $code = Str::random(32);
        $secret = Str::random(48);

        $request = self::create([
            'code_hash' => hash('sha256', $code),
            'secret_hash' => hash('sha256', $secret),
            'device_name' => Str::limit($deviceName, 117),
            'ip_address' => $ip,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return [$request, $code, $secret];
    }

    public static function findByCode(string $code): ?self
    {
        return self::where('code_hash', hash('sha256', $code))->first();
    }

    public function secretMatches(?string $secret): bool
    {
        return is_string($secret) && hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    /** The status the app sees: an unfinished request past its lifetime reads as expired. */
    public function publicStatus(): string
    {
        return in_array($this->status, ['pending', 'approved'], true) && $this->isExpired() ? 'expired' : $this->status;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay());
    }
}
