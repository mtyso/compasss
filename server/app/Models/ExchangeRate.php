<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = ['currency', 'date', 'rate', 'source'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4'];
    }

    // `date` is deliberately left uncast: a `date` cast writes "Y-m-d 00:00:00", which
    // SQLite then fails to match against "Y-m-d" (see CLAUDE.md, Dates).
}
