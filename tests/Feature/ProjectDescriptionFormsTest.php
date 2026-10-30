<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DetailForm;
use App\Models\DetailFormField;
use App\Models\Project;
use App\Models\ProjectDetail;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectDetailForms;
use App\Support\DetailFieldScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * A project's description: the detail form each of its types asks for -
 * fields filled once for the project and a list of units - filled in on the
 * create wizard's Project Details step, edited from Project Details, and
 * shown folded on the office, technician and client pages.
 */
class ProjectDescriptionFormsTest extends TestCase
{
    use RefreshDatabase;

    private ProjectType $installation;

    private ProjectType $ducting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();

        $installationForm = DetailForm::factory()->create(['name' => 'Installation']);
        DetailFormField::factory()->for($installationForm, 'form')->forProject()->dropdown(['Installation', 'Relocation'])->required()
            ->create(['label' => 'Work', 'field_key' => 'work', 'sort_order' => 0]);
        DetailFormField::factory()->for($installationForm, 'form')->dropdown(['LG', 'Daikin'])->required()
            ->create(['label' => 'Brand', 'field_key' => 'brand', 'sort_order' => 1]);
        DetailFormField::factory()->for($installationForm, 'form')->number()
            ->create(['label' => 'Capacity (HP)', 'field_key' => 'capacity_hp', 'sort_order' => 2]);

        $ductingForm = DetailForm::factory()->create(['name' => 'Ducting']);
        DetailFormField::factory()->for($ductingForm, 'form')->forProject()->required()
            ->create(['label' => 'Scope of work', 'field_key' => 'scope_of_work']);

        $this->installation = ProjectType::create(['type_name' => 'Aircon Installation', 'form_id' => $installationForm->form_id]);
        $this->ducting = ProjectType::create(['type_name' => 'Ducting Fabrication', 'form_id' => $ductingForm->form_id]);
    }

    public function test_the_wizard_draws_each_types_form_in_place_of_the_description_box(): void
    {
        $page = $this->get(route('super-admin.projects.create'))->assertOk();

        $page->assertSee('Step <span data-step-counter>1</span> of 4', false);
        $page->assertSee('data-details-type-name="Aircon Installation"', false);
        $page->assertSee('data-details-type-name="Ducting Fabrication"', false);
        $page->assertSee('name="details['.$this->installation->type_id.'][project][work]"', false);
        $page->assertSee('name="details['.$this->installation->type_id.'][units][0][brand]"', false);
        // The old box is still there, as optional Notes.
        $page->assertSee('Notes');
        $page->assertDontSee('name="project_description" id="projectDescription" rows="4"', false);
    }

    public function test_a_project_with_two_types_saves_project_fields_and_units_apart(): void
    {
        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'project_types' => ['Aircon Installation', 'Ducting Fabrication'],
            'project_description' => '',
            'details' => [
                $this->installation->type_id => [
                    'project' => ['work' => 'Relocation'],
                    'units' => [
                        ['brand' => 'LG', 'capacity_hp' => '1.5'],
                        ['brand' => 'Daikin', 'capacity_hp' => '2'],
                    ],
                ],
                $this->ducting->type_id => [
                    'project' => ['scope_of_work' => 'Fabricate 12 m of GI duct'],
                ],
            ],
        ]))->assertRedirect(route('super-admin.projects'));

        $project = Project::query()->sole();
        $installed = $project->details->where('type_id', $this->installation->type_id)->values();

        $this->assertNull($project->description);
        $this->assertCount(3, $installed);
        $this->assertSame(DetailFieldScope::Project, $installed[0]->scope);
        $this->assertSame(['work' => 'Relocation'], $installed[0]->values);
        $this->assertSame(['brand' => 'LG', 'capacity_hp' => '1.5'], $installed[1]->values);
        $this->assertSame(['brand' => 'Brand', 'capacity_hp' => 'Capacity (HP)'], $installed[1]->labels);
        $this->assertSame('Daikin', $installed[2]->values['brand']);

        $this->assertSame(
            ['scope_of_work' => 'Fabricate 12 m of GI duct'],
            $project->details->firstWhere('type_id', $this->ducting->type_id)->values
        );
    }

    public function test_a_required_project_field_left_blank_refuses_the_project(): void
    {
        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'details' => [$this->installation->type_id => [
                'project' => ['work' => ''],
                'units' => [['brand' => 'LG']],
            ]],
        ]))->assertSessionHasErrors(['details.'.$this->installation->type_id.'.project.work' => 'The Aircon Installation: Work field is required.']);

        $this->assertDatabaseCount('tbl_projects', 0);
    }

    public function test_a_unit_dropdown_value_not_on_the_list_is_refused(): void
    {
        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'details' => [$this->installation->type_id => [
                'project' => ['work' => 'Installation'],
                'units' => [['brand' => 'Unknown Brand']],
            ]],
        ]))->assertSessionHasErrors('details.'.$this->installation->type_id.'.units.0.brand');

        $this->assertDatabaseCount('tbl_projects', 0);
    }

    public function test_a_form_with_unit_fields_needs_at_least_one_unit(): void
    {
        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'details' => [$this->installation->type_id => ['project' => ['work' => 'Installation']]],
        ]))->assertSessionHasErrors(['details.'.$this->installation->type_id.'.units' => 'Add at least one unit for Aircon Installation.']);
    }

    public function test_a_form_with_only_project_fields_asks_for_no_units(): void
    {
        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'project_types' => ['Ducting Fabrication'],
            'details' => [$this->ducting->type_id => ['project' => ['scope_of_work' => 'Ducting']]],
        ]))->assertRedirect(route('super-admin.projects'));

        $this->assertSame(1, ProjectDetail::query()->count());
    }

    public function test_editing_keeps_the_values_of_fields_archived_since(): void
    {
        $project = $this->project();
        $capacity = DetailFormField::query()->where('field_key', 'capacity_hp')->sole();

        $detail = $this->unit($project, ['brand' => 'LG', 'capacity_hp' => '1'], ['brand' => 'Brand', 'capacity_hp' => 'Capacity (HP)']);

        $capacity->update(['archived_at' => now()]);

        $this->put(route('super-admin.projects.details.update', $project->project_id), [
            'details' => [
                $this->installation->type_id => [
                    'project' => ['work' => 'Installation'],
                    'units' => [
                        [ProjectDetailForms::DETAIL_ID_KEY => $detail->detail_id, 'brand' => 'Daikin'],
                        ['brand' => 'LG'],
                    ],
                ],
                $this->ducting->type_id => ['project' => ['scope_of_work' => 'Ducting']],
            ],
        ])->assertRedirect(route('super-admin.projects.show', $project->project_id));

        $units = $project->details()
            ->where('type_id', $this->installation->type_id)
            ->where('scope', DetailFieldScope::Unit)
            ->get();

        $this->assertCount(2, $units);
        $this->assertSame(['brand' => 'Daikin', 'capacity_hp' => '1'], $units[0]->values);
        $this->assertSame(['brand' => 'LG'], $units[1]->values);
    }

    public function test_a_refused_edit_reopens_on_its_own_error_bag(): void
    {
        $project = $this->project();

        $this->put(route('super-admin.projects.details.update', $project->project_id), [
            'details' => [$this->installation->type_id => ['project' => ['work' => ''], 'units' => [['brand' => 'LG']]]],
        ])->assertSessionHasErrorsIn('details', 'details.'.$this->installation->type_id.'.project.work');

        $this->assertDatabaseCount('tbl_project_details', 0);
    }

    public function test_the_office_page_folds_the_details_under_a_summary_with_the_notes(): void
    {
        $project = $this->project(['description' => 'Bring a ladder.']);
        $this->unit($project, ['brand' => 'LG', 'old_field' => 'Kept value'], ['brand' => 'Brand', 'old_field' => 'Old label']);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Edit Description')
            ->assertSee('data-bs-toggle="collapse"', false)
            ->assertSeeInOrder(['Project Description:', 'Aircon Installation', '1 unit', 'Show details', 'Unit 1', 'Brand', 'LG', 'Old label', 'Kept value', 'Notes', 'Bring a ladder.']);
    }

    public function test_a_projects_own_notes_still_show_when_it_has_no_details(): void
    {
        $project = $this->project(['description' => 'Legacy free-text description.']);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Legacy free-text description.')
            ->assertDontSee('Show details');
    }

    public function test_the_technician_page_shows_the_folded_description_without_editing(): void
    {
        $project = $this->project(['description' => 'Bring a ladder.']);
        $this->unit($project, ['brand' => 'Daikin'], ['brand' => 'Brand']);

        $technician = $this->technician('technician', 'Juan Technician');
        ProjectTechnician::create(['project_id' => $project->project_id, 'technician_id' => $technician->technician_id]);
        $technician->account->forceFill(['status' => User::STATUS_ACTIVE])->save();

        $this->actingAs($technician->account)
            ->get(route('technician.projects.show', $project))
            ->assertOk()
            ->assertSeeInOrder(['Project Description:', 'Aircon Installation', 'Show details', 'Brand', 'Daikin', 'Bring a ladder.'])
            ->assertDontSee('editProjectDescriptionModal');
    }

    public function test_the_client_page_shows_the_folded_description(): void
    {
        $client = $this->fixtureClient([
            'client_type' => 'Residential',
            'firstname' => 'Owner',
            'surname' => 'Person',
            'fullname' => 'Owner Person',
            'email_address' => 'owner@example.test',
            'contact_number' => '09123456789',
        ]);
        $project = $this->project(['client_id' => $client->client_id]);
        $this->unit($project, ['brand' => 'Daikin'], ['brand' => 'Brand']);

        $account = User::factory()->create(['name' => 'Owner Person', 'email' => 'owner@example.test']);
        $account->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE] + $this->acceptedTerms())->save();

        $this->actingAs($account)
            ->get(route('public.projects.show', $project->project_id))
            ->assertOk()
            ->assertSeeInOrder(['Project Description:', 'Aircon Installation', 'Show details', 'Brand', 'Daikin'])
            ->assertDontSee('editProjectDescriptionModal');
    }

    public function test_taking_a_type_off_a_project_drops_its_details(): void
    {
        $project = $this->project();
        $this->unit($project, ['brand' => 'LG'], ['brand' => 'Brand']);

        ProjectDetail::create([
            'project_id' => $project->project_id,
            'type_id' => $this->ducting->type_id,
            'form_id' => $this->ducting->form_id,
            'scope' => DetailFieldScope::Project,
            'values' => ['scope_of_work' => 'Ducting'],
            'labels' => ['scope_of_work' => 'Scope of work'],
        ]);

        $project->projectTypes()->sync([$this->ducting->type_id]);
        app(ProjectDetailForms::class)->pruneRemovedTypes($project);

        $this->assertSame([$this->ducting->type_id], $project->details()->pluck('type_id')->all());
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function project(array $attributes = []): Project
    {
        $project = Project::create(array_merge([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Description Project',
            'reference_no' => 'REF-DESC',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => null,
        ], $attributes));

        $project->projectTypes()->sync([$this->installation->type_id, $this->ducting->type_id]);

        return $project;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $labels
     */
    private function unit(Project $project, array $values, array $labels): ProjectDetail
    {
        return ProjectDetail::create([
            'project_id' => $project->project_id,
            'type_id' => $this->installation->type_id,
            'form_id' => $this->installation->form_id,
            'scope' => DetailFieldScope::Unit,
            'values' => $values,
            'labels' => $labels,
        ]);
    }

    private function technician(string $role, string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role])->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $lead = $this->technician('lead_technician', 'Lead Technician');
        $technician = $this->technician('technician', 'Juan Technician');

        return array_merge([
            'client_type' => 'Residential',
            'surname' => 'Dela Cruz',
            'firstname' => 'Juan',
            'client_email' => 'juan.dela.cruz@example.test',
            'client_phone' => '09123456789',
            'project_address' => '123 Sample Street, Sample City',
            'quotation_amount' => '1250.00',
            'project_types' => ['Aircon Installation'],
            'details' => [$this->installation->type_id => [
                'project' => ['work' => 'Installation'],
                'units' => [['brand' => 'LG']],
            ]],
            'assessment_report' => [UploadedFile::fake()->create('assessment.pdf', 12, 'application/pdf')],
            'approved_quotation' => [UploadedFile::fake()->create('quotation.pdf', 12, 'application/pdf')],
            'project_description' => 'Test notes',
            'target_end_date' => CarbonImmutable::today()->addDays(30)->toDateString(),
            'lead_tech' => $lead->technician_id,
            'technicians' => [$technician->technician_id],
            'start_date' => CarbonImmutable::today()->addDays(10)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(12)->toDateString(),
        ], $overrides);
    }
}
