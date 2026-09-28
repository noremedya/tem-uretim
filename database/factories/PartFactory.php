<?php

namespace Database\Factories;

use App\Enums\PartType;
use App\Models\Part;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bakiye satırı StockService::openBalance ile açılır; başlangıç stoğu StockService üzerinden girilir.
 *
 * @extends Factory<Part>
 */
class PartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('P-#####'),
            'name' => fake()->words(2, true),
            'unit_id' => Unit::factory(),
            'type' => fake()->randomElement(PartType::cases()),
            'barcode' => null,
            'critical_level' => '0',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Part $part) => app(StockService::class)->openBalance($part));
    }

    public function inactive(): static
    {
        return $this->afterCreating(function (Part $part): void {
            $part->is_active = false;
            $part->save();
        });
    }

    public function critical(string|int $level): static
    {
        return $this->state(fn () => ['critical_level' => (string) $level]);
    }

    /** Başlangıç stoğu (StockService üzerinden giriş hareketiyle). */
    public function withStock(string|int $quantity, ?User $user = null): static
    {
        return $this->afterCreating(fn (Part $part) => app(StockService::class)->stockIn(
            $part,
            $quantity,
            $user ?? User::factory()->warehouse()->create(),
            'Açılış stoğu',
        ));
    }
}
