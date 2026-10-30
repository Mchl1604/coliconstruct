<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\MaterialSubtype;
use App\Models\MaterialType;
use App\Models\User;
use Database\Seeders\MaterialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inventory -> Materials -> Sub-types: the kinds within a material type -
 * Split-Type and Window-Type within Aircon Units.
 */
class MaterialSubtypeTest extends TestCase
{
    use RefreshDatabase;

    private MaterialType $aircon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();

        $this->aircon = MaterialType::factory()->create(['type_name' => 'Aircon Units']);
    }

    public function test_sub_types_are_kept_per_type(): void
    {
        $this->postJson(route('inventory.materials.subtypes.store', $this->aircon), ['subtype_name' => ' Split-Type '])
            ->assertOk()
            ->assertJsonPath('types.0.subtypes.0.subtype_name', 'Split-Type');

        $this->postJson(route('inventory.materials.subtypes.store', $this->aircon), ['subtype_name' => 'split-type'])
            ->assertStatus(422)
            ->assertJsonPath('error', "'split-type' already exists.");

        // The same name under another type is another sub-type.
        $copper = MaterialType::factory()->create(['type_name' => 'Copper & Fittings']);
        $pvc = MaterialType::factory()->create(['type_name' => 'Drainage & PVC']);

        $this->postJson(route('inventory.materials.subtypes.store', $copper), ['subtype_name' => 'Fittings'])->assertOk();
        $this->postJson(route('inventory.materials.subtypes.store', $pvc), ['subtype_name' => 'Fittings'])->assertOk();

        $split = MaterialSubtype::query()->where('subtype_name', 'Split-Type')->firstOrFail();

        $this->putJson(route('inventory.materials.subtypes.update', $split), ['subtype_name' => 'Split-Type (Wall)'])
            ->assertOk();

        $this->assertSame('Split-Type (Wall)', $split->fresh()->subtype_name);
        $this->assertSame(4, ActivityLog::query()->where('action', 'like', 'Material Sub-type %')->count());
    }

    public function test_a_sub_type_with_materials_cannot_be_removed(): void
    {
        $split = MaterialSubtype::factory()->create(['material_type_id' => $this->aircon->material_type_id, 'subtype_name' => 'Split-Type']);
        $material = Material::factory()->create([
            'material_type_id' => $this->aircon->material_type_id,
            'material_subtype_id' => $split->material_subtype_id,
        ]);

        $this->deleteJson(route('inventory.materials.subtypes.destroy', $split))
            ->assertStatus(422)
            ->assertJsonPath('error', 'It has 1 material. Move them first.');

        $material->update(['material_subtype_id' => null]);

        $this->deleteJson(route('inventory.materials.subtypes.destroy', $split))->assertOk();

        $this->assertSame(0, MaterialSubtype::count());
    }

    public function test_a_material_takes_a_sub_type_of_its_own_type(): void
    {
        $split = MaterialSubtype::factory()->create(['material_type_id' => $this->aircon->material_type_id, 'subtype_name' => 'Split-Type']);
        $copper = MaterialType::factory()->create(['type_name' => 'Copper & Fittings']);
        $tube = MaterialSubtype::factory()->create(['material_type_id' => $copper->material_type_id, 'subtype_name' => 'Copper Tube']);

        $payload = [
            'name' => 'Split-Type Inverter 1.5 HP',
            'material_type_id' => $this->aircon->material_type_id,
            'unit' => 'unit',
        ];

        $this->postJson(route('inventory.materials.store'), $payload + ['material_subtype_id' => $tube->material_subtype_id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Choose a sub-type of this type.');

        $this->postJson(route('inventory.materials.store'), $payload + ['material_subtype_id' => $split->material_subtype_id])
            ->assertOk()
            ->assertJsonPath('material.subtype', 'Split-Type');

        $material = Material::query()->firstOrFail();

        $this->getTablePage(route('inventory.materials.index'))->assertSee('Split-Type');

        // Cleared by sending none.
        $this->putJson(route('inventory.materials.update', $material), $payload + ['material_subtype_id' => ''])->assertOk();

        $this->assertNull($material->fresh()->material_subtype_id);
    }

    public function test_both_tables_filter_on_the_sub_type(): void
    {
        $split = MaterialSubtype::factory()->create(['material_type_id' => $this->aircon->material_type_id, 'subtype_name' => 'Split-Type']);
        $window = MaterialSubtype::factory()->create(['material_type_id' => $this->aircon->material_type_id, 'subtype_name' => 'Window-Type']);

        Material::factory()->create(['material_type_id' => $this->aircon->material_type_id, 'material_subtype_id' => $split->material_subtype_id, 'name' => 'Wall Unit One']);
        Material::factory()->create(['material_type_id' => $this->aircon->material_type_id, 'material_subtype_id' => $window->material_subtype_id, 'name' => 'Window Unit One']);

        foreach (['inventory.materials.index', 'inventory.stock.index'] as $route) {
            $this->getTablePage(route($route), ['type' => $this->aircon->material_type_id, 'subtype' => $window->material_subtype_id])
                ->assertSee('Window Unit One')
                ->assertDontSee('Wall Unit One');

            $this->getTablePage(route($route), ['search' => 'split-type'])
                ->assertSee('Wall Unit One')
                ->assertDontSee('Window Unit One');
        }
    }

    public function test_the_starting_list_files_materials_by_name(): void
    {
        $this->seed(MaterialSeeder::class);

        $subtypeOf = fn (string $name): ?string => Material::query()->where('name', $name)->firstOrFail()->subtypeName();

        $this->assertSame('Split-Type', $subtypeOf('Split-Type Inverter 1.5 HP'));
        $this->assertSame('Window-Type', $subtypeOf('Window-Type Inverter 1.0 HP'));
        $this->assertSame('Ceiling Cassette', $subtypeOf('Ceiling Cassette Inverter 3.0 HP'));
        $this->assertSame('Ducted', $subtypeOf('Ducted Split 5.0 TR'));
        $this->assertSame('VRF', $subtypeOf('VRF Outdoor Unit 10 HP'));
        $this->assertSame('Copper Tube', $subtypeOf('Copper Tube 1/4"'));
        $this->assertSame('Fittings', $subtypeOf('Copper Elbow 5/8"'));
        $this->assertSame('Wire & Cable', $subtypeOf('Control Cable 4-Core'));
        $this->assertSame('Electrical Tape', $subtypeOf('Electrical Tape'));
        $this->assertSame('Motors & Compressors', $subtypeOf('Indoor Fan Motor, Split-Type'));
        $this->assertNull($subtypeOf('Teflon Tape'));

        $this->assertSame(8, $this->aircon->subtypes()->count());
    }

    public function test_the_starting_list_never_undoes_what_purchasing_arranged(): void
    {
        $this->seed(MaterialSeeder::class);

        $unit = Material::query()->where('name', 'Window-Type Inverter 1.0 HP')->firstOrFail();
        $split = MaterialSubtype::query()->where('subtype_name', 'Split-Type')->firstOrFail();

        $unit->update(['material_subtype_id' => $split->material_subtype_id]);
        $split->update(['subtype_name' => 'Split-type']);

        $count = MaterialSubtype::count();

        MaterialSubtype::applyStartingList();

        $this->assertSame($count, MaterialSubtype::count());
        $this->assertSame('Split-type', $unit->fresh()->subtypeName());
    }

    public function test_an_hr_admin_cannot_keep_the_sub_types(): void
    {
        $this->actingAs(User::create([
            'user_code' => 'EMP-0002',
            'name' => 'Hr Admin',
            'first_name' => 'Hr',
            'last_name' => 'Admin',
            'email' => 'hr@example.test',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]));

        $this->postJson(route('inventory.materials.subtypes.store', $this->aircon), ['subtype_name' => 'Split-Type'])
            ->assertForbidden();

        $this->assertSame(0, MaterialSubtype::count());
    }
}
