<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Models\MotorModel;
use App\Models\Price;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Price>
 */
class PriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'motor_model_id' => MotorModel::factory(),
            'customer_id' => null,
            'currency' => Currency::TRY,
            'unit_price' => '10000.00',
            'valid_from' => today(),
        ];
    }
}
