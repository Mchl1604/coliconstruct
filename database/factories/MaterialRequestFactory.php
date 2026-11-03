<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialRequest>
 */
class MaterialRequestFactory extends Factory
{
    /**
     * Two of something, waiting on a decision.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => fn (): int => Project::create([
                'client_id' => Client::factory()->create()->client_id,
                'name' => 'Aircon Installation',
                'reference_no' => 'REF-'.fake()->unique()->numerify('#####'),
                'status' => 'ongoing',
                'address' => '1 Test Street',
                'description' => 'Description',
                'quotation' => 100000,
            ])->project_id,
            'material_id' => Material::factory(),
            'quantity' => 2,
            'status' => MaterialRequest::STATUS_PENDING,
            'requested_by' => User::factory(),
        ];
    }
}
