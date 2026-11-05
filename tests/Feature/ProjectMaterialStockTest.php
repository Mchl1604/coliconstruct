<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectMaterialEntry;
use App\Models\ProjectType;
use App\Models\Skill;
use App\Models\StockMovement;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectMaterialLedger;
use App\Services\StockAvailability;
use App\Support\BusinessTime;
use App\Support\MaterialUnit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 3: a project's materials and the warehouse.
 *
 * The create wizard's plan reserves stock, a release by Purchasing ends the
 * reservation, and what the project's Materials tab records on site moves
 * the warehouse with it.
 */
class ProjectMaterialStockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Material $aircon;

    private Material $copper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->actingAsSuperAdmin();

        $this->aircon = Material::factory()->brand('Daikin')->create([
            'name' => 'Split-Type Inverter 1.5 HP',
            'unit' => MaterialUnit::Unit,
        ]);

        $this->copper = Material::factory()->measured()->create(['name' => 'Copper Tube 1/4']);
    }

    // ------------------------------------------------------------------
    // The create wizard
    // ------------------------------------------------------------------

    public function test_the_wizard_saves_the_plan_and_it_reserves_stock(): void
    {
        $this->stockIn($this->aircon, 10);

        $this->post(route('super-admin.projects.create.store'), $this->wizardPayload([
            ['material_id' => $this->aircon->material_id, 'quantity' => 4],
        ]))->assertRedirect(route('super-admin.projects'));

        $project = Project::query()->firstOrFail();

        $this->assertSame(4.0, (float) $project->materials()->value('quantity_needed'));

        $this->assertSame(
            ['on_hand' => 10.0, 'reserved' => 4.0, 'available' => 6.0],
            app(StockAvailability::class)->forMaterial($this->aircon)
        );

        // Nothing to ask for: the warehouse covers it.
        $this->assertSame(0, MaterialRequest::count());
    }

    public function test_a_line_over_the_limit_falls_to_what_is_available_and_asks_for_nothing(): void
    {
        $buyer = $this->account(User::ROLE_PURCHASING_ADMIN);

        $this->stockIn($this->aircon, 5);

        $this->post(route('super-admin.projects.create.store'), $this->wizardPayload([
            ['material_id' => $this->aircon->material_id, 'quantity' => 8],
        ]))
            ->assertRedirect(route('super-admin.projects'))
            ->assertSessionHas('success', 'Project created. Materials were limited to what is available.');

        $this->assertSame(5.0, (float) Project::query()->firstOrFail()->materials()->value('quantity_needed'));
        $this->assertSame(0, MaterialRequest::count());
        $this->assertSame(0, Notification::query()->where('user_id', $buyer->id)->where('title', 'Material Requested')->count());
    }

    public function test_the_limit_is_what_is_available_not_what_is_on_hand(): void
    {
        $this->stockIn($this->aircon, 5);
        $this->plan($this->project('REF-RES-LIMIT'), $this->aircon, 3);

        $this->post(route('super-admin.projects.create.store'), $this->wizardPayload([
            ['material_id' => $this->aircon->material_id, 'quantity' => 5],
        ]))->assertRedirect(route('super-admin.projects'));

        $this->assertSame(2.0, (float) Project::query()->latest('project_id')->firstOrFail()->materials()->value('quantity_needed'));
    }

    public function test_the_checkbox_requests_the_remaining_and_purchasing_is_told(): void
    {
        $buyer = $this->account(User::ROLE_PURCHASING_ADMIN);

        $this->stockIn($this->aircon, 5);

        $this->post(route('super-admin.projects.create.store'), $this->wizardPayload([
            ['material_id' => $this->aircon->material_id, 'quantity' => 5, 'request_remaining' => 3],
        ]))
            ->assertRedirect(route('super-admin.projects'))
            ->assertSessionHas('success', 'Project created successfully.');

        $project = Project::query()->firstOrFail();
        $materialRequest = MaterialRequest::query()->firstOrFail();

        $this->assertSame(5.0, (float) $project->materials()->value('quantity_needed'));
        $this->assertSame(3.0, (float) $materialRequest->quantity);
        $this->assertSame(MaterialRequest::STATUS_PENDING, $materialRequest->status);
        $this->assertSame($project->project_id, $materialRequest->project_id);
        $this->assertSame(1, Notification::query()->where('user_id', $buyer->id)->where('title', 'Material Requested')->count());
    }

    public function test_stock_taken_while_the_form_was_open_adds_to_what_is_requested(): void
    {
        $this->stockIn($this->aircon, 2);

        $this->post(route('super-admin.projects.create.store'), $this->wizardPayload([
            ['material_id' => $this->aircon->material_id, 'quantity' => 5, 'request_remaining' => 3],
        ]))->assertRedirect(route('super-admin.projects'));

        $this->assertSame(2.0, (float) Project::query()->firstOrFail()->materials()->value('quantity_needed'));
        $this->assertSame(6.0, (float) MaterialRequest::query()->value('quantity'));
    }

    public function test_a_material_with_none_available_can_only_be_requested(): void
    {
        $this->post(route('super-admin.projects.create.store'), $this->wizardPayload([
            ['material_id' => $this->aircon->material_id, 'quantity' => 0, 'request_remaining' => 4],
        ]))->assertRedirect(route('super-admin.projects'));

        $this->assertSame(0, ProjectMaterial::count());
        $this->assertSame(4.0, (float) MaterialRequest::query()->value('quantity'));
    }

    public function test_a_requested_amount_its_unit_refuses_sends_the_wizard_back(): void
    {
        $this->stockIn($this->aircon, 5);

        $this->post(route('super-admin.projects.create.store'), $this->wizardPayload([
            ['material_id' => $this->aircon->material_id, 'quantity' => 5, 'request_remaining' => 1.5],
        ]))->assertSessionHasErrors('materials.0.request_remaining');

        $this->assertSame(0, Project::count());
    }

    public function test_available_stock_never_goes_below_zero(): void
    {
        $this->plan($this->project('REF-RES-4'), $this->aircon, 3);
        $this->stockIn($this->aircon, 1);

        $this->assertSame(
            ['on_hand' => 1.0, 'reserved' => 3.0, 'available' => 0.0],
            app(StockAvailability::class)->forMaterial($this->aircon)
        );

        $this->getTablePage(route('inventory.stock.index'), ['search' => 'split'])
            ->assertSee('text-danger fw-semibold">0</span>', false);
    }

    public function test_a_wizard_line_its_unit_refuses_sends_the_wizard_back(): void
    {
        $this->post(route('super-admin.projects.create.store'), $this->wizardPayload([
            ['material_id' => $this->aircon->material_id, 'quantity' => 1.5],
        ]))->assertSessionHasErrors('materials.0.quantity');

        $this->assertSame(0, Project::count());
    }

    public function test_bad_wizard_lines_are_refused_with_nothing_saved(): void
    {
        $inactive = Material::factory()->inactive()->create();
        $payload = $this->wizardPayload([]);

        $cases = [
            'materials.0.quantity' => [['material_id' => $this->copper->material_id, 'quantity' => 0]],
            'materials.0.material_id' => [['material_id' => $inactive->material_id, 'quantity' => 1]],
            'materials.1.material_id' => [
                ['material_id' => $this->copper->material_id, 'quantity' => 1],
                ['material_id' => $this->copper->material_id, 'quantity' => 2],
            ],
        ];

        foreach ($cases as $field => $rows) {
            $this->post(route('super-admin.projects.create.store'), ['materials' => $rows] + $payload)
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Project::count());
        $this->assertSame(0, ProjectMaterial::count());
    }

    public function test_the_wizard_search_answers_with_stock_figures(): void
    {
        $this->stockIn($this->copper, 25);

        $this->getJson(route('super-admin.projects.material-options', ['q' => 'copper']))
            ->assertOk()
            ->assertJsonPath('materials.0.name', 'Copper Tube 1/4')
            ->assertJsonPath('materials.0.on_hand', 25)
            ->assertJsonPath('materials.0.available', 25);
    }

    public function test_the_wizard_has_a_materials_step(): void
    {
        $this->get(route('super-admin.projects.create'))
            ->assertOk()
            ->assertSee('data-wizard-materials', false)
            ->assertSee('Step <span data-step-counter>1</span> of 6', false)
            ->assertSee('projectWizardMaterials.js', false);
    }

    // ------------------------------------------------------------------
    // Reservations
    // ------------------------------------------------------------------

    public function test_a_release_by_purchasing_ends_the_reservation(): void
    {
        $project = $this->project('REF-RES-1');
        $this->plan($project, $this->aircon, 4);
        $this->stockIn($this->aircon, 10);

        $this->assertSame(4.0, app(StockAvailability::class)->forMaterial($this->aircon)['reserved']);

        $this->postJson(route('inventory.stock.issue'), [
            'material_id' => $this->aircon->material_id,
            'quantity' => 4,
            'movement_date' => now()->toDateString(),
            'project_id' => $project->project_id,
        ])->assertOk();

        $this->assertSame(
            ['on_hand' => 6.0, 'reserved' => 0.0, 'available' => 6.0],
            app(StockAvailability::class)->forMaterial($this->aircon)
        );
    }

    public function test_a_closed_project_reserves_nothing(): void
    {
        $project = $this->project('REF-RES-2', 'completed');
        $this->plan($project, $this->aircon, 4);

        $this->assertSame(0.0, app(StockAvailability::class)->forMaterial($this->aircon)['reserved']);
    }

    public function test_the_stock_table_shows_reserved_and_available(): void
    {
        $this->plan($this->project('REF-RES-3'), $this->aircon, 3);
        $this->stockIn($this->aircon, 5);

        // On hand, reserved, available.
        $this->getTablePage(route('inventory.stock.index'), ['search' => 'split'])
            ->assertSeeInOrder(['fw-semibold ">5</span>', 'small ">3</td>', '">2</span>'], false);
    }

    // ------------------------------------------------------------------
    // The project's Materials tab moves the warehouse
    // ------------------------------------------------------------------

    public function test_added_from_the_warehouse_comes_off_the_shelf(): void
    {
        $project = $this->project('REF-TAB-1');
        $this->stockIn($this->copper, 10);

        $entry = app(ProjectMaterialLedger::class)->add($project, $this->copper, 2.5, today(), null, $this->admin);

        $movement = StockMovement::query()->where('project_material_entry_id', $entry->material_entry_id)->firstOrFail();

        $this->assertSame(-2.5, (float) $movement->quantity);
        $this->assertSame($project->project_id, $movement->project_id);
        $this->assertSame(7.5, $this->copper->fresh()->onHand());
    }

    public function test_bought_directly_touches_no_stock(): void
    {
        $project = $this->project('REF-TAB-2');

        app(ProjectMaterialLedger::class)->add($project, $this->copper, 2, today(), null, $this->admin, fromStock: false);

        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, ProjectMaterialEntry::count());
    }

    public function test_an_addition_the_warehouse_cannot_cover_is_refused_whole(): void
    {
        $project = $this->project('REF-TAB-3');
        $this->stockIn($this->copper, 1);

        try {
            app(ProjectMaterialLedger::class)->add($project, $this->copper, 3, today(), null, $this->admin);
            $this->fail('An addition past what is in stock was allowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Only 1 m available. Ask Purchasing.', $exception->getMessage());
        }

        $this->assertSame(0, ProjectMaterialEntry::count());
        $this->assertSame(1.0, $this->copper->fresh()->onHand());
    }

    public function test_an_addition_cannot_take_stock_another_project_reserved(): void
    {
        $project = $this->project('REF-TAB-7');
        $this->plan($this->project('REF-TAB-8'), $this->copper, 8);
        $this->stockIn($this->copper, 10);
        $this->finalizePhases($project);

        $this->post(route('super-admin.projects.materials.add', $project->project_id), [
            'material_id' => $this->copper->material_id,
            'quantity' => 3,
        ])->assertSessionHas('error', 'Only 2 m available. Ask Purchasing.');

        $this->assertSame(0, ProjectMaterialEntry::count());
        $this->assertSame(10.0, $this->copper->fresh()->onHand());
    }

    public function test_the_project_form_takes_from_stock_today_with_no_notes(): void
    {
        $project = $this->project('REF-TAB-4');
        $this->stockIn($this->copper, 5);
        $this->finalizePhases($project);

        $this->post(route('super-admin.projects.materials.add', $project->project_id), [
            'material_id' => $this->copper->material_id,
            'source' => 'direct',
            'quantity' => 2,
            'entry_date' => BusinessTime::today()->subDays(3)->toDateString(),
            'notes' => 'Ignored',
        ])->assertSessionHas('success');

        $entry = ProjectMaterialEntry::query()->firstOrFail();

        $this->assertSame(3.0, $this->copper->fresh()->onHand());
        $this->assertSame(BusinessTime::today()->toDateString(), $entry->entry_date->toDateString());
        $this->assertNull($entry->notes);
    }

    public function test_the_add_material_dialog_asks_only_for_material_and_quantity(): void
    {
        $project = $this->project('REF-TAB-9');
        $this->finalizePhases($project);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('data-add-material-available', false)
            ->assertDontSee('id="addMaterialDate"', false)
            ->assertDontSee('id="addMaterialSource"', false)
            ->assertDontSee('id="addMaterialNotes"', false);
    }

    public function test_the_project_picker_and_add_more_show_available_stock(): void
    {
        $project = $this->project('REF-TAB-10');
        $this->plan($project, $this->copper, 4);
        $this->stockIn($this->copper, 10);
        $this->finalizePhases($project);

        $this->getJson(route('super-admin.projects.materials.suggest', [$project->project_id, 'q' => 'copper']))
            ->assertOk()
            ->assertJsonPath('materials.0.available', 6);

        $this->assertSame(6.0, app(ProjectMaterialLedger::class)->summary($project->fresh())->first()['available']);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('"available":6', false);
    }

    public function test_a_return_goes_back_to_stock_and_voiding_it_takes_it_away(): void
    {
        $project = $this->project('REF-TAB-5');
        $this->stockIn($this->copper, 10);
        $ledger = app(ProjectMaterialLedger::class);

        $ledger->add($project, $this->copper, 4, today(), null, $this->admin);

        $line = ProjectMaterial::query()->where('project_id', $project->project_id)->firstOrFail();
        $return = $ledger->record($project, $line, ProjectMaterialEntry::TYPE_RETURNED, 1, today(), null, $this->admin);

        $this->assertSame(7.0, $this->copper->fresh()->onHand());

        $ledger->void($project, $return, $this->admin);

        $this->assertSame(6.0, $this->copper->fresh()->onHand());
        $this->assertNotNull(StockMovement::query()->where('project_material_entry_id', $return->material_entry_id)->value('voided_at'));
    }

    public function test_close_out_returns_only_what_the_warehouse_released(): void
    {
        $project = $this->project('REF-TAB-6');
        $this->plan($project, $this->aircon, 5);
        $this->stockIn($this->aircon, 10);

        // Purchasing released three of the five planned.
        $this->postJson(route('inventory.stock.issue'), [
            'material_id' => $this->aircon->material_id,
            'quantity' => 3,
            'movement_date' => now()->toDateString(),
            'project_id' => $project->project_id,
        ])->assertOk();

        app(ProjectMaterialLedger::class)->closeOut($project, 'Left over at completion.');

        // All five are "unused" on the project; only the three that left
        // the shelf come back to it.
        $this->assertSame(5.0, (float) ProjectMaterialEntry::query()->where('entry_type', ProjectMaterialEntry::TYPE_UNUSED)->value('quantity'));
        $this->assertSame(10.0, $this->aircon->fresh()->onHand());
        $this->assertSame('System', StockMovement::query()->where('movement_type', StockMovement::TYPE_RETURNED)->value('recorded_by_name'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function stockIn(Material $material, float|int $quantity): void
    {
        $this->postJson(route('inventory.stock.receive'), [
            'material_id' => $material->material_id,
            'movement_type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => $quantity,
            'movement_date' => now()->toDateString(),
        ])->assertOk();
    }

    private function plan(Project $project, Material $material, float|int $quantity): ProjectMaterial
    {
        return ProjectMaterial::create([
            'project_id' => $project->project_id,
            'material_id' => $material->material_id,
            'quantity_needed' => $quantity,
        ]);
    }

    private function project(string $reference, string $status = 'ongoing'): Project
    {
        return Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Aircon Installation',
            'reference_no' => $reference,
            'status' => $status,
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
    }

    private function account(string $role): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => sprintf('EMP-%04d', $sequence),
            'name' => 'Office '.$sequence,
            'first_name' => 'Office',
            'last_name' => (string) $sequence,
            'email' => 'office'.$sequence.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }

    /**
     * A valid create-wizard submission with these material lines.
     *
     * @param  array<int, array<string, mixed>>  $materials
     * @return array<string, mixed>
     */
    private function wizardPayload(array $materials): array
    {
        ProjectType::query()->firstOrCreate(['type_name' => 'Aircon Installation']);
        $skill = Skill::query()->firstOrCreate(['skill_name' => 'Aircon Installation']);

        $lead = $this->technician('lead_technician', 'Lead Tech');
        $crew = $this->technician('technician', 'Crew Tech');

        DB::table('tbl_skill_map')->insert(['technician_id' => $crew->technician_id, 'skill_id' => $skill->skill_id]);

        return [
            'client_type' => 'Residential',
            'surname' => 'Dela Cruz',
            'firstname' => 'Juan',
            'middle_name' => 'S',
            'client_email' => 'juan.dela.cruz@example.test',
            'client_phone' => '09123456789',
            ...$this->luzonAddress(),
            'quotation_items' => [['kind' => 'other', 'description' => 'Aircon works', 'quantity' => 1, 'unit_price' => '1250.00']],
            'project_types' => ['Aircon Installation'],
            'assessment_report' => [UploadedFile::fake()->create('assessment.pdf', 12, 'application/pdf')],
            'approved_quotation' => [UploadedFile::fake()->create('quotation.jpg', 12, 'image/jpeg')],
            'target_end_date' => CarbonImmutable::today()->addDays(30)->toDateString(),
            'lead_tech' => $lead->technician_id,
            'technicians' => [$crew->technician_id],
            'start_date' => CarbonImmutable::today()->addDays(10)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(12)->toDateString(),
            'materials' => $materials,
        ];
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
}
