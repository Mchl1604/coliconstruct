<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * projects:rename - every project named "reference number - client", the
 * company on commercial work and the person otherwise.
 */
class RenameProjectsTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(Client $client, string $reference, string $name): Project
    {
        return Project::create([
            'client_id' => $client->client_id,
            'name' => $name,
            'reference_no' => $reference,
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);
    }

    public function test_projects_are_renamed_to_reference_and_client(): void
    {
        $residential = Client::factory()->create([
            'client_type' => 'Residential',
            'fullname' => 'Maria Santos',
            'company_name' => null,
        ]);
        $commercial = Client::factory()->create([
            'client_type' => 'Commercial',
            'fullname' => 'Juan Cruz',
            'company_name' => 'Acme Corp',
        ]);

        $home = $this->projectFor($residential, 'PRJ-20261001-00001', 'Aircon Cleaning - Santos Residence');
        $office = $this->projectFor($commercial, 'PRJ-20261002-00002', 'Juan Cruz');
        $updatedAt = $office->fresh()->updated_at;

        $this->travel(1)->hours();

        $this->artisan('projects:rename', ['--force' => true])
            ->expectsOutputToContain('2 project(s) renamed.')
            ->assertSuccessful();

        $this->assertSame('PRJ-20261001-00001 - Maria Santos', $home->fresh()->name);
        $this->assertSame('PRJ-20261002-00002 - Acme Corp', $office->fresh()->name);
        $this->assertTrue($updatedAt->equalTo($office->fresh()->updated_at));

        $this->artisan('projects:rename', ['--force' => true])
            ->expectsOutputToContain('already named')
            ->assertSuccessful();
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $client = Client::factory()->create(['client_type' => 'Residential', 'fullname' => 'Maria Santos']);
        $project = $this->projectFor($client, 'PRJ-20261001-00001', 'Old Name');

        $this->artisan('projects:rename', ['--dry-run' => true])
            ->expectsOutputToContain('1 project(s) would be renamed.')
            ->assertSuccessful();

        $this->assertSame('Old Name', $project->fresh()->name);
    }
}
