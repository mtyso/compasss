<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** How much of a habit was done on one day: 1 for a check, the number for count and limit habits. */
class HabitLog extends Model
{
    protected $fillable = ['user_id', 'habit_id', 'date', 'value'];

    // `date` stays uncast and is always written as Y-m-d (see RecurringPayment).
    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
