<?php

namespace Database\Factories;

use App\Models\DetailForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DetailForm>
 */
class DetailFormFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Unique in tbl_detail_forms.
            'name' => ucwords(fake()->unique()->words(2, true)),
        ];
    }
}
