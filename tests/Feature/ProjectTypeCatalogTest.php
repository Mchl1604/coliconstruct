<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Skill;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Project Types: the catalogue of work the company does.
 *
 * A project type and a technician specialty are the same entry wearing two
 * hats, so every write to one has to land on the other - that is the whole
 * point of managing them from one screen.
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

    public function test_adding_a_project_type_also_adds_the_matching_specialty(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson(
            route('super-admin.configuration.project-types.store'),
            ['type_name' => 'Heating Installation']
        );

        $response->assertOk();

        $this->assertNotNull(ProjectType::query()->where('type_name', 'Heating Installation')->first());
        $this->assertNotNull($this->skillNamed('Heating Installation'));

        // And it comes back in the list the screen renders.
        $names = collect($response->json('types'))->pluck('type_name')->all();
        $this->assertContains('Heating Installation', $names);
    }

    /**
     * The specialty pickers read tbl_skills, so this is the whole of "it also
     * shows up as a specialty" as far as the interface is concerned.
     */
    public function test_a_new_type_is_offered_on_the_technicians_page(): void
    {
        $this->actingAsSuperAdmin();
        $this->technician('Ana Mendoza');

        $this->postJson(
            route('super-admin.configuration.project-types.store'),
            ['type_name' => 'Heating Installation']
        )->assertOk();

        $response = $this->get(route('super-admin.technicians.index'));

        $response->assertOk();
        $response->assertSee('Heating Installation');
    }

    public function test_renaming_a_type_renames_its_specialty(): void
    {
        $this->actingAsSuperAdmin();

        $type = ProjectType::create(['type_name' => 'Heating Installation']);
        Skill::create(['skill_name' => 'Heating Installation']);

        $this->putJson(
            route('super-admin.configuration.project-types.update', $type->type_id),
            ['type_name' => 'Heating Repair']
        )->assertOk();

        $this->assertSame('Heating Repair', $type->fresh()->type_name);
        $this->assertNotNull($this->skillNamed('Heating Repair'));
        $this->assertNull($this->skillNamed('Heating Installation'));
    }

    public function test_removing_an_unused_type_removes_its_specialty(): void
    {
        $this->actingAsSuperAdmin();

        $type = ProjectType::create(['type_name' => 'Heating Installation']);
        Skill::create(['skill_name' => 'Heating Installation']);

        $this->deleteJson(
            route('super-admin.configuration.project-types.destroy', $type->type_id)
        )->assertOk();

        $this->assertNull(ProjectType::query()->find($type->type_id));
        $this->assertNull($this->skillNamed('Heating Installation'));
    }

    public function test_a_type_used_by_a_project_cannot_be_removed(): void
    {
        $this->actingAsSuperAdmin();

        $type = ProjectType::create(['type_name' => 'Heating Installation']);
        Skill::create(['skill_name' => 'Heating Installation']);

        $this->project('Some Project')->projectTypes()->attach($type->type_id);

        $response = $this->deleteJson(
            route('super-admin.configuration.project-types.destroy', $type->type_id)
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot be removed', (string) $response->json('error'));

        // Neither half was touched.
        $this->assertNotNull(ProjectType::query()->find($type->type_id));
        $this->assertNotNull($this->skillNamed('Heating Installation'));
    }

    public function test_a_specialty_held_by_a_technician_cannot_be_removed(): void
    {
        $this->actingAsSuperAdmin();

        $type = ProjectType::create(['type_name' => 'Heating Installation']);
        $skill = Skill::create(['skill_name' => 'Heating Installation']);

        $this->technician('Ana Mendoza')->skills()->attach($skill->skill_id);

        $response = $this->deleteJson(
            route('super-admin.configuration.project-types.destroy', $type->type_id)
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('specialty', (string) $response->json('error'));
        $this->assertNotNull($this->skillNamed('Heating Installation'));
    }

    public function test_a_duplicate_name_is_refused_whatever_its_casing(): void
    {
        $this->actingAsSuperAdmin();

        ProjectType::create(['type_name' => 'Heating Installation']);

        $response = $this->postJson(
            route('super-admin.configuration.project-types.store'),
            ['type_name' => '  heating   installation  ']
        );

        $response->assertStatus(422);
        $this->assertSame(1, ProjectType::query()->count());
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
        $this->postJson(
            route('super-admin.configuration.project-types.store'),
            ['type_name' => 'Heating Installation']
        )->assertStatus(403);

        $this->assertSame(0, ProjectType::query()->count());
    }
}
