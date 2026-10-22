<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A measurement towards a goal without money: «84,2 кг» on a day. One a day. */
class GoalCheckin extends Model
{
    protected $fillable = ['user_id', 'date', 'value'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2'];
    }
}
