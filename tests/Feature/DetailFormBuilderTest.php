<?php

namespace Tests\Feature;

use App\Models\DetailForm;
use App\Models\ProjectType;
use App\Models\User;
use App\Services\DetailFormCatalog;
use App\Support\DetailFieldScope;
use App\Support\DetailFieldType;
use Database\Seeders\DetailFormSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Configuration -> Project Types -> Detail Forms: the fixed forms a project
 * type asks for in the create wizard, read-only on screen.
 */
class DetailFormBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
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

        $this->actingAs($admin);

        return $admin;
    }

    public function test_an_admin_cannot_read_forms(): void
    {
        $form = DetailForm::factory()->create();

        $this->actingAsAdmin();

        $this->getJson(route('super-admin.configuration.detail-forms.index'))->assertForbidden();
        $this->getJson(route('super-admin.configuration.detail-forms.show', $form))->assertForbidden();
    }

    public function test_the_super_admin_views_a_form_but_cannot_change_it(): void
    {
        $this->actingAsSuperAdmin();
        $form = DetailForm::factory()->create(['name' => 'Fixed Form']);
        $url = route('super-admin.configuration.detail-forms.show', $form);

        $this->getJson($url)->assertOk()->assertJsonPath('form.name', 'Fixed Form');
        $this->postJson(route('super-admin.configuration.detail-forms.index'), ['name' => 'New'])->assertStatus(405);
        $this->putJson($url, ['name' => 'Renamed'])->assertStatus(405);
        $this->deleteJson($url)->assertStatus(405);

        $this->assertSame('Fixed Form', $form->refresh()->name);
    }

    public function test_a_field_dropped_from_a_definition_is_archived_and_comes_back_with_its_key(): void
    {
        $catalog = app(DetailFormCatalog::class);
        $field = fn (string $key): array => ['field_key' => $key, 'label' => ucfirst($key), 'input_type' => 'text', 'scope' => 'unit', 'options' => [], 'is_required' => false];

        $form = $catalog->define('Fixed Form', [$field('model'), $field('brand')]);
        $catalog->define('Fixed Form', [$field('brand')]);

        $this->assertNotNull($form->fields()->where('field_key', 'model')->sole()->archived_at);

        $catalog->define('Fixed Form', [$field('model'), $field('brand')]);

        $this->assertNull($form->fields()->where('field_key', 'model')->sole()->archived_at);
        $this->assertSame(2, $form->fields()->count());
    }

    public function test_the_seeder_gives_every_type_a_form_once(): void
    {
        $installation = ProjectType::create(['type_name' => 'Aircon Installation']);
        $repair = ProjectType::create(['type_name' => 'Equipment Repair']);
        $checkUp = ProjectType::create(['type_name' => 'Equipment Check-up']);
        $ducting = ProjectType::create(['type_name' => 'Ducting Installation']);
        $other = ProjectType::create(['type_name' => 'Solar Panels']);

        $this->seed(DetailFormSeeder::class);
        $this->seed(DetailFormSeeder::class);

        $this->assertSame(
            ['Cleaning', 'Ducting', 'Equipment Check-up', 'Equipment Repair', 'General', 'Installation'],
            DetailForm::query()->orderBy('name')->pluck('name')->all()
        );
        $this->assertSame('Installation', $installation->refresh()->detailForm->name);
        $this->assertSame('Equipment Repair', $repair->refresh()->detailForm->name);
        $this->assertSame('Equipment Check-up', $checkUp->refresh()->detailForm->name);
        $this->assertSame('Ducting', $ducting->refresh()->detailForm->name);
        $this->assertSame('General', $other->refresh()->detailForm->name);

        $repairForm = DetailForm::query()->where('name', 'Equipment Repair')->sole();
        $trouble = $repairForm->fields->firstWhere('field_key', 'trouble_description');

        $this->assertSame(DetailFieldType::LongText, $trouble->input_type);
        $this->assertSame(DetailFieldScope::Unit, $trouble->scope);
        $this->assertTrue($trouble->is_required);
        $this->assertTrue($repairForm->hasUnits());
    }
}
