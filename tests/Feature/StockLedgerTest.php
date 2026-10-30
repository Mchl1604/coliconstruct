<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Material;
use App\Models\Notification;
use App\Models\Project;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\MaterialUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Inventory -> Stock: what came in, what went out, and counts set right.
 *
 * On hand is the sum of the movements that are not voided, it never goes
 * below zero, and Purchasing is told on the way down to the reorder level.
 */
class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Material $aircon;

    private Material $copper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();

        $this->aircon = Material::factory()->brand('Daikin')->reorderAt(5)->create([
            'name' => 'Split-Type Inverter 1.5 HP',
            'unit' => MaterialUnit::Unit,
        ]);

        $this->copper = Material::factory()->measured()->create(['name' => 'Copper Tube 1/4']);
    }

    public function test_a_delivery_adds_to_what_is_on_hand(): void
    {
        $this->receive($this->aircon, 12, ['supplier' => 'Daikin PH', 'reference_no' => 'DR-1001'])
            ->assertOk()
            ->assertJsonPath('message', '12 unit of Split-Type Inverter 1.5 HP (Daikin) received.')
            ->assertJsonPath('summary.out', 1);

        $movement = StockMovement::query()->firstOrFail();

        $this->assertSame(12.0, $this->aircon->fresh()->onHand());
        $this->assertSame('Daikin PH', $movement->supplier);
        $this->assertSame('DR-1001', $movement->reference_no);
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityLog::STOCK_RECEIVED)->where('module', ActivityLog::MODULE_INVENTORY)->count());
    }

    public function test_a_return_names_its_project_and_not_a_supplier(): void
    {
        $project = $this->project('REF-STK-1');

        $this->receive($this->copper, 2.5, [
            'movement_type' => StockMovement::TYPE_RETURNED,
            'project_id' => $project->project_id,
            'supplier' => 'Ignored',
        ])->assertOk();

        $movement = StockMovement::query()->firstOrFail();

        $this->assertSame($project->project_id, $movement->project_id);
        $this->assertNull($movement->supplier);
        $this->assertSame(2.5, $this->copper->fresh()->onHand());
    }

    public function test_nothing_goes_out_past_what_is_on_hand(): void
    {
        $this->issue($this->aircon, 1)
            ->assertStatus(422)
            ->assertJsonPath('error', 'No Split-Type Inverter 1.5 HP (Daikin) on hand.');

        $this->receive($this->aircon, 3);

        $this->issue($this->aircon, 4)
            ->assertStatus(422)
            ->assertJsonPath('error', 'Only 3 unit on hand.');

        $this->issue($this->aircon, 3)->assertOk();

        $this->assertSame(0.0, $this->aircon->fresh()->onHand());
        $this->assertSame(-3.0, (float) StockMovement::query()->where('movement_type', StockMovement::TYPE_STOCK_OUT)->value('quantity'));
    }

    public function test_stock_goes_out_only_to_a_project_still_in_progress(): void
    {
        $this->receive($this->aircon, 10);

        $open = $this->project('REF-STK-2');
        $closed = $this->project('REF-STK-3', 'completed');

        $this->issue($this->aircon, 1, ['project_id' => $closed->project_id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Choose a project still in progress.');

        $this->issue($this->aircon, 1, ['project_id' => $open->project_id])->assertOk();

        $this->assertSame($open->project_id, StockMovement::query()->where('movement_type', StockMovement::TYPE_STOCK_OUT)->value('project_id'));
    }

    public function test_a_count_sets_the_record_to_what_was_found(): void
    {
        $this->receive($this->aircon, 10);

        $this->adjust($this->aircon, 10, 'Counted')
            ->assertStatus(422)
            ->assertJsonPath('error', 'The count matches the record.');

        $this->adjust($this->aircon, 7, '')
            ->assertStatus(422)
            ->assertJsonPath('error', 'Say why the count changed.');

        $this->adjust($this->aircon, 7, 'Three damaged in storage')->assertOk();

        $this->assertSame(7.0, $this->aircon->fresh()->onHand());
        $this->assertSame(-3.0, (float) StockMovement::query()->where('movement_type', StockMovement::TYPE_ADJUSTMENT)->value('quantity'));

        $this->adjust($this->aircon, 9, 'Found two more')->assertOk();

        $this->assertSame(9.0, $this->aircon->fresh()->onHand());
    }

    public function test_a_voided_entry_stops_counting_but_stays_listed(): void
    {
        $this->receive($this->aircon, 10);
        $this->receive($this->aircon, 4);

        $delivery = StockMovement::query()->orderBy('stock_movement_id')->firstOrFail();

        $this->postJson(route('inventory.stock.void', $delivery))->assertOk();

        $this->assertSame(4.0, $this->aircon->fresh()->onHand());
        $this->assertNotNull($delivery->fresh()->voided_at);

        $this->postJson(route('inventory.stock.void', $delivery))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Already voided.');

        $this->getTablePage(route('inventory.stock.movements'))
            ->assertSee('material-entry-voided', false)
            ->assertSee('Voided');
    }

    public function test_a_delivery_already_issued_cannot_be_voided(): void
    {
        $this->receive($this->aircon, 5);
        $this->issue($this->aircon, 4);

        $delivery = StockMovement::query()->where('movement_type', StockMovement::TYPE_STOCK_IN)->firstOrFail();

        $this->postJson(route('inventory.stock.void', $delivery))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Already issued. Void later entries first.');

        $this->assertSame(1.0, $this->aircon->fresh()->onHand());
    }

    public function test_quantities_follow_the_unit_and_dates_cannot_be_in_the_future(): void
    {
        $this->receive($this->aircon, 1.5)
            ->assertStatus(422)
            ->assertJsonPath('error', 'Whole numbers only for unit.');

        $this->receive($this->copper, 1.25)->assertOk();

        $this->receive($this->aircon, 1, ['movement_date' => now()->addDays(3)->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('error', 'The date cannot be in the future.');
    }

    public function test_purchasing_is_told_once_on_the_way_down(): void
    {
        $buyer = $this->account(User::ROLE_PURCHASING_ADMIN);
        $officer = $this->account(User::ROLE_ADMIN_OFFICER);
        $hrAdmin = $this->account(User::ROLE_ADMIN);

        $this->receive($this->aircon, 10);

        // 10 -> 6: still above the reorder level of 5.
        $this->issue($this->aircon, 4);
        $this->assertSame(0, Notification::query()->count());

        // 6 -> 4: Low Stock.
        $this->issue($this->aircon, 2);

        $low = Notification::query()->where('title', 'Low Stock')->get();

        $this->assertEqualsCanonicalizing(
            [$buyer->id, $officer->id, auth()->id()],
            $low->pluck('user_id')->all()
        );
        $this->assertFalse($low->contains('user_id', $hrAdmin->id));
        $this->assertSame('Split-Type Inverter 1.5 HP (Daikin) is down to 4 unit.', $low->first()->message);
        $this->assertSame(Notification::MODULE_INVENTORY, $low->first()->module);
        $this->assertStringContainsString('stock=low_stock', (string) $low->first()->url);

        // 4 -> 3: still low, nothing new.
        $this->issue($this->aircon, 1);
        $this->assertSame(3, Notification::query()->count());

        // 3 -> 0: Out of Stock.
        $this->issue($this->aircon, 3);
        $this->assertSame(3, Notification::query()->where('title', 'Out of Stock')->count());
    }

    public function test_the_stock_table_filters_on_its_status(): void
    {
        $this->receive($this->aircon, 4);
        $this->receive($this->copper, 30);

        $plenty = Material::factory()->reorderAt(2)->create(['name' => 'Wall Bracket']);
        $this->receive($plenty, 10);

        $this->getTablePage(route('inventory.stock.index'), ['stock' => Material::STOCK_LOW])
            ->assertSee('Split-Type Inverter 1.5 HP')
            ->assertDontSee('Copper Tube')
            ->assertDontSee('Wall Bracket');

        $this->getTablePage(route('inventory.stock.index'), ['stock' => Material::STOCK_IN])
            ->assertSee('Copper Tube')
            ->assertSee('Wall Bracket')
            ->assertDontSee('Split-Type Inverter');

        $empty = Material::factory()->create(['name' => 'Drain Hose']);

        $this->getTablePage(route('inventory.stock.index'), ['stock' => Material::STOCK_OUT])
            ->assertSee($empty->name)
            ->assertDontSee('Copper Tube');

        $this->getTablePage(route('inventory.stock.index'), ['search' => 'daikin'])
            ->assertSee('Low Stock')
            ->assertSee('data-stock-action="out"', false);
    }

    public function test_history_narrows_movements_to_one_material(): void
    {
        $this->receive($this->aircon, 4, ['reference_no' => 'DR-AIRCON']);
        $this->receive($this->copper, 30, ['reference_no' => 'DR-COPPER']);

        $this->getTablePage(route('inventory.stock.movements'), ['material' => $this->copper->material_id])
            ->assertSee('DR-COPPER')
            ->assertDontSee('DR-AIRCON');

        $this->getTablePage(route('inventory.stock.movements'), ['type' => StockMovement::TYPE_STOCK_OUT])
            ->assertDontSee('DR-COPPER');
    }

    public function test_an_hr_admin_cannot_move_stock(): void
    {
        $this->actingAs($this->account(User::ROLE_ADMIN));

        $this->receive($this->aircon, 5)->assertForbidden();
        $this->getJson(route('inventory.stock.index'))->assertForbidden();

        $this->actingAs($this->account(User::ROLE_PURCHASING_ADMIN));

        $this->receive($this->aircon, 5)->assertOk();

        $this->assertSame(5.0, $this->aircon->fresh()->onHand());
    }

    public function test_the_inventory_page_opens_on_a_notice_filter(): void
    {
        $this->get(route('inventory.index', ['stock' => Material::STOCK_LOW]))
            ->assertOk()
            ->assertSee('id="stockTable"', false)
            ->assertSee('id="movementsTable"', false)
            ->assertSee('<option value="low_stock" selected>', false)
            ->assertSee('data-stock-modal', false);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $extra
     */
    private function receive(Material $material, float|int $quantity, array $extra = []): TestResponse
    {
        return $this->postJson(route('inventory.stock.receive'), $extra + [
            'material_id' => $material->material_id,
            'movement_type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => $quantity,
            'movement_date' => now()->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function issue(Material $material, float|int $quantity, array $extra = []): TestResponse
    {
        return $this->postJson(route('inventory.stock.issue'), $extra + [
            'material_id' => $material->material_id,
            'quantity' => $quantity,
            'movement_date' => now()->toDateString(),
        ]);
    }

    private function adjust(Material $material, float|int $counted, string $reason): TestResponse
    {
        return $this->postJson(route('inventory.stock.adjust'), [
            'material_id' => $material->material_id,
            'counted' => $counted,
            'movement_date' => now()->toDateString(),
            'notes' => $reason,
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
}
