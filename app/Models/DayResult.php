<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A day for the money streaks: its daily limit and «Трат не было» (docs/PLAN.md, D38). */
class DayResult extends Model
{
    protected $fillable = ['date', 'limit_uzs', 'spent_uzs', 'no_spending'];

    // `date` stays uncast and is always written as Y-m-d.
    protected function casts(): array
    {
        return [
            'limit_uzs' => 'integer',
            'spent_uzs' => 'integer',
            'no_spending' => 'boolean',
        ];
    }
}
