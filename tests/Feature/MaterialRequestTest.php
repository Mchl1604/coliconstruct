<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectMaterialEntry;
use App\Models\ProjectTechnician;
use App\Models\StockMovement;
use App\Models\Technician;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\BusinessTime;
use App\Support\MaterialUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A lead asks for material rather than adding it, and the inventory side
 * decides. Approving takes it off the shelf; nothing moves before that.
 */
class MaterialRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $buyer;

    private Project $project;

    private Material $copper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lead = $this->account(User::ROLE_LEAD_TECHNICIAN);
        $this->buyer = $this->account(User::ROLE_PURCHASING_ADMIN);

        $this->project = Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Aircon Installation',
            'reference_no' => 'REF-REQ-1',
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
        $this->finalizePhases($this->project);

        ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => Technician::create(['account_id' => $this->lead->id, 'role' => $this->lead->role])->technician_id,
        ]);

        $this->copper = Material::factory()->measured()->create(['name' => 'Copper Tube 1/4']);
    }

    public function test_a_lead_requests_and_nothing_moves_yet(): void
    {
        $this->stock(10);

        $this->requestMaterial(4)->assertSessionHas('success', 'Material requested.');

        $materialRequest = MaterialRequest::query()->firstOrFail();

        $this->assertSame(MaterialRequest::STATUS_PENDING, $materialRequest->status);
        $this->assertSame($this->lead->id, $materialRequest->requested_by);
        $this->assertSame(0, ProjectMaterialEntry::count());
        $this->assertSame(10.0, $this->copper->fresh()->onHand());
        $this->assertTrue(Notification::query()->where('title', 'Material Requested')->where('user_id', $this->buyer->id)->exists());
    }

    public function test_a_lead_may_request_more_than_is_available(): void
    {
        $this->requestMaterial(4)->assertSessionHas('success');

        $this->assertSame(1, MaterialRequest::count());
    }

    public function test_a_quantity_the_unit_refuses_is_not_requested(): void
    {
        $aircon = Material::factory()->create(['unit' => MaterialUnit::Unit]);

        $this->actingAs($this->lead)
            ->post(route('technician.projects.materials.requests.store', $this->project->project_id), [
                'material_id' => $aircon->material_id,
                'quantity' => 1.5,
            ])->assertSessionHas('error');

        $this->assertSame(0, MaterialRequest::count());
    }

    public function test_a_lead_not_on_the_project_cannot_request(): void
    {
        $stranger = $this->account(User::ROLE_LEAD_TECHNICIAN);
        Technician::create(['account_id' => $stranger->id, 'role' => $stranger->role]);

        $this->actingAs($stranger)
            ->post(route('technician.projects.materials.requests.store', $this->project->project_id), [
                'material_id' => $this->copper->material_id,
                'quantity' => 1,
            ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, MaterialRequest::count());
    }

    public function test_the_lead_sees_request_material_and_cannot_add(): void
    {
        $this->actingAs($this->lead)
            ->get(route('technician.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Request Material')
            ->assertDontSee('Add to Material List')
            ->assertDontSee('>Add Material<', false);

        $this->assertFalse(app('router')->has('technician.projects.materials.add'));
        $this->assertFalse(app('router')->has('technician.projects.materials.quick-add'));
    }

    public function test_approving_adds_it_to_the_project_off_the_shelf(): void
    {
        $this->stock(10);
        $this->requestMaterial(4);
        $materialRequest = MaterialRequest::query()->firstOrFail();

        $this->actingAs($this->buyer)
            ->postJson(route('inventory.material-requests.approve', $materialRequest))
            ->assertOk()
            ->assertJsonPath('message', 'Request approved.');

        $materialRequest->refresh();
        $entry = ProjectMaterialEntry::query()->firstOrFail();

        $this->assertSame(MaterialRequest::STATUS_APPROVED, $materialRequest->status);
        $this->assertSame($this->buyer->id, $materialRequest->decided_by);
        $this->assertSame($entry->material_entry_id, $materialRequest->project_material_entry_id);
        $this->assertSame(6.0, $this->copper->fresh()->onHand());
        $this->assertSame(1, StockMovement::query()->where('project_material_entry_id', $entry->material_entry_id)->count());
        $this->assertTrue(Notification::query()->where('title', 'Material Request Approved')->where('user_id', $this->lead->id)->exists());
    }

    public function test_approving_past_what_is_available_leaves_it_pending(): void
    {
        $this->stock(1);
        $this->requestMaterial(4);
        $materialRequest = MaterialRequest::query()->firstOrFail();

        $this->actingAs($this->buyer)
            ->postJson(route('inventory.material-requests.approve', $materialRequest))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Only 1 m available. Ask Purchasing.');

        $this->assertTrue($materialRequest->fresh()->isPending());
        $this->assertSame(0, ProjectMaterialEntry::count());
        $this->assertSame(1.0, $this->copper->fresh()->onHand());
    }

    public function test_a_request_is_decided_once(): void
    {
        $this->stock(10);
        $this->requestMaterial(4);
        $materialRequest = MaterialRequest::query()->firstOrFail();

        $this->actingAs($this->buyer);
        $this->postJson(route('inventory.material-requests.approve', $materialRequest))->assertOk();

        $this->postJson(route('inventory.material-requests.approve', $materialRequest))
            ->assertStatus(422)->assertJsonPath('error', 'Already decided.');
        $this->postJson(route('inventory.material-requests.reject', $materialRequest), ['reason' => 'No'])
            ->assertStatus(422);

        $this->assertSame(6.0, $this->copper->fresh()->onHand());
    }

    public function test_rejecting_needs_a_reason_and_tells_the_lead(): void
    {
        $this->requestMaterial(4);
        $materialRequest = MaterialRequest::query()->firstOrFail();

        $this->actingAs($this->buyer);

        $this->postJson(route('inventory.material-requests.reject', $materialRequest), ['reason' => ''])
            ->assertStatus(422)->assertJsonPath('error', 'Say why.');

        $this->postJson(route('inventory.material-requests.reject', $materialRequest), ['reason' => 'Use what is on site.'])
            ->assertOk();

        $this->assertSame('Use what is on site.', $materialRequest->fresh()->rejection_reason);
        $this->assertSame(0, ProjectMaterialEntry::count());

        $notice = Notification::query()->where('title', 'Material Request Rejected')->where('user_id', $this->lead->id)->firstOrFail();
        $this->assertStringContainsString('Use what is on site.', $notice->message);

        $this->actingAs($this->lead)
            ->get(route('technician.projects.show', $this->project->project_id))
            ->assertSee('Use what is on site.')
            ->assertSee('Rejected');
    }

    public function test_only_the_inventory_side_decides(): void
    {
        $this->requestMaterial(4);
        $materialRequest = MaterialRequest::query()->firstOrFail();

        foreach ([$this->lead, $this->account(User::ROLE_ADMIN)] as $account) {
            $this->actingAs($account)
                ->postJson(route('inventory.material-requests.approve', $materialRequest))
                ->assertForbidden();
        }

        $this->assertTrue($materialRequest->fresh()->isPending());
    }

    public function test_the_requests_page_lists_what_is_waiting(): void
    {
        $this->requestMaterial(4);

        $this->actingAs($this->buyer)
            ->get(route('inventory.material-requests.index'))
            ->assertOk()
            ->assertSee('id="materialRequestsTable"', false);

        $this->getTablePage(route('inventory.material-requests.table'), ['status' => MaterialRequest::STATUS_PENDING])
            ->assertSee('Copper Tube 1/4')
            ->assertSee('REF-REQ-1')
            ->assertSee('data-request-approve', false);

        $this->getTablePage(route('inventory.material-requests.table'), ['status' => MaterialRequest::STATUS_REJECTED])
            ->assertDontSee('Copper Tube 1/4');
    }

    public function test_a_pending_request_shows_what_is_available_now(): void
    {
        $this->stock(10);
        $this->requestMaterial(4);

        $this->actingAs($this->buyer)
            ->get(route('inventory.material-requests.index'))
            ->assertSee('>Available</th>', false);

        $this->getTablePage(route('inventory.material-requests.table'))
            ->assertSee('text-success">10 m</span>', false)
            ->assertDontSee('Not enough');
    }

    public function test_a_pending_request_over_what_is_available_says_so(): void
    {
        $this->stock(1);
        $this->requestMaterial(4);

        $this->actingAs($this->buyer);

        $this->getTablePage(route('inventory.material-requests.table'))
            ->assertSee('text-danger">1 m</span>', false)
            ->assertSee('Not enough');
    }

    public function test_a_decided_request_shows_no_available_figure(): void
    {
        $this->stock(10);
        $this->requestMaterial(4);

        $this->actingAs($this->buyer)
            ->postJson(route('inventory.material-requests.approve', MaterialRequest::query()->firstOrFail()))
            ->assertOk();

        $this->getTablePage(route('inventory.material-requests.table'))
            ->assertDontSee('text-success">', false)
            ->assertDontSee('Not enough');
    }

    public function test_the_table_still_sorts_by_status(): void
    {
        $this->requestMaterial(4);

        $this->actingAs($this->buyer);

        $this->getTablePage(route('inventory.material-requests.table'), ['order' => [['column' => 6, 'dir' => 'asc']]])
            ->assertSee('Copper Tube 1/4');
    }

    public function test_a_lead_return_waits_for_approval_then_goes_back_on_the_shelf(): void
    {
        $line = $this->onSite(4);

        $this->actingAs($this->lead)
            ->get(route('technician.projects.show', $this->project->project_id))
            ->assertSee('Return Material')
            ->assertSee('Inventory approves returns first.');

        $this->returnMaterial($line, 3)->assertSessionHas('success', 'Return sent for approval.');

        $return = MaterialRequest::query()->where('request_type', MaterialRequest::TYPE_RETURN)->sole();

        $this->assertTrue($return->isPending());
        $this->assertSame(1, ProjectMaterialEntry::count());
        $this->assertSame(6.0, $this->copper->fresh()->onHand());
        $this->assertTrue(Notification::query()->where('title', 'Material Return')->where('user_id', $this->buyer->id)->exists());

        $this->actingAs($this->buyer);

        $this->getTablePage(route('inventory.material-requests.table'))
            ->assertSee('Return')
            ->assertSee('Left over');

        $this->postJson(route('inventory.material-requests.approve', $return))->assertOk();

        $entry = ProjectMaterialEntry::query()->where('entry_type', ProjectMaterialEntry::TYPE_RETURNED)->sole();

        $this->assertSame(MaterialRequest::STATUS_APPROVED, $return->fresh()->status);
        $this->assertSame($entry->material_entry_id, $return->fresh()->project_material_entry_id);
        $this->assertSame('Left over', $entry->notes);
        $this->assertSame(9.0, $this->copper->fresh()->onHand());
        $this->assertTrue(Notification::query()->where('title', 'Material Return Approved')->where('user_id', $this->lead->id)->exists());
    }

    public function test_a_rejected_return_moves_nothing(): void
    {
        $line = $this->onSite(4);
        $this->returnMaterial($line, 2);
        $return = MaterialRequest::query()->where('request_type', MaterialRequest::TYPE_RETURN)->sole();

        $this->actingAs($this->buyer)
            ->postJson(route('inventory.material-requests.reject', $return), ['reason' => 'Still needed on site.'])
            ->assertOk();

        $this->assertSame(0, ProjectMaterialEntry::query()->where('entry_type', ProjectMaterialEntry::TYPE_RETURNED)->count());
        $this->assertSame(6.0, $this->copper->fresh()->onHand());
    }

    public function test_a_lead_cannot_return_more_than_is_on_site(): void
    {
        $line = $this->onSite(4);

        $this->returnMaterial($line, 5)->assertSessionHas('error');

        $this->assertSame(0, MaterialRequest::query()->where('request_type', MaterialRequest::TYPE_RETURN)->count());
    }

    /**
     * Four of copper on the project, approved off a shelf of ten.
     */
    private function onSite(float|int $quantity): ProjectMaterial
    {
        $this->stock(10);
        $this->requestMaterial($quantity);

        $this->actingAs($this->buyer)
            ->postJson(route('inventory.material-requests.approve', MaterialRequest::query()->firstOrFail()))
            ->assertOk();

        return ProjectMaterial::query()->where('project_id', $this->project->project_id)->sole();
    }

    private function returnMaterial(ProjectMaterial $line, float|int $quantity)
    {
        return $this->actingAs($this->lead)
            ->post(route('technician.projects.materials.record', $this->project->project_id), [
                'project_material_id' => $line->project_material_id,
                'entry_type' => ProjectMaterialEntry::TYPE_RETURNED,
                'quantity' => $quantity,
                'entry_date' => BusinessTime::today()->toDateString(),
                'notes' => 'Left over',
            ]);
    }

    private function requestMaterial(float|int $quantity)
    {
        return $this->actingAs($this->lead)
            ->post(route('technician.projects.materials.requests.store', $this->project->project_id), [
                'material_id' => $this->copper->material_id,
                'quantity' => $quantity,
            ]);
    }

    private function stock(float|int $quantity): void
    {
        app(StockLedger::class)->receive($this->copper, StockMovement::TYPE_STOCK_IN, $quantity, BusinessTime::today(), [], $this->buyer);
    }

    private function account(string $role): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => sprintf('EMP-%04d', $sequence),
            'name' => 'Person '.$sequence,
            'first_name' => 'Person',
            'last_name' => (string) $sequence,
            'email' => 'person'.$sequence.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }
}
