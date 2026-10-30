<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One of the day's five prayers, prayed in its time (`on_time`) or made up after it (`qaza`), docs/PLAN.md D40. */
class PrayerLog extends Model
{
    public const STATUSES = ['on_time', 'qaza'];

    protected $fillable = ['user_id', 'date', 'prayer', 'status'];

    // `date` stays uncast and is always written as Y-m-d (see RecurringPayment).
}
