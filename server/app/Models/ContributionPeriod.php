<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pay period's contributions to goals (docs/PLAN.md, D38): what the period asked and what was still due
 * when it was last written. `goals` holds the same per goal: id, planned and due in the goal's currency.
 */
class ContributionPeriod extends Model
{
    protected $fillable = ['starts_on', 'ends_on', 'planned_uzs', 'due_uzs', 'goals'];

    // `starts_on` and `ends_on` stay uncast and are always written as Y-m-d.
    protected function casts(): array
    {
        return [
            'planned_uzs' => 'integer',
            'due_uzs' => 'integer',
            'goals' => 'array',
        ];
    }
}
