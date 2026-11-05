<?php

namespace Database\Factories;

use App\Models\MotorDesign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotorDesign>
 */
class MotorDesignFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'TSR-'.fake()->unique()->numerify('###'), 'name' => 'Deneme '.fake()->word()];
    }
}
