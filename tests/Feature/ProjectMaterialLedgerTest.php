<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectMaterialEntry;
use App\Models\ProjectTechnician;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectCompletion;
use App\Services\ProjectMaterialLedger;
use App\Support\BusinessTime;
use App\Support\MaterialUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project's materials after setup: added, used, returned, and what is left
 * on site - recorded by the three roles that run a project, refused past what
 * is actually there, and closed out as unused when the project ends.
 */
class ProjectMaterialLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $admin;

    private User $leadAccount;

    private User $mateAccount;

    private Project $project;

    private Material $aircon;

    private Material $copper;

    private ProjectMaterial $airconLine;

    private ProjectMaterial $copperLine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->account('super_admin', 'owner@example.test');
        $this->admin = $this->account('admin', 'admin@example.test');
        $this->leadAccount = $this->account('lead_technician', 'lead@example.test');
        $this->mateAccount = $this->account('technician', 'mate@example.test');

        $this->project = $this->project('REF-MAT-1');

        $this->assign($this->project, $this->technician($this->leadAccount));
        $this->assign($this->project, $this->technician($this->mateAccount));

        $type = MaterialType::factory()->create(['type_name' => 'Aircon Units']);

        $this->aircon = Material::factory()->brand('Daikin')->create([
            'material_type_id' => $type->material_type_id,
            'name' => 'Split-Type Inverter 1.5 HP',
            'unit' => MaterialUnit::Unit,
        ]);

        $this->copper = Material::factory()->measured()->brand('Mueller')->create([
            'material_type_id' => $type->material_type_id,
            'name' => 'Copper Tube 1/2"',
        ]);

        $this->airconLine = $this->plan($this->aircon, 2);
        $this->copperLine = $this->plan($this->copper, 15);
    }

    // ------------------------------------------------------------------
    // The figures
    // ------------------------------------------------------------------

    public function test_use_and_return_move_what_is_on_site(): void
    {
        $this->record($this->superAdmin, $this->copperLine, 'used', '10.5')->assertRedirect();
        $this->record($this->superAdmin, $this->copperLine, 'returned', '3')->assertRedirect();

        $row = $this->row($this->copper);

        $this->assertEquals(15, $row['needed']);
        $this->assertEquals(10.5, $row['used']);
        $this->assertEquals(3, $row['returned']);
        $this->assertEquals(1.5, $row['on_site']);
    }

    public function test_more_cannot_be_used_or_returned_than_is_on_site(): void
    {
        $this->record($this->superAdmin, $this->airconLine, 'used', '3')
            ->assertSessionHas('error', 'Only 2 unit remaining. Add more first.');

        $this->assertSame(0, ProjectMaterialEntry::count());
    }

    public function test_adding_more_raises_what_may_be_used(): void
    {
        $this->addMaterial($this->superAdmin, $this->aircon, '1')->assertRedirect();
        $this->record($this->superAdmin, $this->airconLine, 'used', '3')->assertSessionHasNoErrors();

        $row = $this->row($this->aircon);

        $this->assertEquals(1, $row['added']);
        $this->assertEquals(0, $row['on_site']);
    }

    public function test_a_material_the_plan_did_not_have_gets_a_line_of_its_own(): void
    {
        $bracket = Material::factory()->create(['name' => 'Outdoor Bracket', 'unit' => MaterialUnit::Set]);

        $this->addMaterial($this->leadAccount, $bracket, '2', 'technician')->assertRedirect();

        $line = ProjectMaterial::query()->where('material_id', $bracket->material_id)->firstOrFail();

        $this->assertEquals(0, $line->quantity_needed);
        $this->assertEquals(2, $this->row($bracket)['on_site']);
    }

    public function test_counted_units_take_whole_numbers_only(): void
    {
        $this->record($this->superAdmin, $this->airconLine, 'used', '1.5')
            ->assertSessionHas('error', 'Whole numbers only for unit.');

        $this->record($this->superAdmin, $this->copperLine, 'used', '1.25')
            ->assertSessionHasNoErrors();
    }

    public function test_a_future_date_is_refused(): void
    {
        $this->record($this->superAdmin, $this->copperLine, 'used', '1', BusinessTime::today()->addDay()->toDateString())
            ->assertSessionHas('error', 'The date cannot be in the future.');

        $this->assertSame(0, ProjectMaterialEntry::count());
    }

    // ------------------------------------------------------------------
    // Voiding
    // ------------------------------------------------------------------

    public function test_a_voided_entry_stops_counting_but_stays_on_the_history(): void
    {
        $this->record($this->leadAccount, $this->copperLine, 'used', '5', portal: 'technician');
        $entry = ProjectMaterialEntry::query()->firstOrFail();

        $this->actingAs($this->leadAccount)
            ->post(route('technician.projects.materials.void', [$this->project->project_id, $entry->material_entry_id]))
            ->assertRedirect();

        $this->assertNotNull($entry->fresh()->voided_at);
        $this->assertEquals(15, $this->row($this->copper)['on_site']);

        $this->actingAs($this->superAdmin);

        $this->getTablePage(route('super-admin.projects.materials.table', $this->project->project_id))
            ->assertSee('Voided');
    }

    public function test_a_lead_voids_only_what_they_recorded(): void
    {
        $this->record($this->admin, $this->copperLine, 'used', '5');
        $entry = ProjectMaterialEntry::query()->firstOrFail();

        $this->actingAs($this->leadAccount)
            ->post(route('technician.projects.materials.void', [$this->project->project_id, $entry->material_entry_id]))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertNull($entry->fresh()->voided_at);
    }

    public function test_an_addition_already_used_cannot_be_voided_first(): void
    {
        $this->addMaterial($this->superAdmin, $this->aircon, '1');
        $added = ProjectMaterialEntry::query()->firstOrFail();
        $this->record($this->superAdmin, $this->airconLine, 'used', '3');

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.materials.void', [$this->project->project_id, $added->material_entry_id]))
            ->assertSessionHas('error', 'Already used. Void later entries first.');

        $this->assertNull($added->fresh()->voided_at);
    }

    // ------------------------------------------------------------------
    // Who may record
    // ------------------------------------------------------------------

    public function test_a_lead_not_on_the_project_is_refused(): void
    {
        $stranger = $this->account('lead_technician', 'stranger@example.test');
        $this->technician($stranger);

        $this->record($stranger, $this->copperLine, 'used', '1', portal: 'technician')->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, ProjectMaterialEntry::count());
    }

    public function test_a_plain_technician_reads_but_does_not_record(): void
    {
        $this->actingAs($this->mateAccount)
            ->get(route('technician.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Material History')
            ->assertDontSee('Record Use');

        $this->getTablePage(route('technician.projects.materials.table', $this->project->project_id))
            ->assertOk();

        $this->record($this->mateAccount, $this->copperLine, 'used', '1', portal: 'technician');

        $this->assertSame(0, ProjectMaterialEntry::count());
    }

    public function test_the_lead_and_the_office_are_offered_recording(): void
    {
        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Record Use')
            ->assertSee('Split-Type Inverter 1.5 HP');

        $this->actingAs($this->admin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Record Return')
            ->assertSee('Add Material');
    }

    public function test_the_table_shows_remaining_and_no_needed_column(): void
    {
        $this->actingAs($this->admin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('<th class="text-end">Remaining</th>', false)
            ->assertDontSee('<th class="text-end">Needed</th>', false)
            ->assertDontSee('<th class="text-end">On Site</th>', false);
    }

    public function test_a_project_waiting_on_its_client_takes_no_entries(): void
    {
        $this->project->forceFill(['status' => Project::STATUS_AWAITING_CLIENT_CONFIRMATION])->save();

        $this->record($this->superAdmin, $this->copperLine, 'used', '1')->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, ProjectMaterialEntry::count());
    }

    public function test_a_project_on_hold_still_takes_returns(): void
    {
        $this->project->forceFill(['status' => 'unscheduled', 'on_hold' => true])->save();

        $this->record($this->admin, $this->copperLine, 'returned', '15')->assertSessionHasNoErrors();

        $this->assertEquals(0, $this->row($this->copper)['on_site']);
    }

    // ------------------------------------------------------------------
    // Closing out
    // ------------------------------------------------------------------

    public function test_completion_records_what_is_left_on_site_as_unused(): void
    {
        $this->record($this->superAdmin, $this->copperLine, 'used', '12');

        app(ProjectCompletion::class)->confirm($this->project->fresh(), null, Project::METHOD_AUTO_COMPLETED);

        $copper = $this->row($this->copper);
        $aircon = $this->row($this->aircon);

        $this->assertEquals(3, $copper['unused']);
        $this->assertEquals(0, $copper['on_site']);
        $this->assertEquals(2, $aircon['unused']);
        $this->assertSame('System', ProjectMaterialEntry::query()->where('entry_type', 'unused')->first()->recorderName());
        $this->assertTrue(ActivityLog::query()->where('action', ActivityLog::PROJECT_MATERIALS_CLOSED_OUT)->exists());

        // Nothing is left to record a second time.
        $this->assertSame(0, app(ProjectMaterialLedger::class)->closeOut($this->project, 'Again.'));
    }

    public function test_requesting_completion_leaves_the_materials_on_site(): void
    {
        $this->project->forceFill(['status' => Project::STATUS_AWAITING_CLIENT_CONFIRMATION])->save();

        $this->assertSame(0, ProjectMaterialEntry::query()->where('entry_type', 'unused')->count());
        $this->assertEquals(15, $this->row($this->copper)['on_site']);
    }

    public function test_cancelling_records_what_is_left_on_site_as_unused(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.cancel', $this->project->project_id), [
                'cancellation_reason' => 'Client withdrew',
            ])
            ->assertRedirect();

        $this->assertSame('cancelled', $this->project->fresh()->status);
        $this->assertEquals(15, $this->row($this->copper)['unused']);
    }

    public function test_the_completed_project_shows_an_unused_column(): void
    {
        app(ProjectCompletion::class)->confirm($this->project->fresh(), null, Project::METHOD_AUTO_COMPLETED);

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Unused')
            ->assertDontSee('Record Use');
    }

    public function test_every_entry_is_on_the_project_activity_log(): void
    {
        $this->record($this->superAdmin, $this->copperLine, 'used', '2.5');

        $log = ActivityLog::query()->where('action', ActivityLog::PROJECT_MATERIAL_USED)->firstOrFail();

        $this->assertSame(ActivityLog::MODULE_MATERIALS, $log->module);
        $this->assertSame(ActivityLog::RECORD_PROJECT, $log->record_type);
        $this->assertStringContainsString('2.5 m of Copper Tube 1/2" (Mueller)', $log->description);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function row(Material $material): array
    {
        return app(ProjectMaterialLedger::class)
            ->summary($this->project->fresh())
            ->first(fn (array $row): bool => (int) $row['material']->material_id === (int) $material->material_id);
    }

    private function record(User $user, ProjectMaterial $line, string $type, string $quantity, ?string $date = null, string $portal = 'super-admin')
    {
        return $this->actingAs($user)
            ->from(route($portal.'.projects.show', $this->project->project_id))
            ->post(route($portal.'.projects.materials.record', $this->project->project_id), [
                'project_material_id' => $line->project_material_id,
                'entry_type' => $type,
                'quantity' => $quantity,
                'entry_date' => $date ?? BusinessTime::today()->toDateString(),
            ]);
    }

    private function addMaterial(User $user, Material $material, string $quantity, string $portal = 'super-admin')
    {
        return $this->actingAs($user)
            ->from(route($portal.'.projects.show', $this->project->project_id))
            ->post(route($portal.'.projects.materials.add', $this->project->project_id), [
                'material_id' => $material->material_id,
                'quantity' => $quantity,
                'entry_date' => BusinessTime::today()->toDateString(),
            ]);
    }

    private function plan(Material $material, float $quantity): ProjectMaterial
    {
        return ProjectMaterial::create([
            'project_id' => $this->project->project_id,
            'material_id' => $material->material_id,
            'quantity_needed' => $quantity,
        ]);
    }

    private function project(string $reference): Project
    {
        $project = Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Aircon Installation',
            'reference_no' => $reference,
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        $this->finalizePhases($project);

        return $project->fresh();
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
