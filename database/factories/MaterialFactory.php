<?php

namespace Database\Factories;

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
            'name' => ucwords(fake()->unique()->words(3, true)),
            'brand' => null,
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

    public function brand(string $brand): static
    {
        return $this->state(fn (): array => ['brand' => $brand]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
