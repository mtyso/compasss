<?php

namespace App\Money;

use App\Models\User;

/**
 * The ready-made category set (docs/PLAN.md, section 8). Names live in
 * lang/{ru,uz}/categories.php under the same keys. A child takes its parent's
 * necessity unless it names its own.
 */
final class DefaultCategories
{
    public const EXPENSE = [
        'groceries' => ['essential', 'shopping-basket', 'amber', ['groceries_supermarket', 'groceries_bazaar', 'groceries_corner']],
        'cafe' => ['flexible', 'utensils', 'orange', ['cafe_delivery', 'cafe_coffee', 'cafe_restaurants']],
        'transport' => ['essential', 'car', 'blue', ['transport_taxi', 'transport_public', 'transport_fuel', 'transport_parking', 'transport_service']],
        'home' => ['essential', 'house', 'teal', ['home_rent', 'home_utilities', 'home_internet', 'home_supplies', 'home_repair' => 'flexible']],
        'connection' => ['essential', 'smartphone', 'indigo', ['connection_mobile', 'connection_subscriptions' => 'flexible']],
        'health' => ['essential', 'heart-pulse', 'red', ['health_pharmacy', 'health_doctors', 'health_sport' => 'flexible']],
        'clothes' => ['flexible', 'shirt', 'purple', []],
        'beauty' => ['flexible', 'sparkles', 'pink', ['beauty_haircut', 'beauty_cosmetics']],
        'family' => ['flexible', 'gift', 'rose', ['family_gifts', 'family_help' => 'essential', 'family_celebrations']],
        'leisure' => ['flexible', 'tree-palm', 'green', ['leisure_events', 'leisure_hobby', 'leisure_travel']],
        'education' => ['flexible', 'graduation-cap', 'cyan', ['education_courses', 'education_books']],
        'finance' => ['essential', 'landmark', 'slate', ['finance_fees', 'finance_interest', 'finance_taxes']],
        'charity' => ['flexible', 'hand-heart', 'lime', []],
        'other_expense' => ['flexible', 'circle-ellipsis', 'gray', []],
    ];

    public const INCOME = [
        'salary' => ['banknote', 'green'],
        'side_job' => ['briefcase', 'teal'],
        'bonus' => ['award', 'amber'],
        'gifts_income' => ['gift', 'rose'],
        'interest_cashback' => ['percent', 'indigo'],
        'sales' => ['tag', 'orange'],
        'other_income' => ['circle-ellipsis', 'gray'],
    ];

    /** Idempotent: categories that already exist (by key) are left as the user changed them. */
    public static function seedFor(User $user): void
    {
        $existing = $user->categories()->whereNotNull('system_key')->pluck('id', 'system_key');
        $sort = 0;

        foreach (self::EXPENSE as $key => [$necessity, $icon, $color, $children]) {
            $parentId = $existing[$key] ?? $user->categories()->create([
                'kind' => 'expense', 'system_key' => $key, 'icon' => $icon, 'color' => $color,
                'necessity' => $necessity, 'sort' => $sort,
            ])->id;
            $sort++;

            $childSort = 0;
            foreach ($children as $childKey => $childNecessity) {
                if (is_int($childKey)) {
                    [$childKey, $childNecessity] = [$childNecessity, $necessity];
                }
                if (! isset($existing[$childKey])) {
                    $user->categories()->create([
                        'parent_id' => $parentId, 'kind' => 'expense', 'system_key' => $childKey,
                        'necessity' => $childNecessity, 'sort' => $childSort,
                    ]);
                }
                $childSort++;
            }
        }

        foreach (self::INCOME as $key => [$icon, $color]) {
            if (! isset($existing[$key])) {
                $user->categories()->create(['kind' => 'income', 'system_key' => $key, 'icon' => $icon, 'color' => $color, 'sort' => $sort]);
            }
            $sort++;
        }
    }
}
