<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone that takes push notifications (docs/PLAN.md, D31). */
class DeviceToken extends Model
{
    use MassPrunable;

    public const PLATFORMS = ['android'];

    /** Firebase calls a token stale after a month unseen; the app refreshes it on every start. */
    public const STALE_DAYS = 60;

    protected $fillable = ['user_id', 'personal_access_token_id', 'token', 'platform', 'device_name', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('last_seen_at', '<', now()->subDays(self::STALE_DAYS));
    }
}
