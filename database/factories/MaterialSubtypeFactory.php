<?php

namespace Database\Factories;

use App\Models\MaterialSubtype;
use App\Models\MaterialType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialSubtype>
 */
class MaterialSubtypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_type_id' => MaterialType::factory(),
            // Unique within its type.
            'subtype_name' => ucwords(fake()->unique()->words(2, true)),
        ];
    }
}
