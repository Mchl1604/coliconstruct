<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DetailForm;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Skill;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectTypeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Project Types: the catalogue of work the company does.
 *
 * A fixed list: read on screen, written only from code. A project type and a
 * technician specialty are the same entry wearing two hats, so adding one has
 * to land on the other.
 */
class ProjectTypeCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function technician(string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => 'technician'])->save();

        return Technician::create(['account_id' => $user->id, 'role' => 'technician']);
    }

    private function project(string $name): Project
    {
        return Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name), 0, 8)),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);
    }

    private function skillNamed(string $name): ?Skill
    {
        return Skill::query()->where('skill_name', $name)->first();
    }

    /**
     * The specialty pickers read tbl_skills, so this is the whole of "it also
     * shows up as a specialty" as far as the interface is concerned.
     */
    public function test_adding_a_type_from_code_also_adds_the_matching_specialty(): void
    {
        $type = app(ProjectTypeCatalog::class)->add('Equipment Check-up');

        $this->assertSame('Equipment Check-up', $type->type_name);
        $this->assertNotNull($this->skillNamed('Equipment Check-up'));
    }

    public function test_the_list_shows_each_type_with_its_form(): void
    {
        $this->actingAsSuperAdmin();
        ProjectType::create(['type_name' => 'Equipment Repair', 'form_id' => DetailForm::factory()->create(['name' => 'Repair Form'])->form_id]);

        $this->getJson(route('super-admin.configuration.project-types.index'))
            ->assertOk()
            ->assertJsonPath('types.0.type_name', 'Equipment Repair')
            ->assertJsonPath('types.0.form_name', 'Repair Form');
    }

    public function test_the_type_cannot_be_added_renamed_or_removed_on_screen(): void
    {
        $this->actingAsSuperAdmin();
        $type = ProjectType::create(['type_name' => 'Equipment Repair']);
        $url = route('super-admin.configuration.project-types.index');

        $this->postJson($url, ['type_name' => 'Solar Panels'])->assertStatus(405);
        $this->putJson($url.'/'.$type->type_id, ['type_name' => 'Renamed'])->assertStatus(405);
        $this->deleteJson($url.'/'.$type->type_id)->assertStatus(405);
        $this->putJson($url.'/'.$type->type_id.'/form', ['form_id' => 1])->assertStatus(405);

        $this->assertSame(['Equipment Repair'], ProjectType::query()->pluck('type_name')->all());
    }

    public function test_an_admin_cannot_reach_the_project_type_endpoints(): void
    {
        $admin = User::create([
            'user_code' => 'EMP-0002',
            'name' => 'Test Admin',
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin@coliconstruct.test',
            'role' => 'admin',
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => false,
            'password' => 'test-password',
        ]);

        $this->actingAs($admin);

        $this->getJson(route('super-admin.configuration.project-types.index'))->assertStatus(403);
    }
}
