<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'tax_number' => fake()->unique()->numerify('##########'),
            'tax_office' => fake()->city(),
            'phone' => fake()->numerify('0 (5##) ### ## ##'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
        ];
    }

    public function inactive(): static
    {
        return $this->afterCreating(function (Customer $customer): void {
            $customer->is_active = false;
            $customer->save();
        });
    }
}
