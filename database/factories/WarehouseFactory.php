<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('D-###'),
            'name' => fake()->unique()->words(2, true).' deposu',
            'default_part_types' => [],
        ];
    }

    public function allowsNegative(): static
    {
        return $this->state(fn () => ['allows_negative' => true]);
    }
}
