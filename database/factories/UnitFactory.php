<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->lexify('birim-????'),
            'allows_decimal' => true,
        ];
    }

    public function whole(): static
    {
        return $this->state(fn () => ['allows_decimal' => false]);
    }
}
