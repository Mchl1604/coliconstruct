<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Material;
use App\Models\User;
use App\Support\MaterialUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inventory -> Materials -> Brands, and the reorder level a material carries.
 *
 * One spelling of each brand, renamed in one place for every material that
 * carries it, and never removed out from under one.
 */
class BrandListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    public function test_brands_are_added_renamed_and_removed_only_when_unused(): void
    {
        $this->postJson(route('inventory.materials.brands.store'), ['brand_name' => '  Daikin  '])
            ->assertOk()
            ->assertJsonPath('brands.0.brand_name', 'Daikin');

        $this->postJson(route('inventory.materials.brands.store'), ['brand_name' => 'DAIKIN'])
            ->assertStatus(422)
            ->assertJsonPath('error', "'DAIKIN' already exists.");

        $daikin = Brand::query()->firstOrFail();
        $material = Material::factory()->create(['brand_id' => $daikin->brand_id, 'name' => 'Cassette 3.0 HP']);

        $this->putJson(route('inventory.materials.brands.update', $daikin), ['brand_name' => 'Daikin Aircon'])
            ->assertOk();

        // One row, so every material reads the new name.
        $this->assertSame('Cassette 3.0 HP (Daikin Aircon)', $material->fresh()->displayName());

        $this->deleteJson(route('inventory.materials.brands.destroy', $daikin))
            ->assertStatus(422)
            ->assertJsonPath('error', 'It has 1 material. Change them first.');

        $material->update(['brand_id' => null]);

        $this->deleteJson(route('inventory.materials.brands.destroy', $daikin))->assertOk();

        $this->assertSame(0, Brand::count());
        $this->assertSame(3, ActivityLog::query()->where('action', 'like', 'Brand %')->count());
    }

    public function test_a_material_takes_its_brand_from_the_list(): void
    {
        $type = Material::factory()->create()->type;

        $this->postJson(route('inventory.materials.store'), [
            'name' => 'Split-Type Inverter 1.0 HP',
            'brand_id' => 999,
            'material_type_id' => $type->material_type_id,
            'unit' => 'unit',
        ])->assertStatus(422)->assertJsonPath('error', 'Choose a brand from the list.');
    }

    public function test_a_material_keeps_a_reorder_level_held_to_its_unit(): void
    {
        $material = Material::factory()->create(['name' => 'Wall Bracket', 'unit' => MaterialUnit::Piece]);
        $payload = [
            'name' => 'Wall Bracket',
            'material_type_id' => $material->material_type_id,
            'unit' => 'pc',
        ];

        $this->putJson(route('inventory.materials.update', $material), $payload + ['reorder_level' => '2.5'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Reorder level: whole numbers only for piece.');

        $this->putJson(route('inventory.materials.update', $material), $payload + ['reorder_level' => '10'])
            ->assertOk()
            ->assertJsonPath('material.reorder_level', 10);

        $this->putJson(route('inventory.materials.update', $material), $payload + ['reorder_level' => ''])
            ->assertOk();

        $this->assertNull($material->fresh()->reorder_level);
    }

    public function test_an_hr_admin_cannot_keep_the_brands(): void
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

        $this->postJson(route('inventory.materials.brands.store'), ['brand_name' => 'Carrier'])->assertForbidden();

        $this->assertSame(0, Brand::count());
    }
}
