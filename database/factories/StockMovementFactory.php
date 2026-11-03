<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * Ten of something received today.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_id' => Material::factory(),
            'movement_type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 10,
            'movement_date' => now()->toDateString(),
            'recorded_by_name' => 'Test',
        ];
    }
}
