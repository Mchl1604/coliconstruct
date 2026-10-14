<?php

namespace Database\Factories;

use App\Models\MaterialType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialType>
 */
class MaterialTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Unique in tbl_material_types.
            'type_name' => ucwords(fake()->unique()->words(2, true)),
        ];
    }
}
