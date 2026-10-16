<?php

namespace Database\Factories;

use App\Models\ProductionBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionBatch>
 */
class ProductionBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_number' => fake()->unique()->bothify('PRT-####'),
            'date' => today(),
            'notes' => null,
        ];
    }
}
