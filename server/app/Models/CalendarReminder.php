<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** A calendar reminder already handed to the bot, so each goes out once (App\Calendar\Reminders). */
class CalendarReminder extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = ['user_id', 'key', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** A reminder is only due within minutes of its time, so a few days of keys is plenty. */
    public function prunable(): Builder
    {
        return static::where('sent_at', '<', now()->subDays(3));
    }
}
