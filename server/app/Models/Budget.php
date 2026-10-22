<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    protected $fillable = ['category_id', 'month', 'limit_uzs'];

    protected function casts(): array
    {
        return ['limit_uzs' => 'integer'];
    }
}
