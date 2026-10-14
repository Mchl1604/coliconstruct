<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\User;
use App\Support\MaterialUnit;
use Database\Seeders\MaterialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Configuration -> System Settings -> Materials: the list projects pick from.
 *
 * A reference list, not stock - so the rules are about keeping it one list:
 * one spelling of each material, a unit that never changes under a project
 * that used it, and nothing a project used ever deleted out from under it.
 */
class MaterialCatalogTest extends TestCase
{
    use RefreshDatabase;

    private MaterialType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->type = MaterialType::factory()->create(['type_name' => 'Aircon Units']);
    }

    public function test_the_super_admin_adds_and_edits_a_material(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson(route('super-admin.configuration.materials.store'), [
            'name' => 'Split-Type Inverter 1.5 HP',
            'brand' => 'Daikin',
            'material_type_id' => $this->type->material_type_id,
            'unit' => 'unit',
            'description' => 'R32',
        ])->assertOk()->assertJsonPath('material.label', 'Split-Type Inverter 1.5 HP (Daikin)');

        $material = Material::query()->firstOrFail();

        $this->putJson(route('super-admin.configuration.materials.update', $material), [
            'name' => 'Split-Type Inverter 1.5 HP',
            'brand' => 'Carrier',
            'material_type_id' => $this->type->material_type_id,
            'unit' => 'unit',
        ])->assertOk();

        $this->assertSame('Carrier', $material->fresh()->brand);
        $this->assertNull($material->fresh()->description);
        $this->assertSame(2, ActivityLog::query()->where('module', ActivityLog::MODULE_MATERIALS)->count());
    }

    public function test_the_list_is_the_super_admins_alone(): void
    {
        $admin = User::create([
            'user_code' => 'EMP-0002',
            'name' => 'An Admin',
            'first_name' => 'An',
            'last_name' => 'Admin',
            'email' => 'admin@example.test',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);

        $this->actingAs($admin)
            ->getJson(route('super-admin.configuration.materials.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson(route('super-admin.configuration.materials.store'), [
                'name' => 'Anything',
                'material_type_id' => $this->type->material_type_id,
                'unit' => 'pc',
            ])
            ->assertForbidden();

        $this->assertSame(0, Material::count());
    }

    public function test_the_same_name_brand_and_unit_is_listed_once(): void
    {
        $this->actingAsSuperAdmin();

        Material::factory()->brand('Daikin')->create(['name' => 'Split-Type Inverter 1.0 HP', 'unit' => MaterialUnit::Unit]);

        $this->postJson(route('super-admin.configuration.materials.store'), [
            'name' => '  split-type   inverter 1.0 hp ',
            'brand' => 'DAIKIN',
            'material_type_id' => $this->type->material_type_id,
            'unit' => 'unit',
        ])->assertStatus(422);

        // Another brand of the same unit is another material.
        $this->postJson(route('super-admin.configuration.materials.store'), [
            'name' => 'Split-Type Inverter 1.0 HP',
            'brand' => 'Panasonic',
            'material_type_id' => $this->type->material_type_id,
            'unit' => 'unit',
        ])->assertOk();

        $this->assertSame(2, Material::count());
    }

    public function test_a_used_material_is_deactivated_rather_than_deleted(): void
    {
        $this->actingAsSuperAdmin();

        $material = Material::factory()->create(['material_type_id' => $this->type->material_type_id]);
        $this->useOnAProject($material);

        $this->deleteJson(route('super-admin.configuration.materials.destroy', $material))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Used on projects. Deactivate it instead.');

        $this->putJson(route('super-admin.configuration.materials.status', $material), ['is_active' => false])
            ->assertOk();

        $this->assertFalse($material->fresh()->is_active);
        $this->assertTrue(ActivityLog::query()->where('action', ActivityLog::MATERIAL_DEACTIVATED)->exists());
    }

    public function test_an_unused_material_can_be_removed(): void
    {
        $this->actingAsSuperAdmin();

        $material = Material::factory()->create(['material_type_id' => $this->type->material_type_id]);

        $this->deleteJson(route('super-admin.configuration.materials.destroy', $material))->assertOk();

        $this->assertSame(0, Material::count());
    }

    public function test_a_used_material_keeps_its_unit(): void
    {
        $this->actingAsSuperAdmin();

        $material = Material::factory()->create(['material_type_id' => $this->type->material_type_id]);
        $this->useOnAProject($material);

        $this->putJson(route('super-admin.configuration.materials.update', $material), [
            'name' => $material->name,
            'material_type_id' => $this->type->material_type_id,
            'unit' => 'm',
        ])->assertStatus(422)->assertJsonPath('error', 'Used on projects. The unit cannot change.');

        $this->assertSame(MaterialUnit::Piece, $material->fresh()->unit);
    }

    public function test_types_are_added_renamed_and_removed_only_when_empty(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson(route('super-admin.configuration.materials.types.store'), ['type_name' => 'Refrigerants'])
            ->assertOk();

        $refrigerants = MaterialType::query()->where('type_name', 'Refrigerants')->firstOrFail();

        $this->putJson(route('super-admin.configuration.materials.types.update', $refrigerants), ['type_name' => 'Refrigerants & Gases'])
            ->assertOk();

        $this->postJson(route('super-admin.configuration.materials.types.store'), ['type_name' => 'refrigerants & gases'])
            ->assertStatus(422);

        Material::factory()->create(['material_type_id' => $this->type->material_type_id]);

        $this->deleteJson(route('super-admin.configuration.materials.types.destroy', $this->type))
            ->assertStatus(422)
            ->assertJsonPath('error', 'It has 1 material. Move them first.');

        $this->deleteJson(route('super-admin.configuration.materials.types.destroy', $refrigerants))->assertOk();

        $this->assertSame(['Aircon Units'], MaterialType::query()->pluck('type_name')->all());
    }

    public function test_the_list_pages_and_filters_on_the_server(): void
    {
        $this->actingAsSuperAdmin();

        $other = MaterialType::factory()->create(['type_name' => 'Electrical']);

        Material::factory()->brand('Daikin')->create(['material_type_id' => $this->type->material_type_id, 'name' => 'Cassette 3.0 HP']);
        Material::factory()->inactive()->create(['material_type_id' => $this->type->material_type_id, 'name' => 'Retired Window Unit']);
        Material::factory()->create(['material_type_id' => $other->material_type_id, 'name' => 'THHN Wire']);

        $this->getTablePage(route('super-admin.configuration.materials.index'), ['type' => $this->type->material_type_id])
            ->assertSee('Cassette 3.0 HP')
            ->assertSee('Retired Window Unit')
            ->assertDontSee('THHN Wire');

        $this->getTablePage(route('super-admin.configuration.materials.index'), ['status' => 'active'])
            ->assertSee('THHN Wire')
            ->assertDontSee('Retired Window Unit');

        $this->getTablePage(route('super-admin.configuration.materials.index'), ['search' => 'daikin'])
            ->assertSee('Cassette 3.0 HP')
            ->assertDontSee('THHN Wire');
    }

    public function test_the_settings_page_lists_materials(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('data-content-section="materials"', false)
            ->assertSee('Material Types');
    }

    public function test_the_seeder_lists_aircon_units_by_brand_and_adds_nothing_twice(): void
    {
        $this->seed(MaterialSeeder::class);

        $count = Material::count();

        $this->assertGreaterThan(50, $count);
        $this->assertTrue(
            Material::query()
                ->where('name', 'Split-Type Inverter 1.5 HP')
                ->whereIn('brand', ['Daikin', 'Carrier', 'Panasonic', 'Koppel', 'LG', 'Samsung'])
                ->count() === 6
        );
        $this->assertSame(
            MaterialUnit::Unit,
            Material::query()->where('brand', 'Daikin')->firstOrFail()->unit
        );

        $this->seed(MaterialSeeder::class);

        $this->assertSame($count, Material::count());
    }

    private function useOnAProject(Material $material): void
    {
        $project = Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Aircon Installation',
            'reference_no' => 'REF-CAT-1',
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        ProjectMaterial::create([
            'project_id' => $project->project_id,
            'material_id' => $material->material_id,
            'quantity_needed' => 1,
        ]);
    }
}
