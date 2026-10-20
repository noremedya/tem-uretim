<?php

namespace Database\Factories;

use App\Enums\PartType;
use App\Models\Part;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\Eloquent\Factories\Factory;
use WeakMap;

/**
 * Bakiye satırı StockService::openBalance ile (varsayılan depoda) açılır; başlangıç stoğu StockService üzerinden
 * girilir. critical_level parts kolonu değildir: varsayılan deponun bakiye satırına yazılır.
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
            // Mamul kartı elle açılmaz (motor modelinden oluşur); varsayılan olarak üretilmez.
            'type' => fake()->randomElement(array_filter(PartType::cases(), fn (PartType $type) => $type->isManuallyAssignable())),
            'barcode' => null,
        ];
    }

    public function configure(): static
    {
        return $this
            ->afterMaking(function (Part $part): void {
                self::$criticalLevels ??= new WeakMap;
                self::$criticalLevels[$part] = $part->getAttributes()['critical_level'] ?? '0';
                unset($part['critical_level']);
            })
            ->afterCreating(fn (Part $part) => app(StockService::class)->openBalance($part, self::$criticalLevels[$part] ?? '0'));
    }

    /** @var WeakMap<Part, string>|null Yapılan kartın kritik seviyesi (oluşturulunca bakiye satırına yazılır). */
    private static ?WeakMap $criticalLevels = null;

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
