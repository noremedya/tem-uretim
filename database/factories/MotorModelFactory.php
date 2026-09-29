<?php

namespace Database\Factories;

use App\Enums\MotorPhase;
use App\Models\MotorModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotorModel>
 */
class MotorModelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('M-###??'),
            'name' => fake()->words(3, true),
            'power_kw' => fake()->randomElement(['0.750', '1.500', '4.000', '5.500', '11.000']),
            'speed_rpm' => fake()->randomElement([1000, 1500, 3000]),
            'voltage' => '230/400',
            'frequency_hz' => 50,
            'pole_count' => fake()->randomElement([2, 4, 6]),
            'phase' => MotorPhase::ThreePhase,
        ];
    }

    public function inactive(): static
    {
        return $this->afterCreating(function (MotorModel $model): void {
            $model->is_active = false;
            $model->save();
        });
    }
}
