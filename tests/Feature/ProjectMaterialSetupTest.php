<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectPhase;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectMaterialPlan;
use App\Support\MaterialUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What project setup leaves to the create wizard, and the listings' Set Up Now.
 *
 * Setup is phases only. The material plan is written by the create wizard, so
 * Save and Finish Setup must leave it exactly as it stands - it is on view on
 * the Materials tab from the start, and recording opens once setup is done.
 */
class ProjectMaterialSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $admin;

    private User $leadAccount;

    private User $mateAccount;

    private Project $project;

    private MaterialType $type;

    private Material $aircon;

    private Material $copper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->account('super_admin', 'owner@example.test');
        $this->admin = $this->account('admin', 'admin@example.test');
        $this->leadAccount = $this->account('lead_technician', 'lead@example.test');
        $this->mateAccount = $this->account('technician', 'mate@example.test');

        $this->project = $this->project('REF-SETUP-1');

        $this->assign($this->project, $this->technician($this->leadAccount));
        $this->assign($this->project, $this->technician($this->mateAccount));

        $this->type = MaterialType::factory()->create(['type_name' => 'Aircon Units']);

        $this->aircon = Material::factory()->brand('Carrier')->create([
            'material_type_id' => $this->type->material_type_id,
            'name' => 'Split-Type Inverter 2.0 HP',
            'unit' => MaterialUnit::Unit,
        ]);

        $this->copper = Material::factory()->measured()->create([
            'material_type_id' => $this->type->material_type_id,
            'name' => 'Copper Tube 3/8"',
        ]);
    }

    // ------------------------------------------------------------------
    // The setup screen
    // ------------------------------------------------------------------

    public function test_the_setup_screen_asks_for_phases_only(): void
    {
        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.phases.setup', $this->project->project_id))
            ->assertOk()
            ->assertSee('Set Up Project')
            ->assertSee('Phase Structure')
            ->assertDontSee('Materials Needed')
            ->assertDontSee('Add Material')
            ->assertDontSee('materials_submitted', false)
            ->assertDontSee('setupMaterials.js', false)
            ->assertDontSee('materialPicker.js', false)
            ->assertDontSee('data-material-summary', false);
    }

    public function test_the_setup_screen_goes_straight_to_finish_setup(): void
    {
        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.phases.setup', $this->project->project_id))
            ->assertOk()
            ->assertSee('Finish Setup')
            ->assertDontSee('data-setup-next', false)
            ->assertDontSee('data-setup-finish', false)
            ->assertDontSee('Without Locking')
            ->assertDontSee('Finalize Phases')
            ->assertDontSee('data-phase-save', false);
    }

    public function test_saving_the_phases_leaves_the_plan_alone(): void
    {
        $this->plan([
            ['material_id' => $this->aircon->material_id, 'quantity' => '3', 'notes' => 'Bedrooms'],
            ['material_id' => $this->copper->material_id, 'quantity' => '12.5'],
        ]);

        $this->submit('save')
            ->assertRedirect(route('super-admin.projects.phases.setup', $this->project->project_id));

        $this->assertTrue($this->project->fresh()->awaitingInitialSetup());
        $this->assertEquals(3, $this->line($this->aircon)->quantity_needed);
        $this->assertSame('Bedrooms', $this->line($this->aircon)->notes);
        $this->assertEquals(12.5, $this->line($this->copper)->quantity_needed);
    }

    public function test_finishing_setup_keeps_the_plan_and_records_it(): void
    {
        $this->plan([['material_id' => $this->aircon->material_id, 'quantity' => '2']]);

        $this->submit('finalize')
            ->assertRedirect(route('super-admin.projects.show', $this->project->project_id));

        $project = $this->project->fresh();

        $this->assertTrue($project->phasesAreFinalized());
        $this->assertEquals(2, $this->line($this->aircon)->quantity_needed);
        $this->assertTrue(
            ActivityLog::query()
                ->where('action', ActivityLog::PROJECT_MATERIALS_PLANNED)
                ->where('description', 'Planned 1 material for REF-SETUP-1.')
                ->exists()
        );
    }

    public function test_a_job_may_be_set_up_with_no_materials(): void
    {
        $this->submit('finalize')->assertRedirect();

        $this->assertTrue($this->project->fresh()->phasesAreFinalized());
        $this->assertSame(0, ProjectMaterial::count());
        $this->assertTrue(
            ActivityLog::query()
                ->where('action', ActivityLog::PROJECT_MATERIALS_PLANNED)
                ->where('description', 'Set up REF-SETUP-1 with no materials listed.')
                ->exists()
        );
    }

    public function test_material_rows_sent_to_setup_are_ignored(): void
    {
        $this->plan([['material_id' => $this->aircon->material_id, 'quantity' => '2']]);

        // What a page left open from before the step was removed would send:
        // a replacement row, and one the plan's own rules would refuse.
        $stray = [
            'materials_submitted' => '1',
            'materials' => [
                ['material_id' => $this->copper->material_id, 'quantity' => '9'],
                ['material_id' => $this->aircon->material_id, 'quantity' => '1.5'],
            ],
        ];

        foreach (['save', 'finalize'] as $action) {
            $this->submit($action, $stray)->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertTrue($this->project->fresh()->phasesAreFinalized());
        $this->assertEquals(2, $this->line($this->aircon)->quantity_needed);
        $this->assertNull($this->line($this->copper));
    }

    public function test_the_plan_is_read_only_once_the_project_has_been_set_up(): void
    {
        $this->plan([['material_id' => $this->aircon->material_id, 'quantity' => '2']]);
        $this->submit('finalize');

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.override', $this->project->project_id))
            ->assertRedirect();

        $phases = $this->project->phases()->get()->map(fn (ProjectPhase $phase): array => [
            'phase_id' => $phase->phase_id,
            'title' => $phase->title,
            'description' => $phase->description,
        ])->all();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.save', $this->project->project_id), [
                'phases' => $phases,
                'materials_submitted' => '1',
                'materials' => [['material_id' => $this->copper->material_id, 'quantity' => '9']],
            ])
            ->assertRedirect();

        $this->assertNotNull($this->line($this->aircon));
        $this->assertNull($this->line($this->copper));

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.phases.setup', $this->project->project_id))
            ->assertOk()
            ->assertDontSee('Materials Needed')
            ->assertDontSee('materials_submitted', false);
    }

    // ------------------------------------------------------------------
    // The Materials tab before setup
    // ------------------------------------------------------------------

    public function test_the_materials_tab_shows_the_plan_before_setup(): void
    {
        $this->plan([['material_id' => $this->aircon->material_id, 'quantity' => '2']]);

        foreach ([
            [$this->superAdmin, 'super-admin.projects.show'],
            [$this->leadAccount, 'technician.projects.show'],
        ] as [$account, $route]) {
            $this->actingAs($account)
                ->get(route($route, $this->project->project_id))
                ->assertOk()
                ->assertSee('Split-Type Inverter 2.0 HP')
                ->assertDontSee('Set up the project first.')
                ->assertDontSee('data-bs-target="#addMaterialModal"', false);
        }
    }

    public function test_an_empty_materials_tab_asks_for_setup_first(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Set up the project first.');
    }

    public function test_recording_opens_on_the_materials_tab_once_setup_is_finished(): void
    {
        $this->plan([['material_id' => $this->aircon->material_id, 'quantity' => '2']]);
        $this->submit('finalize');

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Split-Type Inverter 2.0 HP')
            ->assertSee('data-bs-target="#addMaterialModal"', false);
    }

    // ------------------------------------------------------------------
    // The picker
    // ------------------------------------------------------------------

    public function test_the_picker_finds_active_materials_by_name_and_brand(): void
    {
        $this->submit('finalize');

        Material::factory()->inactive()->create(['name' => 'Split-Type Retired Model']);

        $materials = $this->actingAs($this->leadAccount)
            ->getJson(route('technician.projects.materials.suggest', [$this->project->project_id, 'q' => 'carrier split']))
            ->assertOk()
            ->json('materials');

        $this->assertSame(['Split-Type Inverter 2.0 HP (Carrier)'], array_column($materials, 'label'));
    }

    public function test_the_picker_offers_what_projects_of_the_same_type_used_first(): void
    {
        $this->submit('finalize');

        $projectType = ProjectType::create(['type_name' => 'Aircon Installation']);
        $this->project->projectTypes()->sync([$projectType->type_id]);

        $earlier = $this->project('REF-EARLIER');
        $earlier->projectTypes()->sync([$projectType->type_id]);

        ProjectMaterial::create([
            'project_id' => $earlier->project_id,
            'material_id' => $this->copper->material_id,
            'quantity_needed' => 5,
        ]);

        $materials = $this->actingAs($this->superAdmin)
            ->getJson(route('super-admin.projects.materials.suggest', $this->project->project_id))
            ->assertOk()
            ->json('materials');

        $this->assertSame($this->copper->material_id, $materials[0]['material_id']);
        $this->assertSame(1, $materials[0]['used_on']);
    }

    public function test_the_office_can_add_a_missing_material_to_the_list(): void
    {
        $this->submit('finalize');

        $this->actingAs($this->superAdmin)
            ->postJson(route('super-admin.projects.materials.quick-add', $this->project->project_id), [
                'name' => 'Ceiling Cassette 3.0 HP',
                'brand_id' => Brand::factory()->create(['brand_name' => 'Daikin'])->brand_id,
                'material_type_id' => $this->type->material_type_id,
                'unit' => 'unit',
            ])
            ->assertOk()
            ->assertJsonPath('material.label', 'Ceiling Cassette 3.0 HP (Daikin)');

        $material = Material::query()->where('name', 'Ceiling Cassette 3.0 HP')->firstOrFail();

        $this->assertSame($this->project->project_id, $material->created_from_project_id);
        $this->assertSame($this->superAdmin->id, $material->created_by);
    }

    public function test_the_same_material_cannot_be_listed_twice(): void
    {
        $this->submit('finalize');

        $this->actingAs($this->superAdmin)
            ->postJson(route('super-admin.projects.materials.quick-add', $this->project->project_id), [
                'name' => 'split-type inverter 2.0 hp',
                'brand_id' => $this->aircon->brand_id,
                'material_type_id' => $this->type->material_type_id,
                'unit' => 'unit',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', "'Split-Type Inverter 2.0 HP (Carrier)' is already listed.");
    }

    public function test_a_plain_technician_cannot_use_the_picker(): void
    {
        $this->submit('finalize');

        $this->actingAs($this->mateAccount)
            ->postJson(route('super-admin.projects.materials.quick-add', $this->project->project_id), [
                'name' => 'Anything',
                'material_type_id' => $this->type->material_type_id,
                'unit' => 'pc',
            ]);

        $this->actingAs($this->mateAccount)
            ->getJson(route('technician.projects.materials.suggest', [$this->project->project_id, 'q' => 'split']))
            ->assertForbidden();

        $this->assertFalse(Material::query()->where('name', 'Anything')->exists());
    }

    // ------------------------------------------------------------------
    // Set Up Now
    // ------------------------------------------------------------------

    public function test_a_project_never_set_up_offers_only_set_up_now(): void
    {
        $this->actingAs($this->admin);

        $this->getTablePage(route('super-admin.projects.table'))
            ->assertSee('Set Up Now')
            ->assertSee(route('super-admin.projects.phases.setup', $this->project->project_id))
            ->assertDontSee("window.location='".route('super-admin.projects.show', $this->project->project_id)."'", false);
    }

    public function test_a_set_up_project_gets_its_ordinary_actions_back(): void
    {
        $this->submit('finalize');

        $this->actingAs($this->admin);

        $this->getTablePage(route('super-admin.projects.table'))
            ->assertDontSee('Set Up Now')
            ->assertSee("window.location='".route('super-admin.projects.show', $this->project->project_id)."'", false);
    }

    public function test_an_unlocked_project_is_not_treated_as_new(): void
    {
        $this->submit('finalize');

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.override', $this->project->project_id));

        $this->assertTrue($this->project->fresh()->needsPhaseSetup());
        $this->assertFalse($this->project->fresh()->awaitingInitialSetup());

        $this->getTablePage(route('super-admin.projects.table'))
            ->assertDontSee('Set Up Now');
    }

    public function test_the_lead_is_offered_set_up_now_and_the_crew_is_not(): void
    {
        $this->actingAs($this->leadAccount);

        $this->getTablePage(route('technician.projects.table'))
            ->assertSee('Set Up Now')
            ->assertDontSee('href="'.route('technician.projects.show', $this->project->project_id).'"', false);

        $this->actingAs($this->mateAccount);

        $this->getTablePage(route('technician.projects.table'))
            ->assertDontSee('Set Up Now')
            ->assertSee('href="'.route('technician.projects.show', $this->project->project_id).'"', false);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * Post the setup form the way the screen does: the phases, and nothing
     * about materials unless a test adds it.
     *
     * @param  array<string, mixed>  $extra
     */
    private function submit(string $action, array $extra = [])
    {
        return $this->actingAs($this->superAdmin)
            ->from(route('super-admin.projects.phases.setup', $this->project->project_id))
            ->post(route('super-admin.projects.phases.'.$action, $this->project->project_id), [
                'phases' => $this->structure(),
            ] + $extra);
    }

    /**
     * The plan the create wizard leaves on a project it has just saved.
     *
     * @param  array<int, array{material_id: int, quantity: string, notes?: string}>  $rows
     */
    private function plan(array $rows): void
    {
        app(ProjectMaterialPlan::class)->save($this->project, $rows, $this->superAdmin);
    }

    /**
     * @return array<int, array{title: string, description: string}>
     */
    private function structure(): array
    {
        return array_slice(ProjectPhase::SUGGESTED_PHASES, 0, 2);
    }

    private function line(Material $material): ?ProjectMaterial
    {
        return ProjectMaterial::query()
            ->where('project_id', $this->project->project_id)
            ->where('material_id', $material->material_id)
            ->first();
    }

    private function project(string $reference): Project
    {
        return Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Aircon Installation',
            'reference_no' => $reference,
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
    }

    private function technician(User $account): Technician
    {
        return Technician::create([
            'account_id' => $account->id,
            'role' => $account->role,
        ]);
    }

    private function assign(Project $project, Technician $technician): void
    {
        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    private function account(string $role, string $email): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test Person '.$sequence,
            'first_name' => 'Test',
            'last_name' => 'Person '.$sequence,
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }
}
