<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Support\TaxNumber;
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
            'tax_number' => static::validVkn(),
            'tax_office' => fake()->city(),
            'phone' => fake()->numerify('0 (5##) ### ## ##'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
        ];
    }

    /** Kontrol hanesi geçerli, benzersiz VKN (ilk 9 hane rastgele). */
    public static function validVkn(): string
    {
        $firstNine = fake()->unique()->numerify('#########');

        return $firstNine.TaxNumber::vknCheckDigit($firstNine);
    }

    public function inactive(): static
    {
        return $this->afterCreating(function (Customer $customer): void {
            $customer->is_active = false;
            $customer->save();
        });
    }
}
