<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    public const KINDS = ['expense', 'income'];

    /** Essential spending is protected by the advisor; flexible is where it suggests cutting (docs/PLAN.md, 2.1). */
    public const NECESSITIES = ['essential', 'flexible'];

    public const ICONS = [
        'shopping-basket', 'utensils', 'car', 'house', 'smartphone', 'heart-pulse', 'shirt', 'sparkles', 'gift',
        'tree-palm', 'graduation-cap', 'landmark', 'hand-heart', 'circle-ellipsis', 'banknote', 'briefcase',
        'award', 'percent', 'tag', 'baby', 'paw-print', 'dumbbell', 'plane', 'coffee', 'book', 'wrench',
    ];

    public const COLORS = ['amber', 'orange', 'blue', 'teal', 'indigo', 'red', 'purple', 'pink', 'rose', 'green', 'cyan', 'slate', 'lime', 'gray'];

    protected $fillable = ['parent_id', 'kind', 'system_key', 'name', 'icon', 'color', 'necessity', 'sort', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return array{ru: string, uz: string} */
    public function names(): array
    {
        if ($this->system_key === null) {
            return ['ru' => $this->name, 'uz' => $this->name];
        }

        return [
            'ru' => $this->name ?? __("categories.{$this->system_key}", [], 'ru'),
            'uz' => $this->name ?? __("categories.{$this->system_key}", [], 'uz'),
        ];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'kind' => $this->kind,
            'system_key' => $this->system_key,
            'names' => $this->names(),
            'icon' => $this->icon,
            'color' => $this->color,
            'necessity' => $this->necessity,
            'sort' => $this->sort,
            'archived' => $this->archived_at !== null,
        ];
    }
}
