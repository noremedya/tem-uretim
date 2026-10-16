<?php

namespace Database\Factories;

use App\Enums\PartType;
use App\Models\BomItem;
use App\Models\Part;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BomItem>
 */
class BomItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'parent_part_id' => fn () => Part::factory()->create(['type' => PartType::SemiFinished]),
            'component_part_id' => fn () => Part::factory()->create(['type' => PartType::RawMaterial]),
            'quantity_per_unit' => '1',
            'scrap_rate' => '0',
        ];
    }
}
