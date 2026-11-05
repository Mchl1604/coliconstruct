<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectDetail;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\Skill;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectDetailForms;
use App\Services\ServiceReport;
use App\Support\DetailFieldScope;
use App\Support\ServiceReportForms;
use Database\Seeders\DetailFormSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProjectsAreAlreadySetUp;
use Tests\TestCase;

/**
 * The paper Service Report: the fixed forms of the aircon project types, and
 * the printable dialog on Project Details with only the sections that apply.
 */
class ServiceReportTest extends TestCase
{
    use ProjectsAreAlreadySetUp;
    use RefreshDatabase;

    /**
     * @param  array<int, string>  $typeNames
     */
    private function projectWith(array $typeNames): Project
    {
        foreach (['Equipment Check-up', 'Equipment Repair', 'Aircon Cleaning', 'Aircon Installation', 'Ducting Installation', 'Solar Panels'] as $name) {
            ProjectType::query()->firstOrCreate(['type_name' => $name]);
        }

        $this->seed(DetailFormSeeder::class);

        $client = Client::factory()->create([
            'client_type' => 'Commercial',
            'company_name' => 'Acme Foods Inc.',
            'fullname' => 'Maria Santos',
            'contact_number' => '09171234567',
        ]);

        $project = Project::create([
            'client_id' => $client->client_id,
            'name' => 'Report Project',
            'reference_no' => 'REF-REPORT',
            'status' => 'ongoing',
            'address' => '12 Sample St., Cavite',
            'description' => null,
        ]);

        $project->projectTypes()->sync(ProjectType::query()->whereIn('type_name', $typeNames)->pluck('type_id'));

        return $project;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function unit(Project $project, string $typeName, array $values): void
    {
        $type = ProjectType::query()->where('type_name', $typeName)->sole();

        ProjectDetail::create([
            'project_id' => $project->project_id,
            'type_id' => $type->type_id,
            'form_id' => $type->form_id,
            'scope' => DetailFieldScope::Unit,
            'values' => $values,
            'labels' => array_combine(array_keys($values), array_keys($values)),
        ]);
    }

    public function test_the_report_shows_only_the_sections_of_the_projects_types(): void
    {
        $project = $this->projectWith(['Equipment Repair']);
        $this->unit($project, 'Equipment Repair', ['brand' => 'Daikin', 'model' => 'FTKM-50', 'trouble_description' => 'Not cooling']);

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('View Project Details')
            ->assertSeeInOrder(['SERVICE REPORT', 'Acme Foods Inc.', '12 Sample St., Cavite', 'Maria Santos', '09171234567', 'EQUIPMENT REPAIR:', 'FTKM-50', 'Not cooling'])
            ->assertDontSee('EQUIPMENT CHECK-UP:')
            ->assertDontSee('AIR-CON GENERAL CLEANING:')
            ->assertDontSee('START UP DATA');
    }

    public function test_the_report_leaves_out_what_was_taken_off_the_paper(): void
    {
        $project = $this->projectWith(['Equipment Check-up', 'Equipment Repair', 'Aircon Cleaning', 'Aircon Installation']);

        $this->actingAsSuperAdmin();

        $page = $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        $page->assertSeeInOrder(['EQUIPMENT CHECK-UP:', 'AIR-CON GENERAL CLEANING:', 'START UP DATA', 'MATERIAL PROFILE']);

        foreach (['CES-SR', 'SYSTEM PRESSURE', 'RATED LOAD AMPERE', 'RUNNING CURRENT', 'FOR QUOTATION'] as $removed) {
            $page->assertDontSee($removed);
        }
    }

    public function test_every_unit_gets_its_own_block_and_ticks_what_it_holds(): void
    {
        $project = $this->projectWith(['Aircon Installation']);
        $this->unit($project, 'Aircon Installation', ['brand' => 'LG', 'copper_pipe_sizes' => ['3/8', '1/2'], 'indoor_serial_no' => 'IN-1']);
        $this->unit($project, 'Aircon Installation', ['brand' => 'Gree', 'indoor_serial_no' => 'IN-2']);

        $this->actingAsSuperAdmin();

        $html = $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSeeInOrder(['IN-1', 'IN-2'])
            ->getContent();

        $this->assertSame(2, substr_count($html, 'MATERIAL PROFILE'));
        $this->assertSame(4, substr_count(strstr($html, 'data-service-report-sheet'), '&#10003;'));
    }

    public function test_only_an_hourly_booking_fills_in_the_times(): void
    {
        $project = $this->projectWith(['Equipment Repair']);
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => '2026-11-10 09:00:00',
            'end_datetime' => '2026-11-10 11:30:00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
        ]);

        $header = fn (): array => app(ServiceReport::class)->for($project->fresh())['header'];

        $this->assertNotSame('', $header()['last_modified']);
        $this->assertNotSame('', $header()['time_start']);
        $this->assertNotSame('', $header()['time_end']);

        $schedule->update(['scheduling_mode' => Schedule::MODE_DATE_BASED]);

        $this->assertNotSame('', $header()['last_modified']);
        $this->assertSame('', $header()['time_start']);
        $this->assertSame('', $header()['time_end']);
    }

    public function test_a_type_outside_the_report_keeps_its_folded_details(): void
    {
        $project = $this->projectWith(['Solar Panels']);
        $type = ProjectType::query()->where('type_name', 'Solar Panels')->sole();

        ProjectDetail::create([
            'project_id' => $project->project_id,
            'type_id' => $type->type_id,
            'form_id' => $type->form_id,
            'scope' => DetailFieldScope::Project,
            'values' => ['scope_of_work' => 'Mount the panels'],
            'labels' => ['scope_of_work' => 'Scope of work'],
        ]);

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Show details')
            ->assertSee('Mount the panels')
            ->assertDontSee('data-service-report-sheet', false);
    }

    public function test_the_office_may_edit_from_the_dialog_and_the_crew_may_only_print(): void
    {
        $project = $this->projectWith(['Equipment Check-up']);

        $this->actingAsSuperAdmin();
        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('data-service-report-print', false)
            ->assertSee('data-bs-target="#editProjectDescriptionModal"', false);

        $account = User::factory()->create(['email' => 'crew@example.test']);
        $account->forceFill(['role' => 'technician', 'status' => User::STATUS_ACTIVE, 'must_change_password' => false])->save();
        $technician = Technician::create(['account_id' => $account->id, 'role' => 'technician']);
        ProjectTechnician::create(['project_id' => $project->project_id, 'technician_id' => $technician->technician_id]);

        $this->actingAs($account)
            ->get(route('technician.projects.show', $project))
            ->assertOk()
            ->assertSee('data-service-report-print', false)
            ->assertDontSee('editProjectDescriptionModal');
    }

    public function test_fields_only_known_during_the_work_wait_until_after_creation(): void
    {
        $project = $this->projectWith(['Equipment Check-up', 'Equipment Repair', 'Aircon Cleaning', 'Aircon Installation']);
        $this->actingAsSuperAdmin();

        $wizard = $this->get(route('super-admin.projects.create'))->assertOk();
        $edit = $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        foreach (['Equipment Check-up' => 'findings', 'Equipment Repair' => 'remarks', 'Aircon Cleaning' => 'equipment_tagging', 'Aircon Installation' => 'outdoor_serial_no'] as $typeName => $key) {
            $typeId = ProjectType::query()->where('type_name', $typeName)->value('type_id');
            $name = 'name="details['.$typeId.'][units][0]['.$key.']"';

            $wizard->assertDontSee($name, false);
            $edit->assertSee($name, false);
        }

        $checkUpId = ProjectType::query()->where('type_name', 'Equipment Check-up')->value('type_id');
        $wizard->assertSee('name="details['.$checkUpId.'][units][0][trouble_description]"', false);
    }

    public function test_only_report_types_hide_fields_at_creation(): void
    {
        $this->assertFalse(ServiceReportForms::isFilledDuringWork('Solar Panels', 'remarks'));
        $this->assertTrue(ServiceReportForms::isFilledDuringWork('Equipment Repair', 'remarks'));
    }

    public function test_the_description_has_no_label_or_pencil_and_other_types_can_still_be_edited(): void
    {
        $aircon = $this->projectWith(['Equipment Repair']);

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $aircon->project_id))
            ->assertOk()
            ->assertDontSee('Project Description:')
            ->assertDontSee('title="Edit description"', false)
            ->assertDontSee('Edit Details')
            ->assertSee('data-service-report-print', false);

        $aircon->projectTypes()->sync(ProjectType::query()->where('type_name', 'Solar Panels')->pluck('type_id'));

        $this->get(route('super-admin.projects.show', $aircon->project_id))
            ->assertOk()
            ->assertDontSee('Project Description:')
            ->assertSee('Edit Details')
            ->assertSee('data-bs-target="#editProjectDescriptionModal"', false);
    }

    public function test_ducting_stays_a_type_with_its_own_form_and_no_report(): void
    {
        $project = $this->projectWith(['Ducting Installation']);
        $type = ProjectType::query()->where('type_name', 'Ducting Installation')->sole();

        $this->assertSame('Ducting', $type->detailForm->name);
        $this->assertArrayHasKey('details.'.$type->type_id.'.project.scope_of_work', app(ProjectDetailForms::class)->rules(ProjectType::query()->whereKey($type->type_id)->get()));

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Edit Details')
            ->assertDontSee('data-service-report-sheet', false)
            ->assertDontSee('data-bs-target="#checklist"', false);
    }

    public function test_a_project_with_no_type_left_offers_nothing_to_open(): void
    {
        $project = $this->projectWith([]);

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertDontSee('Edit Details')
            ->assertDontSee('data-service-report-print', false);
    }

    public function test_the_checklist_tab_only_shows_for_types_on_the_paper_with_one(): void
    {
        $cleaning = $this->projectWith(['Aircon Cleaning']);
        $this->unit($cleaning, 'Aircon Cleaning', ['brand' => 'LG', 'area_served' => 'Lobby']);

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $cleaning->project_id))
            ->assertOk()
            ->assertSee('data-bs-target="#checklist"', false)
            ->assertSeeInOrder(['Unit 1 - Lobby', 'Indoor Unit', 'Filters', 'Outdoor Unit', 'Condenser fins']);

        $cleaning->projectTypes()->sync(ProjectType::query()->where('type_name', 'Equipment Repair')->pluck('type_id'));

        $this->get(route('super-admin.projects.show', $cleaning->project_id))
            ->assertOk()
            ->assertDontSee('data-bs-target="#checklist"', false);
    }

    public function test_the_super_admin_ticks_an_item_and_it_prints_on_the_report(): void
    {
        $project = $this->projectWith(['Aircon Cleaning']);
        $this->unit($project, 'Aircon Cleaning', ['brand' => 'LG']);
        $detail = ProjectDetail::query()->where('project_id', $project->project_id)->sole();

        $this->actingAsSuperAdmin();
        $url = route('super-admin.projects.checklist.update', $project);

        $this->postJson($url, ['detail_id' => $detail->detail_id, 'item' => 'indoor_filters', 'done' => true])
            ->assertOk()
            ->assertJsonPath('done_count', 1);
        $this->postJson($url, ['detail_id' => $detail->detail_id, 'item' => 'outdoor_compressor', 'done' => true])
            ->assertOk()
            ->assertJsonPath('done_count', 2);
        $this->postJson($url, ['detail_id' => $detail->detail_id, 'item' => 'indoor_filters', 'done' => false])
            ->assertOk()
            ->assertJsonPath('done_count', 1);

        $this->assertSame(['outdoor_compressor'], $detail->refresh()->checklist);

        $html = $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSeeInOrder(['INDOOR UNIT CHECKLIST', 'OUTDOOR UNIT CHECKLIST', 'COMPRESSOR'])
            ->getContent();

        $this->assertStringContainsString('&#10003;</span>COMPRESSOR', $html);
        $this->assertStringNotContainsString('&#10003;</span>FILTERS', $html);
        $this->assertTrue(ActivityLog::query()->where('action', ActivityLog::PROJECT_CHECKLIST_UPDATED)->exists());
    }

    public function test_only_a_lead_on_the_project_may_tick_among_the_crew(): void
    {
        $project = $this->projectWith(['Aircon Installation']);
        $this->unit($project, 'Aircon Installation', ['brand' => 'LG']);
        $detail = ProjectDetail::query()->where('project_id', $project->project_id)->sole();
        $url = route('technician.projects.checklist.update', $project);
        $payload = ['detail_id' => $detail->detail_id, 'item' => 'indoor_panel', 'done' => true];

        $crew = function (string $role, string $email, bool $onProject) use ($project): User {
            $account = User::factory()->create(['email' => $email]);
            $account->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE, 'must_change_password' => false])->save();
            $technician = Technician::create(['account_id' => $account->id, 'role' => $role]);

            if ($onProject) {
                ProjectTechnician::create(['project_id' => $project->project_id, 'technician_id' => $technician->technician_id]);
            }

            return $account;
        };

        $this->actingAs($crew('technician', 'plain@example.test', true))
            ->get(route('technician.projects.show', $project))
            ->assertOk()
            ->assertSee('data-bs-target="#checklist"', false);
        $this->postJson($url, $payload)->assertForbidden();

        $this->actingAs($crew('lead_technician', 'other-lead@example.test', false))
            ->postJson($url, $payload)
            ->assertForbidden();

        $this->assertNull($detail->refresh()->checklist);

        $this->actingAs($crew('lead_technician', 'lead@example.test', true))
            ->postJson($url, $payload)
            ->assertOk();

        $this->assertSame(['indoor_panel'], $detail->refresh()->checklist);
    }

    public function test_an_office_role_below_super_admin_cannot_tick(): void
    {
        $project = $this->projectWith(['Aircon Cleaning']);
        $this->unit($project, 'Aircon Cleaning', ['brand' => 'LG']);
        $detail = ProjectDetail::query()->where('project_id', $project->project_id)->sole();

        $officer = User::factory()->create(['email' => 'officer@example.test']);
        $officer->forceFill(['role' => User::ROLE_ADMIN_OFFICER, 'status' => User::STATUS_ACTIVE, 'must_change_password' => false])->save();

        $this->actingAs($officer)
            ->postJson(route('super-admin.projects.checklist.update', $project), ['detail_id' => $detail->detail_id, 'item' => 'indoor_panel', 'done' => true])
            ->assertForbidden();

        $this->assertNull($detail->refresh()->checklist);
    }

    public function test_a_unit_of_another_project_or_an_unknown_item_is_refused(): void
    {
        $project = $this->projectWith(['Aircon Cleaning']);
        $this->unit($project, 'Aircon Cleaning', ['brand' => 'LG']);
        $detail = ProjectDetail::query()->where('project_id', $project->project_id)->sole();

        $other = Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Other Project',
            'reference_no' => 'REF-OTHER',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => null,
        ]);
        $other->projectTypes()->sync(ProjectType::query()->where('type_name', 'Aircon Cleaning')->pluck('type_id'));

        $this->actingAsSuperAdmin();

        $this->postJson(route('super-admin.projects.checklist.update', $other), ['detail_id' => $detail->detail_id, 'item' => 'indoor_panel', 'done' => true])
            ->assertNotFound();
        $this->postJson(route('super-admin.projects.checklist.update', $project), ['detail_id' => $detail->detail_id, 'item' => 'made_up', 'done' => true])
            ->assertUnprocessable();

        $this->assertNull($detail->refresh()->checklist);
    }

    public function test_editing_the_report_keeps_the_ticks_of_a_unit(): void
    {
        $project = $this->projectWith(['Aircon Cleaning']);
        $this->unit($project, 'Aircon Cleaning', ['brand' => 'LG', 'system_type' => 'Split']);
        $detail = ProjectDetail::query()->where('project_id', $project->project_id)->sole();
        $detail->update(['checklist' => ['indoor_swing']]);
        $type = ProjectType::query()->where('type_name', 'Aircon Cleaning')->sole();

        $this->actingAsSuperAdmin();

        $this->put(route('super-admin.projects.details.update', $project->project_id), [
            'details' => [$type->type_id => ['units' => [[ProjectDetailForms::DETAIL_ID_KEY => $detail->detail_id, 'brand' => 'Gree', 'system_type' => 'Split']]]],
        ])->assertSessionHas('success');

        $saved = ProjectDetail::query()->where('project_id', $project->project_id)->sole();

        $this->assertSame('Gree', $saved->values['brand']);
        $this->assertSame(['indoor_swing'], $saved->checklist);
    }

    public function test_the_header_shows_when_the_report_was_last_modified(): void
    {
        $project = $this->projectWith(['Equipment Repair']);
        Project::query()->whereKey($project->project_id)->update(['created_at' => '2026-01-10 10:00:00']);

        $this->assertSame('Jan 10, 2026', app(ServiceReport::class)->for($project->fresh())['header']['last_modified']);

        $this->unit($project, 'Equipment Repair', ['brand' => 'LG']);
        ProjectDetail::query()->where('project_id', $project->project_id)->update(['updated_at' => '2026-03-05 10:00:00']);

        $this->assertSame('Mar 5, 2026', app(ServiceReport::class)->for($project->fresh())['header']['last_modified']);

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSeeInOrder(['LAST MODIFIED:', 'Mar 5, 2026'])
            ->assertDontSee('>DATE:<', false);
    }

    public function test_the_signatories_are_edited_and_printed_but_not_asked_at_creation(): void
    {
        $project = $this->projectWith(['Equipment Repair']);
        $this->unit($project, 'Equipment Repair', ['brand' => 'LG', 'equipment_type' => 'VRF', 'trouble_description' => 'Noisy']);
        $detail = ProjectDetail::query()->where('project_id', $project->project_id)->sole();
        $type = ProjectType::query()->where('type_name', 'Equipment Repair')->sole();

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.create'))
            ->assertOk()
            ->assertDontSee('report_performed_by', false)
            ->assertDontSee('report_witnessed_by', false)
            ->assertDontSee('Witnessed and Accepted By')
            ->assertDontSee('report_remarks', false);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('name="report_performed_by"', false)
            ->assertSee('name="report_witnessed_by"', false)
            ->assertSee('name="report_remarks"', false)
            ->assertSeeInOrder(['PERFORMED BY:', 'AC TECH / SERVICE ENGINEER', 'WITNESSED AND ACCEPTED BY:', 'CLIENT / REPRESENTATIVE']);

        $this->put(route('super-admin.projects.details.update', $project->project_id), [
            'report_performed_by' => 'Ramon Cruz',
            'report_witnessed_by' => 'Maria Santos',
            'report_remarks' => 'Client shown the filters.',
            'details' => [$type->type_id => ['units' => [[
                ProjectDetailForms::DETAIL_ID_KEY => $detail->detail_id,
                'brand' => 'LG',
                'equipment_type' => 'VRF',
                'trouble_description' => 'Noisy',
            ]]]],
        ])->assertSessionHas('success');

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSeeInOrder(['PERFORMED BY:', 'Ramon Cruz', 'REMARKS:', 'Client shown the filters.', 'WITNESSED AND ACCEPTED BY:', 'Maria Santos']);
    }

    public function test_a_new_unit_is_checked_against_the_paper_form(): void
    {
        $project = $this->projectWith(['Equipment Check-up']);
        $types = $project->projectTypes()->get();
        $type = $types->sole();
        $rules = app(ProjectDetailForms::class)->rules($types);

        $this->assertContains('required', $rules['details.'.$type->type_id.'.units.*.trouble_description']);
        $this->assertArrayHasKey('details.'.$type->type_id.'.units.*.findings', $rules);
        $this->assertArrayNotHasKey('details.'.$type->type_id.'.units.*.quantity', $rules);
    }

    public function test_the_migration_renames_repair_adds_check_up_and_deletes_heating(): void
    {
        $repair = ProjectType::create(['type_name' => 'Aircon Repair']);
        $heating = ProjectType::create(['type_name' => 'Heating Ventillation']);
        $repairSkill = Skill::create(['skill_name' => 'Aircon Repair']);
        Skill::create(['skill_name' => 'Heating Ventillation']);

        $account = User::factory()->create(['email' => 'tech@example.test']);
        $technician = Technician::create(['account_id' => $account->id, 'role' => 'technician']);
        $technician->skills()->attach($repairSkill->skill_id);

        $project = Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Old Project',
            'reference_no' => 'REF-OLD',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => null,
        ]);
        $project->projectTypes()->sync([$repair->type_id, $heating->type_id]);

        $migration = require database_path('migrations/2026_11_05_032046_apply_paper_service_report_to_project_types.php');
        $migration->up();

        $this->assertSame('Equipment Repair', $repair->refresh()->type_name);
        $this->assertSame('Equipment Repair', $repairSkill->refresh()->skill_name);
        $this->assertFalse(ProjectType::query()->where('type_name', 'like', 'Heating%')->exists());
        $this->assertFalse(Skill::query()->where('skill_name', 'like', 'Heating%')->exists());
        $this->assertSame(['Equipment Repair'], $project->projectTypes()->pluck('type_name')->all());
        $this->assertSame('Equipment Repair', $repair->detailForm->name);

        $checkUpSkill = Skill::query()->where('skill_name', 'Equipment Check-up')->sole();
        $this->assertTrue(ProjectType::query()->where('type_name', 'Equipment Check-up')->whereNotNull('form_id')->exists());
        $this->assertTrue(DB::table('tbl_skill_map')->where('technician_id', $technician->technician_id)->where('skill_id', $checkUpSkill->skill_id)->exists());
    }
}
