<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialType;
use App\Support\MaterialUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    /**
     * An active, counted material with no brand.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_type_id' => MaterialType::factory(),
            'brand_id' => null,
            'name' => ucwords(fake()->unique()->words(3, true)),
            'unit' => MaterialUnit::Piece,
            'description' => null,
            'is_active' => true,
        ];
    }

    /**
     * A material measured in meters, which takes fractional quantities.
     */
    public function measured(): static
    {
        return $this->state(fn (): array => ['unit' => MaterialUnit::Meter]);
    }

    /**
     * Carrying the brand by this name, added to the list if it is not there.
     */
    public function brand(string $brand): static
    {
        return $this->state(fn (): array => [
            'brand_id' => Brand::query()->firstOrCreate(['brand_name' => $brand])->brand_id,
        ]);
    }

    /**
     * Low Stock at or below this many.
     */
    public function reorderAt(float|int $level): static
    {
        return $this->state(fn (): array => ['reorder_level' => $level]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
