<?php

namespace Database\Factories;

use App\Models\DatabaseBackup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A listing row only. No archive is written, so a backup from here can be
 * listed and deleted but not downloaded or restored - tests that need a real
 * archive make one through DatabaseBackupService::create().
 *
 * @extends Factory<DatabaseBackup>
 */
class DatabaseBackupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'backup-'.fake()->unique()->dateTimeThisYear()->format('Y-m-d-His').'.tar.gz';

        return [
            'file_name' => $name,
            'path' => $name,
            'kind' => DatabaseBackup::KIND_MANUAL,
            'includes_files' => true,
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'table_count' => fake()->numberBetween(20, 45),
            'row_count' => fake()->numberBetween(100, 5_000),
            'file_count' => fake()->numberBetween(0, 200),
            'created_by' => null,
            'created_by_name' => fake()->name(),
        ];
    }

    public function withoutFiles(): static
    {
        return $this->state(fn (array $attributes): array => [
            'includes_files' => false,
            'file_count' => 0,
        ]);
    }
}
