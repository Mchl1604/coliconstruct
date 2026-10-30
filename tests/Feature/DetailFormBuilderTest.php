<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DetailForm;
use App\Models\DetailFormField;
use App\Models\Project;
use App\Models\ProjectDetail;
use App\Models\ProjectType;
use App\Models\User;
use App\Support\DetailFieldScope;
use App\Support\DetailFieldType;
use Database\Seeders\DetailFormSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Configuration -> Project Types -> Detail Forms: the forms a project type
 * asks for in the create wizard, built and edited by the Super Admin only.
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

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ducting Details',
            'fields' => [
                ['label' => 'Duct size', 'input_type' => 'text', 'scope' => 'project', 'is_required' => true],
                ['label' => 'Material', 'input_type' => 'select', 'scope' => 'unit', 'options' => ['GI', 'Aluminum']],
            ],
        ], $overrides);
    }

    public function test_the_super_admin_creates_a_form_with_its_fields_in_order(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson(route('super-admin.configuration.detail-forms.store'), $this->payload())
            ->assertOk()
            ->assertJsonPath('form.name', 'Ducting Details')
            ->assertJsonPath('form.fields.0.field_key', 'duct_size')
            ->assertJsonPath('form.fields.1.options', ['GI', 'Aluminum']);

        $form = DetailForm::query()->sole();

        $this->assertSame(['duct_size', 'material'], $form->fields->pluck('field_key')->all());
        $this->assertTrue($form->fields->first()->is_required);
        $this->assertSame(['duct_size'], $form->projectFields()->pluck('field_key')->all());
        $this->assertSame(['material'], $form->unitFields()->pluck('field_key')->all());
    }

    public function test_an_admin_cannot_read_or_change_forms(): void
    {
        $form = DetailForm::factory()->create();
        $type = ProjectType::create(['type_name' => 'Aircon Repair']);

        $this->actingAsAdmin();

        $this->getJson(route('super-admin.configuration.detail-forms.index'))->assertForbidden();
        $this->postJson(route('super-admin.configuration.detail-forms.store'), $this->payload())->assertForbidden();
        $this->putJson(route('super-admin.configuration.detail-forms.update', $form), $this->payload())->assertForbidden();
        $this->deleteJson(route('super-admin.configuration.detail-forms.destroy', $form))->assertForbidden();
        $this->putJson(route('super-admin.configuration.project-types.form', $type), ['form_id' => $form->form_id])->assertForbidden();

        $this->assertNull($type->refresh()->form_id);
    }

    public function test_a_dropdown_needs_at_least_one_option(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson(route('super-admin.configuration.detail-forms.store'), $this->payload([
            'fields' => [['label' => 'Brand', 'input_type' => 'select', 'scope' => 'unit', 'options' => ['', ' ']]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Add options for each dropdown or checkbox field.');

        $this->assertDatabaseCount('tbl_detail_forms', 0);
    }

    public function test_renaming_a_field_keeps_its_key(): void
    {
        $this->actingAsSuperAdmin();

        $field = DetailFormField::factory()->create(['label' => 'Brand', 'field_key' => 'brand']);

        $this->putJson(route('super-admin.configuration.detail-forms.update', $field->form_id), [
            'name' => $field->form->name,
            'fields' => [['field_id' => $field->field_id, 'label' => 'Unit brand', 'input_type' => 'text', 'scope' => 'unit']],
        ])->assertOk();

        $this->assertSame('brand', $field->refresh()->field_key);
        $this->assertSame('Unit brand', $field->label);
    }

    public function test_a_removed_field_is_archived_once_a_project_used_the_form_and_deleted_before(): void
    {
        $this->actingAsSuperAdmin();

        $form = DetailForm::factory()->create();
        $kept = DetailFormField::factory()->for($form, 'form')->create();
        $dropped = DetailFormField::factory()->for($form, 'form')->create();

        $update = fn (): array => [
            'name' => $form->name,
            'fields' => [['field_id' => $kept->field_id, 'label' => $kept->label, 'input_type' => 'text', 'scope' => 'unit']],
        ];

        // Unused: the field simply goes.
        $this->putJson(route('super-admin.configuration.detail-forms.update', $form), $update())->assertOk();
        $this->assertModelMissing($dropped);

        // Used: a field dropped now is kept, archived, for the projects that
        // were saved with it.
        $second = DetailFormField::factory()->for($form, 'form')->create();
        $type = ProjectType::create(['type_name' => 'Aircon Repair', 'form_id' => $form->form_id]);
        $project = $this->project();
        ProjectDetail::create([
            'project_id' => $project->project_id,
            'type_id' => $type->type_id,
            'form_id' => $form->form_id,
            'values' => [$second->field_key => 'Old value'],
            'labels' => [$second->field_key => $second->label],
        ]);

        $this->putJson(route('super-admin.configuration.detail-forms.update', $form), $update())->assertOk();

        $this->assertNotNull($second->refresh()->archived_at);
        $this->assertSame([$kept->field_id], $form->activeFields()->pluck('field_id')->all());
    }

    public function test_a_form_in_use_by_a_type_cannot_be_removed(): void
    {
        $this->actingAsSuperAdmin();

        $form = DetailForm::factory()->create(['name' => 'Installation']);
        ProjectType::create(['type_name' => 'Aircon Installation', 'form_id' => $form->form_id]);

        $this->deleteJson(route('super-admin.configuration.detail-forms.destroy', $form))
            ->assertStatus(422)
            ->assertJsonPath('error', "'Installation' is used by 1 project type. Change those first.");

        $this->assertModelExists($form);
    }

    public function test_a_type_is_pointed_at_another_form_but_never_at_none(): void
    {
        $this->actingAsSuperAdmin();

        $form = DetailForm::factory()->create();
        $type = ProjectType::create(['type_name' => 'Ducting Installation']);

        $this->putJson(route('super-admin.configuration.project-types.form', $type), ['form_id' => $form->form_id])
            ->assertOk()
            ->assertJsonFragment(['type_name' => 'Ducting Installation', 'form_id' => $form->form_id]);

        $this->putJson(route('super-admin.configuration.project-types.form', $type), ['form_id' => null])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Pick a form.');

        $this->assertSame($form->form_id, $type->refresh()->form_id);
    }

    public function test_a_new_type_cannot_be_added_without_a_form(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson(route('super-admin.configuration.project-types.store'), ['type_name' => 'Aircon Relocation'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Pick a form.');

        $this->assertDatabaseCount('tbl_project_types', 0);
    }

    public function test_a_new_type_can_be_given_its_form_as_it_is_added(): void
    {
        $this->actingAsSuperAdmin();

        $form = DetailForm::factory()->create();

        $this->postJson(route('super-admin.configuration.project-types.store'), [
            'type_name' => 'Aircon Relocation',
            'form_id' => $form->form_id,
        ])->assertOk();

        $this->assertSame($form->form_id, ProjectType::query()->where('type_name', 'Aircon Relocation')->value('form_id'));
    }

    public function test_the_seeder_gives_every_type_a_form_once(): void
    {
        $installation = ProjectType::create(['type_name' => 'Aircon Installation']);
        $repair = ProjectType::create(['type_name' => 'Aircon Repair']);
        $ducting = ProjectType::create(['type_name' => 'Ducting Installation']);
        $other = ProjectType::create(['type_name' => 'Solar Panels']);

        $this->seed(DetailFormSeeder::class);
        $this->seed(DetailFormSeeder::class);

        $this->assertSame(
            ['Cleaning', 'Ducting', 'General', 'Installation', 'Repair', 'Ventilation'],
            DetailForm::query()->orderBy('name')->pluck('name')->all()
        );
        $this->assertSame('Installation', $installation->refresh()->detailForm->name);
        $this->assertSame('Repair', $repair->refresh()->detailForm->name);
        $this->assertSame('Ducting', $ducting->refresh()->detailForm->name);
        $this->assertSame('General', $other->refresh()->detailForm->name);

        $repairForm = DetailForm::query()->where('name', 'Repair')->sole();
        $trouble = $repairForm->fields->firstWhere('field_key', 'trouble_description');

        $this->assertSame(DetailFieldType::LongText, $trouble->input_type);
        $this->assertSame(DetailFieldScope::Project, $trouble->scope);
        $this->assertTrue($trouble->is_required);
        $this->assertTrue($repairForm->hasUnits());
    }

    private function project(): Project
    {
        return Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Units Project',
            'reference_no' => 'REF-UNITS',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);
    }
}
