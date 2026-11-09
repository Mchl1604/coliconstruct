<?php

namespace Tests\Feature;

use App\Http\Controllers\ProjectQuotationController;
use App\Models\Client;
use App\Models\Material;
use App\Models\MaterialType;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\QuotationHistory;
use App\Models\QuotationItem;
use App\Models\StockMovement;
use App\Models\SystemContent;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectMaterialPlan;
use App\Services\ProjectQuotation;
use App\Services\SystemContentService;
use App\Support\MaterialUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProjectsAreAlreadySetUp;
use Tests\TestCase;

/**
 * The quotation breakdown: written once, totalled into the project's quotation,
 * free to go past stock, where the Materials plan is not. Prices stay with the
 * office and the client.
 */
class QuotationFormTest extends TestCase
{
    use ProjectsAreAlreadySetUp;
    use RefreshDatabase;

    private function material(string $name = 'Copper Tube', ?float $price = 150.0, float $onHand = 0): Material
    {
        $material = Material::factory()->create([
            'material_type_id' => MaterialType::query()->firstOrCreate(['type_name' => 'Piping'])->getKey(),
            'name' => $name,
            'unit' => MaterialUnit::cases()[0]->value,
            'unit_price' => $price,
        ]);

        if ($onHand > 0) {
            StockMovement::factory()->create(['material_id' => $material->material_id, 'quantity' => $onHand]);
        }

        return $material;
    }

    private function project(array $attributes = []): Project
    {
        $project = Project::create($attributes + [
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Quoted Project',
            'reference_no' => 'REF-QUOTE',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => null,
            'quotation' => 1000,
        ]);

        $project->projectTypes()->sync([ProjectType::query()->firstOrCreate(['type_name' => 'Aircon Installation'])->type_id]);

        return $project;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(Material $material, float|int $quantity = 2): array
    {
        return [
            ['kind' => 'material', 'material_id' => $material->material_id, 'quantity' => $quantity, 'unit_price' => 150],
            ['kind' => 'labor', 'description' => 'Installation labor', 'unit' => 'lot', 'quantity' => 1, 'unit_price' => 1000.5],
        ];
    }

    private function account(string $role, string $email): User
    {
        $account = User::factory()->create(['email' => $email]);
        $account->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE, 'must_change_password' => false])->save();

        return $account;
    }

    public function test_the_total_comes_from_the_lines(): void
    {
        $rows = $this->lines($this->material());

        $this->assertSame(1300.5, app(ProjectQuotation::class)->total($rows));
    }

    public function test_saved_lines_keep_what_was_quoted_when_the_material_changes(): void
    {
        $material = $this->material();
        $project = $this->project();

        app(ProjectQuotation::class)->save($project, $this->lines($material));

        $material->update(['name' => 'Renamed Tube', 'unit_price' => 999]);

        $line = $project->quotationItems()->where('kind', QuotationItem::KIND_MATERIAL)->sole();

        $this->assertSame('Copper Tube', $line->description);
        $this->assertSame(150.0, (float) $line->unit_price);
        $this->assertSame(300.0, $line->amount());
    }

    public function test_a_line_may_quote_past_stock_but_the_plan_may_not(): void
    {
        $material = $this->material(onHand: 6);
        $quotation = app(ProjectQuotation::class);
        $rows = $this->lines($material, 10);

        $this->assertSame([], $quotation->errors($rows));

        $limited = app(ProjectMaterialPlan::class)->limitToAvailable([
            ['material_id' => $material->material_id, 'quantity' => 6, 'request_remaining' => 4],
        ]);

        $this->assertSame(6.0, (float) $limited['rows'][0]['quantity']);
        $this->assertSame(4.0, (float) $limited['requests'][0]['quantity']);
    }

    public function test_a_material_quoted_twice_or_a_blank_description_is_refused(): void
    {
        $material = $this->material();
        $errors = app(ProjectQuotation::class)->errors([
            ['kind' => 'material', 'material_id' => $material->material_id, 'quantity' => 1, 'unit_price' => 1],
            ['kind' => 'material', 'material_id' => $material->material_id, 'quantity' => 1, 'unit_price' => 1],
            ['kind' => 'labor', 'description' => '  ', 'quantity' => 1, 'unit_price' => 1],
        ]);

        $this->assertArrayHasKey('quotation_items.1.material_id', $errors);
        $this->assertArrayHasKey('quotation_items.2.description', $errors);
    }

    public function test_editing_updates_the_total_and_history_and_leaves_reserved_stock_alone(): void
    {
        $material = $this->material(onHand: 20);
        $project = $this->project();
        $plan = ProjectMaterial::create(['project_id' => $project->project_id, 'material_id' => $material->material_id, 'quantity_needed' => 2]);

        $this->actingAsSuperAdmin();

        $this->put(route('super-admin.projects.quotation.update', $project->project_id), ['quotation_items' => $this->lines($material, 9)])
            ->assertSessionHas('success', 'Quotation saved. Upload the new approved file.');

        $this->assertSame(2350.5, (float) $project->fresh()->quotation);
        $this->assertSame(2.0, (float) $plan->fresh()->quantity_needed);
        $this->assertSame(2350.5, (float) QuotationHistory::query()->where('project_id', $project->project_id)->sole()->new_amount);
    }

    public function test_a_refused_edit_reopens_on_its_own_error_bag(): void
    {
        $project = $this->project();

        $this->actingAsSuperAdmin();

        $this->from(route('super-admin.projects.show', $project->project_id))
            ->put(route('super-admin.projects.quotation.update', $project->project_id), ['quotation_items' => []])
            ->assertSessionHasErrorsIn('quotation', ['quotation_items']);

        $this->assertSame(1000.0, (float) $project->fresh()->quotation);
    }

    public function test_the_edit_project_dialog_cannot_change_a_total_set_by_lines(): void
    {
        $project = $this->project();
        app(ProjectQuotation::class)->save($project, $this->lines($this->material()));

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Set by the quotation details.');
    }

    public function test_the_form_prints_for_the_office_with_its_lines_and_total(): void
    {
        $project = $this->project(['quotation' => 1300.5]);
        app(ProjectQuotation::class)->save($project, $this->lines($this->material()));

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('View Details')
            ->assertSee('data-bs-target="#quotationModal'.$project->project_id.'"', false)
            ->assertSee('data-service-report-print', false)
            ->assertSeeInOrder(['Quotation', 'Copper Tube', '300.00', 'Installation labor', '1,000.50', 'TOTAL AMOUNT', 'PHP', '1,300.50']);
    }

    public function test_the_sheet_follows_the_quote_form_without_rfq_no_tin_tax_or_vat(): void
    {
        $project = $this->project(['quotation' => 1300.5]);
        app(ProjectQuotation::class)->save($project, $this->lines($this->material()));

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSeeInOrder(['Office &amp; Warehouse Address:', 'Date:', 'Client:', 'Address:', 'Contact Person:', 'Phone No.:', 'Email Address:', 'Project Reference No.:', $project->reference_no, 'Location:'], false)
            ->assertSeeInOrder(['Item', 'Description', 'Quantity', 'Unit', 'Unit Price', 'Line Total'])
            ->assertSeeInOrder(['Amount', 'Discount %', 'Discount Amount', 'Special Discount', 'TOTAL AMOUNT'])
            ->assertSeeInOrder(['Notes:', 'INCLUSIONS:', 'EXCLUSIONS:', 'Terms of Payment', 'PREPARED BY:', 'approved by', 'CONFORME:', 'SIGNATURE OVER PRINTED NAME / DATE'])
            ->assertDontSee('RFQ')
            ->assertDontSee('TIN:')
            ->assertDontSee('UOM')
            ->assertDontSee('Tax Type')
            ->assertDontSee('VAT')
            ->assertDontSee('ELECTRONICALLY GENERATED');
    }

    public function test_discounts_come_off_the_total_and_print_on_the_sheet(): void
    {
        $material = $this->material();
        $project = $this->project();

        $this->actingAsSuperAdmin();

        $this->put(route('super-admin.projects.quotation.update', $project->project_id), [
            'quotation_items' => $this->lines($material),
            'quotation_discount_percent' => 10,
            'quotation_special_discount' => 100.5,
        ])->assertSessionHas('success');

        $project->refresh();

        // 1,300.50 less 10% (130.05) less 100.50.
        $this->assertSame(1069.95, (float) $project->quotation);
        $this->assertSame(10.0, (float) $project->quotation_discount_percent);
        $this->assertSame(100.5, (float) $project->quotation_special_discount);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSeeInOrder(['Amount', '1,300.50', 'Discount %', '10%', 'Discount Amount', '130.05', 'Special Discount', '100.50', 'TOTAL AMOUNT', '1,069.95']);
    }

    public function test_a_special_discount_past_the_amount_or_a_discount_over_100_is_refused(): void
    {
        $material = $this->material();
        $project = $this->project();
        $url = route('super-admin.projects.quotation.update', $project->project_id);

        $this->actingAsSuperAdmin();

        $this->put($url, ['quotation_items' => $this->lines($material), 'quotation_special_discount' => 1300.51])
            ->assertSessionHasErrorsIn('quotation', ['quotation_special_discount']);

        $this->put($url, ['quotation_items' => $this->lines($material), 'quotation_discount_percent' => 101])
            ->assertSessionHasErrorsIn('quotation', ['quotation_discount_percent']);

        $this->assertSame(1000.0, (float) $project->fresh()->quotation);
    }

    public function test_the_terms_and_signatories_are_saved_and_printed(): void
    {
        $material = $this->material();
        $project = $this->project();

        $this->actingAsSuperAdmin();

        $this->put(route('super-admin.projects.quotation.update', $project->project_id), [
            'quotation_items' => $this->lines($material),
            'quotation_notes' => '  Valid for 30 days.  ',
            'quotation_inclusions' => 'Installation and testing',
            'quotation_exclusions' => 'Civil works',
            'quotation_payment_terms' => '50% down, 50% on completion',
            'quotation_prepared_by' => 'Kianne Solis',
            'quotation_approved_by' => '',
        ])->assertSessionHas('success');

        $project->refresh();

        $this->assertSame('Valid for 30 days.', $project->quotation_notes);
        $this->assertNull($project->quotation_approved_by);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSeeInOrder([
                'Notes:', 'Valid for 30 days.',
                'INCLUSIONS:', 'Installation and testing',
                'EXCLUSIONS:', 'Civil works',
                'Terms of Payment', '50% down, 50% on completion',
                'PREPARED BY:', 'Kianne Solis',
            ])
            // The edit dialog opens on what was saved.
            ->assertSee('name="quotation_prepared_by"', false)
            ->assertSee('>Civil works</textarea>', false);
    }

    public function test_overlong_terms_are_refused(): void
    {
        $project = $this->project();

        $this->actingAsSuperAdmin();

        $this->put(route('super-admin.projects.quotation.update', $project->project_id), [
            'quotation_items' => $this->lines($this->material()),
            'quotation_prepared_by' => str_repeat('a', 101),
        ])->assertSessionHasErrorsIn('quotation', ['quotation_prepared_by']);

        $this->assertNull($project->fresh()->quotation_prepared_by);
    }

    public function test_the_date_moves_to_the_day_the_quotation_is_edited(): void
    {
        $material = $this->material();
        $project = $this->project();

        $this->travelTo(now()->setDate(2026, 3, 2)->setTime(10, 0));
        app(ProjectQuotation::class)->save($project, $this->lines($material));

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertSee('02 Mar 2026');

        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(10, 0));

        $this->put(route('super-admin.projects.quotation.update', $project->project_id), [
            'quotation_items' => $this->lines($material),
            'quotation_special_discount' => 50,
        ])->assertSessionHas('success');

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertSee('09 Mar 2026')
            ->assertDontSee('02 Mar 2026');
    }

    public function test_the_date_can_be_chosen_on_the_edit_form(): void
    {
        $material = $this->material();
        $project = $this->project();

        $this->travelTo(now()->setDate(2026, 3, 2)->setTime(10, 0));
        app(ProjectQuotation::class)->save($project, $this->lines($material));

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertSee('name="quoted_at" class="form-control" value="2026-03-02"', false);

        $url = route('super-admin.projects.quotation.update', $project->project_id);

        $this->put($url, ['quotation_items' => $this->lines($material), 'quoted_at' => 'not a date'])
            ->assertSessionHasErrorsIn(ProjectQuotationController::ERROR_BAG, 'quoted_at');

        $this->put($url, ['quotation_items' => $this->lines($material), 'quoted_at' => '2026-02-20'])
            ->assertSessionHas('success');

        $this->assertSame('2026-02-20', $project->fresh()->quoted_at->format('Y-m-d'));

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertSee('20 Feb 2026');
    }

    public function test_an_old_project_offers_a_breakdown_only_to_those_who_can_add_one(): void
    {
        $project = $this->project();

        $this->actingAsSuperAdmin();
        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('View Details')
            ->assertDontSee('Add Breakdown')
            ->assertSee('data-bs-target="#quotationEditModal'.$project->project_id.'"', false)
            ->assertDontSee('data-bs-target="#quotationModal'.$project->project_id.'"', false);

        $this->actingAs($this->account(User::ROLE_ADMIN, 'hr@example.test'))
            ->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertDontSee('quotationEditModal'.$project->project_id)
            ->assertDontSee('quotationModal'.$project->project_id);
    }

    public function test_labor_has_no_unit_and_the_sheet_names_the_project_ref_no(): void
    {
        $project = $this->project(['quotation' => 1300.5]);
        app(ProjectQuotation::class)->save($project, $this->lines($this->material()));

        $this->assertNull($project->quotationItems()->where('kind', 'labor')->sole()->unit);

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSeeInOrder(['Project Reference No.:', $project->reference_no])
            ->assertDontSee('PROJECT NO:')
            ->assertDontSee('data-quotation-add="labor"', false)
            ->assertSee('data-quotation-add="other"', false);
    }

    public function test_labor_is_one_amount_and_the_sheet_uses_the_configured_contact_details(): void
    {
        $project = $this->project(['quotation' => 2300]);
        app(ProjectQuotation::class)->save($project, [
            ['kind' => 'material', 'material_id' => $this->material()->material_id, 'quantity' => 2, 'unit_price' => 150],
            ['kind' => 'labor', 'description' => 'Labor cost', 'quantity' => 2, 'unit_price' => 1000],
        ]);

        $labor = $project->quotationItems()->where('kind', 'labor')->sole();
        $this->assertSame(1.0, (float) $labor->quantity);
        $this->assertSame(2000.0, (float) $labor->unit_price);

        SystemContent::query()->updateOrCreate(['content_key' => 'contact.address'], ['content_value' => '1 Configured Street, Cavite']);
        SystemContent::query()->updateOrCreate(['content_key' => 'contact.phone'], ['content_value' => '(046) 555-0101']);
        app(SystemContentService::class)->flush();

        $this->actingAsSuperAdmin();

        $page = $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        $page->assertSee('1 Configured Street, Cavite')
            ->assertSee('Phone No.: (046) 555-0101')
            ->assertDontSee('NHA Industrial')
            // Every price carries the peso sign.
            ->assertSee('150.00')
            ->assertSee('300.00')
            ->assertSee('<td colspan="4">Labor cost</td>', false)
            ->assertSee('2,000.00');
    }

    public function test_only_those_who_create_projects_may_edit(): void
    {
        $project = $this->project();
        $material = $this->material();
        $url = route('super-admin.projects.quotation.update', $project->project_id);

        $this->actingAs($this->account(User::ROLE_ADMIN, 'hr@example.test'))
            ->put($url, ['quotation_items' => $this->lines($material)])
            ->assertRedirect();

        $this->assertSame(0, $project->quotationItems()->count());

        $this->actingAs($this->account(User::ROLE_ADMIN_OFFICER, 'officer@example.test'))
            ->put($url, ['quotation_items' => $this->lines($material)])
            ->assertSessionHas('success');

        $this->assertSame(2, $project->quotationItems()->count());
    }

    public function test_the_crew_never_sees_the_form_or_a_price(): void
    {
        $project = $this->project();
        $material = $this->material('Priced Tube', 12345.67);
        app(ProjectQuotation::class)->save($project, $this->lines($material));

        $account = $this->account('lead_technician', 'lead@example.test');
        $technician = Technician::create(['account_id' => $account->id, 'role' => 'lead_technician']);
        ProjectTechnician::create(['project_id' => $project->project_id, 'technician_id' => $technician->technician_id]);

        $this->actingAs($account)
            ->get(route('technician.projects.show', $project))
            ->assertOk()
            ->assertDontSee('quotationModal'.$project->project_id)
            ->assertDontSee('12,345.67')
            ->assertDontSee('12345.67')
            ->assertDontSee('unit_price', false);
    }

    public function test_the_wizard_search_gives_the_inventory_price_only_to_those_who_quote(): void
    {
        $material = $this->material('Searchable Tube', 88.5);

        $this->actingAs($this->account(User::ROLE_ADMIN_OFFICER, 'officer@example.test'))
            ->getJson(route('super-admin.projects.material-options', ['q' => 'Searchable']))
            ->assertOk()
            ->assertJsonPath('materials.0.material_id', $material->material_id)
            ->assertJsonPath('materials.0.unit_price', '88.50');

        $this->actingAs($this->account(User::ROLE_ADMIN, 'hr@example.test'))
            ->getJson(route('super-admin.projects.material-options', ['q' => 'Searchable']))
            ->assertOk()
            ->assertJsonMissingPath('materials.0.unit_price');
    }

    public function test_inventory_saves_a_price_on_a_material(): void
    {
        $material = $this->material('Listed Tube', null);

        $this->actingAsSuperAdmin();

        $this->putJson(route('inventory.materials.update', $material), [
            'material_type_id' => $material->material_type_id,
            'name' => $material->name,
            'unit' => $material->unit->value,
            'unit_price' => '245.50',
        ])->assertOk();

        $this->assertSame(245.5, (float) $material->fresh()->unit_price);

        $this->putJson(route('inventory.materials.update', $material), [
            'material_type_id' => $material->material_type_id,
            'name' => $material->name,
            'unit' => $material->unit->value,
            'unit_price' => '-1',
        ])->assertStatus(422);
    }

    public function test_the_materials_tab_shows_quoted_figures_and_what_is_not_planned(): void
    {
        $planned = $this->material('Planned Tube');
        $unplanned = $this->material('Unplanned Tube');
        $project = $this->project();
        ProjectMaterial::create(['project_id' => $project->project_id, 'material_id' => $planned->material_id, 'quantity_needed' => 1]);

        app(ProjectQuotation::class)->save($project, [
            ['kind' => 'material', 'material_id' => $planned->material_id, 'quantity' => 3, 'unit_price' => 1],
            ['kind' => 'material', 'material_id' => $unplanned->material_id, 'quantity' => 4, 'unit_price' => 1],
        ]);

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('>Quoted</th>', false)
            ->assertSee('Quoted, not planned:')
            ->assertSee('Unplanned Tube');
    }
}
