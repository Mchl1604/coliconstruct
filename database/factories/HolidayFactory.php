<?php

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    /**
     * A day off some way ahead.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'holiday_date' => now()->addDays(fake()->unique()->numberBetween(30, 300))->toDateString(),
            'name' => 'Company Holiday',
        ];
    }
}
