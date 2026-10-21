<?php

namespace Database\Factories;

use App\Enums\PartType;
use App\Models\PartSubtype;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartSubtype>
 */
class PartSubtypeFactory extends Factory
{
    public function definition(): array
    {
        return ['type' => PartType::RawMaterial, 'name' => fake()->unique()->words(2, true)];
    }
}
