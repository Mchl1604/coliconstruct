<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\Material;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectMaterialEntry;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phases 5 and 6: the lead's generated daily progress report, and the
 * structured incident report with the office's review.
 */
class DailyReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $leadAccount;

    private User $mateAccount;

    private Technician $lead;

    private Technician $mate;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leadAccount = $this->account('lead_technician', 'lead@example.test');
        $this->lead = Technician::create(['account_id' => $this->leadAccount->id, 'role' => 'lead_technician']);

        $this->mateAccount = $this->account('technician', 'mate@example.test');
        $this->mate = Technician::create(['account_id' => $this->mateAccount->id, 'role' => 'technician']);

        $this->project = Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Aircon Retrofit',
            'reference_no' => 'REF-DAY-1',
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
        $this->finalizePhases($this->project);

        foreach ([$this->lead, $this->mate] as $technician) {
            ProjectTechnician::create([
                'project_id' => $this->project->project_id,
                'technician_id' => $technician->technician_id,
                'joined_at' => now()->subDays(5),
            ]);
        }

        $schedule = Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => $this->day(-2).' 00:00:00',
            'end_datetime' => $this->day(10).' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        foreach ($this->project->projectTechnicians()->get() as $assignment) {
            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Phase 5: the generated progress report
    // ------------------------------------------------------------------

    public function test_the_lead_sees_the_day_gathered(): void
    {
        $this->dayOfWork();

        $this->actingAs($this->leadAccount)
            ->get(route('technician.progress-reports.create', ['project' => $this->project->project_id]))
            ->assertOk()
            ->assertDontSee('Gather')
            ->assertDontSee('name="date"', false)
            ->assertSee('Wired the indoor unit.')
            ->assertSee('Copper Tube 1/4')
            ->assertSee('Used')
            ->assertSee('File Report');
    }

    public function test_the_report_is_filed_once_a_day_and_frozen(): void
    {
        $task = $this->dayOfWork();

        $this->actingAs($this->leadAccount)
            ->post(route('technician.progress-reports.store'), [
                'project_id' => $this->project->project_id,
                'report_date' => $this->day(0),
                'remarks' => 'Good progress.',
                'next_plan' => 'Mount the outdoor unit.',
            ])
            ->assertRedirect(route('technician.reports'))
            ->assertSessionHas('success', 'Progress report filed.');

        $report = TechnicianReport::query()->firstOrFail();

        $this->assertSame('progress', $report->report_type);
        $this->assertSame('Good progress.', $report->report_data['remarks']);
        $this->assertSame('Wired the indoor unit.', $report->report_data['task_updates'][0]['notes']);
        $this->assertStringContainsString('Wired the indoor unit.', $report->report_description);

        // Later edits never change a filed report.
        TaskUpdate::query()->update(['notes' => 'Rewritten afterwards.']);
        $this->assertSame('Wired the indoor unit.', $report->fresh()->report_data['task_updates'][0]['notes']);

        $this->actingAs($this->leadAccount)
            ->post(route('technician.progress-reports.store'), [
                'project_id' => $this->project->project_id,
                'report_date' => $this->day(0),
            ])
            ->assertSessionHas('error', 'A progress report for this day is already filed.');

        $this->assertSame(1, TechnicianReport::count());
        $this->assertNotNull($task);
    }

    public function test_a_report_is_always_for_today_and_only_on_a_scheduled_day(): void
    {
        // A date sent with the form is ignored.
        $this->actingAs($this->leadAccount)
            ->post(route('technician.progress-reports.store'), [
                'project_id' => $this->project->project_id,
                'report_date' => $this->day(-5),
            ])
            ->assertSessionHas('success');

        $this->assertSame($this->day(0), TechnicianReport::query()->firstOrFail()->report_date->toDateString());

        $this->project->schedules()->delete();
        TechnicianReport::query()->delete();

        $this->actingAs($this->leadAccount)
            ->post(route('technician.progress-reports.store'), ['project_id' => $this->project->project_id])
            ->assertSessionHas('error', 'Reports only on scheduled days.');

        $this->assertSame(0, TechnicianReport::count());
    }

    public function test_tasks_in_progress_show_among_the_task_updates(): void
    {
        $task = $this->dayOfWork();

        Task::create([
            'project_id' => $this->project->project_id,
            'phase_id' => $this->defaultPhaseId($this->project),
            'technician_id' => $this->mate->technician_id,
            'task_title' => 'Mount the bracket',
            'task_description' => 'Mount it',
            'start_date' => $this->day(-1),
            'due_date' => $this->day(3),
            'status' => 'ongoing',
        ]);

        $this->actingAs($this->leadAccount)
            ->get(route('technician.progress-reports.create', ['project' => $this->project->project_id]))
            ->assertOk()
            ->assertSee('Mount the bracket')
            ->assertSee('In Progress');

        $this->actingAs($this->leadAccount)->post(route('technician.progress-reports.store'), [
            'project_id' => $this->project->project_id,
        ])->assertSessionHas('success');

        $snapshot = TechnicianReport::query()->firstOrFail()->report_data;

        // Listed once: a task with an update today is among the updates.
        $this->assertSame(['Mount the bracket'], array_column($snapshot['in_progress_tasks'], 'task'));
        $this->assertSame($task->task_title, $snapshot['task_updates'][0]['task']);
    }

    public function test_opened_from_a_project_it_asks_for_no_project_and_returns_there(): void
    {
        $this->dayOfWork();
        $id = $this->project->project_id;
        $back = route('technician.projects.show', $id).'#reports';

        $this->actingAs($this->leadAccount);

        foreach (['progress', 'incident'] as $kind) {
            $this->get(route('technician.'.$kind.'-reports.create', ['project' => $id, 'from' => 'project']))
                ->assertOk()
                ->assertSee('Back to Project')
                ->assertSee('href="'.$back.'"', false)
                ->assertDontSee('name="project"', false)
                ->assertDontSee('Continue');

            $this->get(route('technician.'.$kind.'-reports.create'))
                ->assertOk()
                ->assertSee('Back to Reports')
                ->assertSee('name="project"', false)
                ->assertDontSee('Continue')
                ->assertDontSee('Gather');
        }

        $this->post(route('technician.progress-reports.store'), ['project_id' => $id, 'from' => 'project'])
            ->assertRedirect($back);

        $this->post(route('technician.incident-reports.store'), $this->incidentPayload(['from' => 'project']))
            ->assertRedirect($back);
    }

    public function test_the_project_page_offers_one_create_report_button(): void
    {
        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Create Report')
            ->assertSee(e(route('technician.progress-reports.create', ['project' => $this->project->project_id, 'from' => 'project'])), false)
            ->assertSee(e(route('technician.incident-reports.create', ['project' => $this->project->project_id, 'from' => 'project'])), false);
    }

    public function test_the_incident_form_drops_what_was_removed(): void
    {
        $response = $this->actingAs($this->leadAccount)
            ->get(route('technician.incident-reports.create', ['project' => $this->project->project_id, 'from' => 'project']))
            ->assertOk();

        foreach (['severity', 'location_details', 'task_id', 'damage_owner', 'damage_cost', 'preventive_action', 'work_stopped', 'client_notified'] as $field) {
            $response->assertDontSee('name="'.$field.'"', false);
        }
    }

    public function test_other_asks_what_and_reads_back_as_what_was_typed(): void
    {
        $base = ['incident_type' => 'other', 'location' => 'other', 'cause' => 'other'];

        $this->actingAs($this->leadAccount)
            ->post(route('technician.incident-reports.store'), $this->incidentPayload($base))
            ->assertSessionHas('error', 'Please specify.');

        $this->assertSame(0, TechnicianReport::count());

        $this->post(route('technician.incident-reports.store'), $this->incidentPayload($base + [
            'incident_type_other' => 'Stray dog on site',
            'location_other' => 'Client garage',
            'cause_other' => 'Open gate',
        ]))->assertSessionHas('success');

        $report = TechnicianReport::query()->firstOrFail();

        $this->assertSame('Incident: Stray dog on site', $report->report_title);
        $this->assertSame('Client garage', $report->incident->label('LOCATIONS', 'location'));

        $this->get($report->documentUrl())
            ->assertOk()
            ->assertSee('Stray dog on site')
            ->assertSee('Client garage')
            ->assertSee('Open gate');

        // An injury's Other is not asked for on another kind of incident.
        $this->post(route('technician.incident-reports.store'), $this->incidentPayload([
            'incident_type' => 'near_miss',
            'injury_body_part' => 'other',
        ]))->assertSessionHas('success');
    }

    public function test_the_filed_report_reads_on_its_own_page_for_the_crew_and_the_office(): void
    {
        $this->dayOfWork();

        $this->actingAs($this->leadAccount)->post(route('technician.progress-reports.store'), [
            'project_id' => $this->project->project_id,
            'report_date' => $this->day(0),
        ]);

        $report = TechnicianReport::query()->firstOrFail();

        $this->assertNotNull($report->documentUrl());

        $this->actingAs($this->mateAccount)->get($report->documentUrl())->assertOk()->assertSee('Wired the indoor unit.');

        $this->actingAsSuperAdminAccount();
        $this->get($report->documentUrl())->assertOk()->assertSee('Print');
    }

    public function test_a_plain_technician_cannot_generate_a_report(): void
    {
        $this->actingAs($this->mateAccount)
            ->get(route('technician.progress-reports.create'))
            ->assertRedirect(route('technician.schedule'));
    }

    // ------------------------------------------------------------------
    // Phase 6: the structured incident report
    // ------------------------------------------------------------------

    public function test_the_lead_files_a_structured_incident_and_the_office_hears_at_once(): void
    {
        $admin = $this->account('admin', 'hr@example.test');

        $this->actingAs($this->leadAccount)
            ->post(route('technician.incident-reports.store'), $this->incidentPayload([
                'persons' => [
                    ['technician_id' => $this->mate->technician_id, 'person_type' => 'team', 'involvement' => 'injured'],
                    ['name' => 'Mrs. Santos', 'person_type' => 'client', 'involvement' => 'witness'],
                ],
            ]))
            ->assertRedirect(route('technician.reports'))
            ->assertSessionHas('success', 'Incident report filed.');

        $report = TechnicianReport::query()->firstOrFail();
        $incident = $report->incident;

        $this->assertSame('incident', $report->report_type);
        $this->assertSame('Incident: Injury', $report->report_title);
        $this->assertSame('injury', $incident->incident_type);
        $this->assertSame('lost_time', $incident->injury_treatment);
        $this->assertTrue($incident->mayNeedDoleReport());
        $this->assertSame(IncidentReport::STATUS_OPEN, $incident->status);
        $this->assertSame(2, $incident->persons()->count());
        $this->assertSame('team', $incident->persons()->where('technician_id', $this->mate->technician_id)->value('person_type'));

        $this->assertSame(1, Notification::query()->where('title', 'Technician Report Filed')->where('user_id', $admin->id)->count());

        $this->actingAs($admin)
            ->get($report->documentUrl())
            ->assertOk()
            ->assertSee('Lost-time injury. May need a DOLE report.')
            ->assertSee('Mrs. Santos')
            ->assertSee('Save Review');
    }

    public function test_an_injury_needs_its_details(): void
    {
        $this->actingAs($this->leadAccount)
            ->post(route('technician.incident-reports.store'), $this->incidentPayload(['injury_treatment' => '']))
            ->assertSessionHas('error', 'Choose the treatment given.');

        $this->assertSame(0, TechnicianReport::count());
    }

    public function test_only_the_lead_files_and_only_from_the_team(): void
    {
        $this->actingAs($this->mateAccount)
            ->get(route('technician.incident-reports.create'))
            ->assertRedirect(route('technician.schedule'));

        $outsider = Technician::create([
            'account_id' => $this->account('technician', 'outsider@example.test')->id,
            'role' => 'technician',
        ]);

        $this->actingAs($this->leadAccount)
            ->post(route('technician.incident-reports.store'), $this->incidentPayload([
                'persons' => [['technician_id' => $outsider->technician_id, 'person_type' => 'team', 'involvement' => 'involved']],
            ]))
            ->assertSessionHas('error', "Pick people from this project's team.");
    }

    public function test_the_office_reviews_and_closes_with_notes(): void
    {
        $this->actingAs($this->leadAccount)->post(route('technician.incident-reports.store'), $this->incidentPayload());
        $incident = IncidentReport::query()->firstOrFail();

        $admin = $this->actingAsSuperAdminAccount();

        $this->put(route('super-admin.incident-reports.status', $incident), ['status' => 'closed'])
            ->assertSessionHas('error', 'Say how it was resolved before closing it.');

        $this->put(route('super-admin.incident-reports.status', $incident), [
            'status' => 'closed',
            'resolution_notes' => 'First aid given; ladder replaced.',
        ])->assertSessionHas('success', 'Incident updated.');

        $incident->refresh();

        $this->assertSame(IncidentReport::STATUS_CLOSED, $incident->status);
        $this->assertSame($admin->id, $incident->reviewed_by);
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityLog::INCIDENT_REVIEWED)->count());
    }

    public function test_the_lead_cannot_review(): void
    {
        $this->actingAs($this->leadAccount)->post(route('technician.incident-reports.store'), $this->incidentPayload());
        $incident = IncidentReport::query()->firstOrFail();

        $this->actingAs($this->leadAccount)
            ->put(route('super-admin.incident-reports.status', $incident), ['status' => 'closed', 'resolution_notes' => 'x'])
            ->assertRedirect(route('technician.schedule'));

        $this->assertSame(IncidentReport::STATUS_OPEN, $incident->fresh()->status);
    }

    public function test_the_progress_report_lists_the_days_incidents(): void
    {
        $this->actingAs($this->leadAccount)->post(route('technician.incident-reports.store'), $this->incidentPayload(['severity' => 'minor']));

        $this->actingAs($this->leadAccount)
            ->get(route('technician.progress-reports.create', ['project' => $this->project->project_id, 'date' => $this->day(0)]))
            ->assertOk()
            ->assertSee('Incident: Injury');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * A task with today's update and a material used today.
     */
    private function dayOfWork(): Task
    {
        $task = Task::create([
            'project_id' => $this->project->project_id,
            'phase_id' => $this->defaultPhaseId($this->project),
            'technician_id' => $this->mate->technician_id,
            'task_title' => 'Wire indoor unit',
            'task_description' => 'Wire it',
            'start_date' => $this->day(-1),
            'due_date' => $this->day(3),
            'status' => 'ongoing',
        ]);

        TaskUpdate::create([
            'task_id' => $task->task_id,
            'technician_id' => $this->mate->technician_id,
            'update_date' => $this->day(0),
            'notes' => 'Wired the indoor unit.',
            'posted_by' => $this->mateAccount->id,
        ]);

        $copper = Material::factory()->measured()->create(['name' => 'Copper Tube 1/4']);
        $line = ProjectMaterial::create([
            'project_id' => $this->project->project_id,
            'material_id' => $copper->material_id,
            'quantity_needed' => 10,
        ]);

        ProjectMaterialEntry::create([
            'project_id' => $this->project->project_id,
            'project_material_id' => $line->project_material_id,
            'entry_type' => ProjectMaterialEntry::TYPE_USED,
            'quantity' => 4,
            'entry_date' => $this->day(0),
            'recorded_by' => $this->leadAccount->id,
            'recorded_by_name' => 'Lead',
        ]);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function incidentPayload(array $overrides = []): array
    {
        return array_merge([
            'project_id' => $this->project->project_id,
            'incident_type' => 'injury',
            'occurred_date' => $this->day(0),
            'occurred_time' => '00:01',
            'location' => 'outdoor_unit',
            'description' => 'Slipped on the ladder while mounting the bracket.',
            'injury_body_part' => 'arm',
            'injury_type' => 'sprain',
            'injury_treatment' => 'lost_time',
            'immediate_action' => 'First aid on site.',
            'cause' => 'unsafe_condition',
        ], $overrides);
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

    private function actingAsSuperAdminAccount(): User
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        $this->actingAs($owner);

        return $owner;
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }
}
