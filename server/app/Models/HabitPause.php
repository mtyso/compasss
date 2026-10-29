<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A pause for all habits, both days included (docs/PLAN.md, D37). */
class HabitPause extends Model
{
    protected $fillable = ['starts_on', 'ends_on'];

    // `starts_on` and `ends_on` stay uncast and are always written as Y-m-d.

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'starts_on' => substr((string) $this->starts_on, 0, 10),
            'ends_on' => substr((string) $this->ends_on, 0, 10),
        ];
    }
}
