<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'telegram_id' => fake()->unique()->numberBetween(100_000_000, 7_999_999_999),
            'telegram_username' => fake()->userName(),
            'name' => fake()->firstName(),
            'locale' => 'ru',
        ];
    }
}
