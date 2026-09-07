<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\ClientProjects;
use App\Services\DashboardMetrics;
use App\Services\ProjectPhaseProgress;
use App\Services\ProjectPhaseRules;
use App\Services\ProjectReopen;
use App\Services\TaskPhaseRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Phase setup: a one-time act per project, and then a locked structure.
 *
 * The whole point of the lock is that "2/4 Phases" keeps meaning the same
 * thing. An Admin or a Lead Technician who could add a fifth phase halfway
 * through a job would turn that into "2/5" without any work having been done,
 * and every progress figure on the project would quietly become a different
 * measurement. So the tests below are as much about who is refused as about
 * who is allowed.
 */
class ProjectPhaseSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $admin;

    private User $leadAccount;

    private Technician $lead;

    private Technician $mate;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->account('super_admin', 'owner@example.test');
        $this->admin = $this->account('admin', 'admin@example.test');

        $this->leadAccount = $this->account('lead_technician', 'lead@example.test');
        $this->lead = Technician::create([
            'account_id' => $this->leadAccount->id,
            'role' => 'lead_technician',
        ]);

        $mateAccount = $this->account('technician', 'mate@example.test');
        $this->mate = Technician::create([
            'account_id' => $mateAccount->id,
            'role' => 'technician',
        ]);

        // Deliberately NOT given phases: this whole file is about the project
        // that has just been created and has none.
        $this->project = Project::create([
            'name' => 'Aircon Retrofit',
            'reference_no' => 'REF-PHASE-1',
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        $this->assign($this->lead);
        $this->assign($this->mate);
        $this->book(10, 20);
    }

    // ------------------------------------------------------------------
    // State 1: setup required
    // ------------------------------------------------------------------

    public function test_a_new_project_starts_awaiting_phase_setup(): void
    {
        // Read back from the database, because `pending` is the column's own
        // default rather than something the application writes.
        $project = $this->project->fresh();

        $this->assertTrue($project->needsPhaseSetup());
        $this->assertSame(Project::PHASE_SETUP_PENDING, $project->phase_setup_status);
        $this->assertNull($project->phase_count);
        $this->assertCount(0, $project->phases()->get());
    }

    public function test_project_details_offers_setup_instead_of_the_monitoring_panel(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Phase Setup Required')
            ->assertSee('Set Up Project Phases')
            // The monitoring interface is absent, not merely empty.
            ->assertDontSee('0/0 Phases');
    }

    /**
     * The setup screen is the only phase interface an unfinalized project has.
     */
    public function test_the_three_authorized_roles_can_open_the_setup_screen(): void
    {
        foreach ([$this->superAdmin, $this->admin] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('super-admin.projects.phases.setup', $this->project->project_id))
                ->assertOk()
                ->assertSee('Set Up Project Phases');
        }

        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.phases.setup', $this->project->project_id))
            ->assertOk()
            ->assertSee('Set Up Project Phases');
    }

    /**
     * A plain technician does the work; they do not decide what the stages of
     * the job are.
     *
     * They are turned away at the door - the phase routes sit inside the
     * lead-only group - and being in the wrong place is a navigation mistake
     * in this application rather than an attack, so it is a redirect home
     * rather than a 403. The rule itself is asserted separately, so this stays
     * true if the door ever moves.
     */
    public function test_a_plain_technician_cannot_open_the_setup_screen(): void
    {
        $this->actingAs($this->mate->account)
            ->get(route('technician.projects.phases.setup', $this->project->project_id))
            ->assertRedirect();

        $this->assertFalse(
            app(ProjectPhaseRules::class)->canSetUp($this->mate->account, $this->project)
        );
    }

    /**
     * A lead runs the boards they are on and nobody else's.
     */
    public function test_a_lead_cannot_set_up_phases_for_a_project_they_are_not_on(): void
    {
        $other = Project::create([
            'name' => 'Somebody Else',
            'reference_no' => 'REF-PHASE-2',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.phases.setup', $other->project_id))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Finalizing
    // ------------------------------------------------------------------

    public function test_finalizing_locks_the_structure_and_records_who_did_it(): void
    {
        $this->actingAs($this->admin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => $this->structure(),
            ])
            ->assertRedirect(route('super-admin.projects.show', $this->project->project_id));

        $project = $this->project->fresh();

        $this->assertTrue($project->phasesAreFinalized());
        $this->assertSame(4, $project->phase_count);
        $this->assertSame($this->admin->id, $project->phase_setup_finalized_by);
        $this->assertNotNull($project->phase_setup_finalized_at);

        $phases = $project->phases()->get();

        $this->assertCount(4, $phases);
        $this->assertSame([1, 2, 3, 4], $phases->pluck('sequence')->all());
        $this->assertSame(
            ['Site Preparation', 'Installation', 'Testing', 'Final Inspection'],
            $phases->pluck('title')->all()
        );

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::PROJECT_PHASES_FINALIZED,
            'record_type' => 'Project',
            'record_id' => $project->project_id,
        ]);
    }

    /**
     * Saving without locking exists so a half-typed structure is not lost, and
     * it must not quietly finalize anything.
     */
    public function test_saving_without_finalizing_keeps_the_project_in_setup(): void
    {
        $this->actingAs($this->admin)
            ->post(route('super-admin.projects.phases.save', $this->project->project_id), [
                'phases' => $this->structure(),
            ])
            ->assertRedirect(route('super-admin.projects.phases.setup', $this->project->project_id));

        $project = $this->project->fresh();

        $this->assertTrue($project->needsPhaseSetup());
        $this->assertNull($project->phase_count);
        $this->assertCount(4, $project->phases()->get());
    }

    public function test_a_structure_with_no_phases_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => [],
            ])
            ->assertSessionHasErrors('phases');

        $this->assertTrue($this->project->fresh()->needsPhaseSetup());
    }

    public function test_a_phase_without_a_description_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => [['title' => 'Site Preparation', 'description' => '']],
            ])
            ->assertSessionHasErrors('phases.0.description');

        $this->assertTrue($this->project->fresh()->needsPhaseSetup());
    }

    // ------------------------------------------------------------------
    // State 2: locked
    // ------------------------------------------------------------------

    /**
     * The rule the whole feature exists for.
     */
    public function test_an_admin_cannot_change_a_finalized_structure(): void
    {
        $this->finalize();

        $this->actingAs($this->admin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => $this->structure(5),
            ])
            ->assertForbidden();

        $this->assertSame(4, $this->project->fresh()->phase_count);
        $this->assertCount(4, $this->project->phases()->get());
    }

    public function test_a_lead_cannot_change_a_finalized_structure(): void
    {
        $this->finalize();

        $this->actingAs($this->leadAccount)
            ->post(route('technician.projects.phases.finalize', $this->project->project_id), [
                'phases' => $this->structure(5),
            ])
            ->assertForbidden();

        $this->assertSame(4, $this->project->fresh()->phase_count);
    }

    /**
     * There is no way back into setup for anybody but a Super Admin - not even
     * by keeping the URL.
     */
    public function test_the_setup_screen_redirects_away_once_the_structure_is_locked(): void
    {
        $this->finalize();

        $this->actingAs($this->admin)
            ->get(route('super-admin.projects.phases.setup', $this->project->project_id))
            ->assertRedirect(route('super-admin.projects.show', $this->project->project_id));

        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.phases.setup', $this->project->project_id))
            ->assertRedirect(route('technician.projects.show', $this->project->project_id));
    }

    public function test_a_finalized_project_shows_the_monitoring_panel(): void
    {
        $this->finalize();

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Project Phases')
            ->assertSee('0/4 Phases')
            ->assertSee('Current Phase')
            ->assertDontSee('Phase Setup Required');
    }

    /**
     * Both project pages are split in two, and the phases live in the second
     * half - so completing one has to come back to a page that opens there
     * rather than to a panel hidden behind a tab.
     */
    public function test_the_administrative_page_splits_into_two_tabs(): void
    {
        $this->finalize();

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('data-bs-target="#project-information"', false)
            ->assertSee('data-bs-target="#project-progress"', false)
            // The record in the first half, how far it has got in the second.
            ->assertSeeInOrder([
                'id="project-information"',
                'Registered User Account',
                'Assigned Team',
                'id="project-progress"',
                'Project Phases',
                'Project Activity',
            ], false);
    }

    public function test_the_technician_page_splits_into_the_same_two_tabs(): void
    {
        $this->finalize();

        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('data-bs-target="#project-information"', false)
            ->assertSee('data-bs-target="#project-progress"', false)
            ->assertSeeInOrder([
                'id="project-information"',
                'Assigned Team',
                'id="project-progress"',
                'Project Phases',
            ], false);
    }

    public function test_completing_a_phase_returns_to_the_progress_tab(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $task = $this->task($phases[0]);
        $task->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.complete', [
                'project' => $this->project->project_id,
                'phase' => $phases[0]->phase_id,
            ]))
            ->assertRedirect(
                route('super-admin.projects.show', $this->project->project_id).'#project-progress'
            );

        // And the lead's copy of the page lands on the same pane.
        $this->actingAs($this->leadAccount)
            ->post(route('technician.projects.phases.complete', [
                'project' => $this->project->project_id,
                'phase' => $phases[1]->phase_id,
            ]), ['override' => 0])
            ->assertRedirect();
    }

    // ------------------------------------------------------------------
    // Super Admin override
    // ------------------------------------------------------------------

    public function test_only_a_super_admin_can_unlock_a_finalized_structure(): void
    {
        $this->finalize();

        // Refused at the door - the override route is the one endpoint in the
        // administrative group narrowed to a Super Admin - and refused again
        // by the rule behind it.
        $this->actingAs($this->admin)
            ->post(route('super-admin.projects.phases.override', $this->project->project_id))
            ->assertRedirect();

        $this->assertFalse(
            app(ProjectPhaseRules::class)->canOverrideStructure($this->admin, $this->project)
        );
        $this->assertTrue($this->project->fresh()->phasesAreFinalized());
    }

    public function test_the_override_reopens_setup_and_is_recorded(): void
    {
        $this->finalize();

        // Nothing is submitted with it: the dialog confirms in a sentence, and
        // what is recorded is who unlocked it and when.
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.override', $this->project->project_id))
            ->assertRedirect(route('super-admin.projects.phases.setup', $this->project->project_id));

        $project = $this->project->fresh();

        $this->assertTrue($project->needsPhaseSetup());
        $this->assertTrue($project->phaseStructureWasOverridden());
        $this->assertSame($this->superAdmin->id, $project->phase_structure_overridden_by);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::PROJECT_PHASE_STRUCTURE_OVERRIDDEN,
            'record_id' => $project->project_id,
        ]);
    }

    /**
     * The confirmation is the whole of it - there is nothing to fill in, and
     * an empty field submitted by an old form does not stop the unlock.
     */
    public function test_the_override_asks_for_nothing_beyond_the_confirmation(): void
    {
        $this->finalize();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.override', $this->project->project_id), [
                'reason' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue($this->project->fresh()->needsPhaseSetup());
        $this->assertNull($this->project->fresh()->phase_structure_override_reason);
    }

    /**
     * Never silently orphan tasks: a phase holding work does not simply
     * disappear, and its tasks are not deleted with it.
     */
    public function test_removing_a_phase_with_tasks_is_refused_without_a_destination(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $this->task($phases[1]);

        $this->unlock();

        $keep = $phases->reject(fn (ProjectPhase $phase): bool => $phase->phase_id === $phases[1]->phase_id);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => $this->rowsFor($keep),
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('tbl_project_phases', ['phase_id' => $phases[1]->phase_id]);
        $this->assertSame(1, Task::count());
    }

    public function test_a_removed_phase_hands_its_tasks_to_the_phase_named_for_them(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $task = $this->task($phases[1]);

        $this->unlock();

        $keep = $phases->reject(fn (ProjectPhase $phase): bool => $phase->phase_id === $phases[1]->phase_id);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => $this->rowsFor($keep),
                'reassign' => [$phases[1]->phase_id => $phases[0]->phase_id],
            ])
            ->assertRedirect();

        $project = $this->project->fresh();

        $this->assertTrue($project->phasesAreFinalized());
        $this->assertSame(3, $project->phase_count);
        $this->assertDatabaseMissing('tbl_project_phases', ['phase_id' => $phases[1]->phase_id]);

        // The task moved rather than going with the phase.
        $this->assertSame(1, Task::count());
        $this->assertSame((int) $phases[0]->phase_id, (int) $task->fresh()->phase_id);
    }

    /**
     * Reordering renumbers every phase without the tasks on them noticing.
     */
    public function test_the_super_admin_can_reorder_phases_and_the_tasks_stay_put(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $task = $this->task($phases[3]);

        $this->unlock();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => $this->rowsFor($phases->reverse()->values()),
            ])
            ->assertRedirect();

        $reordered = $this->project->fresh()->phases()->get();

        $this->assertSame([1, 2, 3, 4], $reordered->pluck('sequence')->all());
        $this->assertSame(
            ['Final Inspection', 'Testing', 'Installation', 'Site Preparation'],
            $reordered->pluck('title')->all()
        );

        // Same phase row, new number - so the work is still filed under the
        // stage it belongs to.
        $this->assertSame((int) $phases[3]->phase_id, (int) $task->fresh()->phase_id);
        $this->assertSame(1, $task->fresh()->phase->sequence);
    }

    // ------------------------------------------------------------------
    // Tasks
    // ------------------------------------------------------------------

    public function test_a_project_awaiting_phase_setup_refuses_new_tasks(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.task.store', $this->project->project_id), [
                'task_title' => 'Wire the panel',
                'task_description' => 'Description',
                'technician_id' => $this->mate->technician_id,
                'start_date' => $this->day(11),
                'due_date' => $this->day(12),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Task::count());
    }

    public function test_the_task_form_endpoint_sends_the_reader_to_phase_setup(): void
    {
        $this->actingAs($this->superAdmin)
            ->getJson(route('super-admin.projects.task-form-data', $this->project->project_id))
            ->assertStatus(422)
            ->assertJsonFragment([
                'error' => 'This project has not been configured with its project phases yet. Set up the project phases before adding tasks.',
            ]);
    }

    public function test_a_task_cannot_be_created_without_a_phase_once_setup_is_done(): void
    {
        $this->finalize();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.task.store', $this->project->project_id), [
                'task_title' => 'Wire the panel',
                'task_description' => 'Description',
                'technician_id' => $this->mate->technician_id,
                'start_date' => $this->day(11),
                'due_date' => $this->day(12),
            ])
            ->assertSessionHasErrors('phase_id');

        $this->assertSame(0, Task::count());
    }

    public function test_a_task_cannot_be_filed_under_another_project_s_phase(): void
    {
        $this->finalize();

        $other = Project::create([
            'name' => 'Somebody Else',
            'reference_no' => 'REF-PHASE-3',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);
        $foreign = $this->finalizePhases($other)->first();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.task.store', $this->project->project_id), [
                'task_title' => 'Wire the panel',
                'task_description' => 'Description',
                'phase_id' => $foreign->phase_id,
                'technician_id' => $this->mate->technician_id,
                'start_date' => $this->day(11),
                'due_date' => $this->day(12),
            ])
            ->assertSessionHasErrors('phase_id');

        $this->assertSame(0, Task::count());
    }

    public function test_a_task_created_with_a_phase_is_filed_under_it(): void
    {
        $this->finalize();
        $phase = $this->project->phases()->get()[1];

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.task.store', $this->project->project_id), [
                'task_title' => 'Wire the panel',
                'task_description' => 'Description',
                'phase_id' => $phase->phase_id,
                'technician_id' => $this->mate->technician_id,
                'start_date' => $this->day(11),
                'due_date' => $this->day(12),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_tasks', [
            'project_id' => $this->project->project_id,
            'phase_id' => $phase->phase_id,
        ]);
    }

    // ------------------------------------------------------------------
    // Monitoring and completion
    // ------------------------------------------------------------------

    public function test_the_progress_figure_counts_completed_phases_against_the_finalized_total(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();

        $phases[0]->forceFill(['completed_at' => now()])->save();
        $phases[1]->forceFill(['completed_at' => now()])->save();

        $summary = app(ProjectPhaseProgress::class)->summary($this->project->fresh());

        $this->assertSame('2/4 Phases', $summary['label']);
        $this->assertSame(50, $summary['percent']);
        $this->assertSame((int) $phases[2]->phase_id, (int) $summary['currentPhaseId']);
        $this->assertSame('In Progress', $summary['phases'][2]['label']);
        $this->assertSame('Not Started', $summary['phases'][3]['label']);
    }

    public function test_a_phase_with_open_tasks_cannot_be_completed(): void
    {
        $this->finalize();
        $phase = $this->project->phases()->get()->first();
        $this->task($phase);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.complete', [
                'project' => $this->project->project_id,
                'phase' => $phase->phase_id,
            ]))
            ->assertSessionHas('error');

        $this->assertNull($phase->fresh()->completed_at);
    }

    public function test_a_phase_completes_once_every_task_on_it_is_done(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $task = $this->task($phases[0]);
        $task->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->actingAs($this->leadAccount)
            ->post(route('technician.projects.phases.complete', [
                'project' => $this->project->project_id,
                'phase' => $phases[0]->phase_id,
            ]))
            ->assertRedirect();

        $this->assertNotNull($phases[0]->fresh()->completed_at);
        $this->assertSame($this->leadAccount->id, $phases[0]->fresh()->completed_by);

        // And the project has moved on to the next stage.
        $this->assertSame(
            (int) $phases[1]->phase_id,
            (int) app(ProjectPhaseProgress::class)->currentPhase($this->project->fresh())->phase_id
        );
    }

    /**
     * Phases close in order, so the count is a position in the job rather than
     * a tally of finished stages.
     */
    public function test_a_later_phase_cannot_be_completed_before_the_current_one(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.complete', [
                'project' => $this->project->project_id,
                'phase' => $phases[2]->phase_id,
            ]))
            ->assertSessionHas('error');

        $this->assertNull($phases[2]->fresh()->completed_at);
    }

    public function test_a_lead_cannot_override_a_phase_with_work_outstanding(): void
    {
        $this->finalize();
        $phase = $this->project->phases()->get()->first();
        $this->task($phase);

        $this->actingAs($this->leadAccount)
            ->post(route('technician.projects.phases.complete', [
                'project' => $this->project->project_id,
                'phase' => $phase->phase_id,
            ]), ['override' => 1])
            ->assertForbidden();

        $this->assertNull($phase->fresh()->completed_at);
    }

    public function test_a_super_admin_can_override_a_phase_and_it_is_recorded_as_one(): void
    {
        $this->finalize();
        $phase = $this->project->phases()->get()->first();
        $this->task($phase);

        // A confirmed decision rather than a written reason - who took it and
        // when is what gets recorded.
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.complete', [
                'project' => $this->project->project_id,
                'phase' => $phase->phase_id,
            ]), ['override' => 1])
            ->assertRedirect();

        $phase = $phase->fresh();

        $this->assertNotNull($phase->completed_at);
        $this->assertTrue($phase->completionWasOverridden());
        $this->assertSame($this->superAdmin->id, $phase->completion_overridden_by);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::PROJECT_PHASE_COMPLETION_OVERRIDDEN,
        ]);
    }

    /**
     * The flag only waives what actually needed waiving. A phase whose work is
     * finished closes normally however the request arrived, so the audit trail
     * is not filled with overrides that overrode nothing.
     */
    public function test_the_override_flag_on_a_phase_that_was_ready_is_not_recorded_as_an_override(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $task = $this->task($phases[0]);
        $task->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.complete', [
                'project' => $this->project->project_id,
                'phase' => $phases[0]->phase_id,
            ]), ['override' => 1])
            ->assertRedirect();

        $this->assertFalse($phases[0]->fresh()->completionWasOverridden());
    }

    // ------------------------------------------------------------------
    // A completed phase takes no more work
    // ------------------------------------------------------------------

    public function test_a_task_cannot_be_created_on_a_completed_phase(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $phases[0]->forceFill(['completed_at' => now()])->save();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.task.store', $this->project->project_id), [
                'task_title' => 'Wire the panel',
                'task_description' => 'Description',
                'phase_id' => $phases[0]->phase_id,
                'technician_id' => $this->mate->technician_id,
                'start_date' => $this->day(11),
                'due_date' => $this->day(12),
            ])
            ->assertSessionHasErrors('phase_id');

        $this->assertSame(0, Task::count());
    }

    public function test_a_completed_phase_is_not_offered_when_filing_new_work(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $phases[0]->forceFill(['completed_at' => now()])->save();

        $offered = app(ProjectPhaseProgress::class)
            ->selectablePhases($this->project->fresh())
            ->pluck('phase_id')
            ->all();

        $this->assertNotContains((int) $phases[0]->phase_id, $offered);
        $this->assertContains((int) $phases[1]->phase_id, $offered);

        // And the create dialog draws from exactly that list.
        $this->actingAs($this->superAdmin)
            ->getJson(route('super-admin.projects.task-form-data', $this->project->project_id))
            ->assertOk()
            ->assertJsonMissing(['phase_id' => $phases[0]->phase_id])
            ->assertJsonFragment(['phase_id' => $phases[1]->phase_id]);
    }

    /**
     * The exception, and only for editing: a task already on a phase that has
     * since closed can still have its wording and dates corrected without
     * being moved to another stage first.
     */
    public function test_a_task_already_on_a_completed_phase_stays_editable(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $task = $this->task($phases[0]);
        $phases[0]->forceFill(['completed_at' => now()])->save();

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.tasks.update', $task->task_id), [
                'task_title' => 'Wire the panel properly',
                'task_description' => 'Description',
                'phase_id' => $phases[0]->phase_id,
                'technician_id' => $this->mate->technician_id,
                'start_date' => $this->day(11),
                'due_date' => $this->day(12),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Wire the panel properly', $task->fresh()->task_title);
        $this->assertSame((int) $phases[0]->phase_id, (int) $task->fresh()->phase_id);
    }

    /**
     * Moving a task ONTO a closed phase is still refused - the allowance above
     * is about staying put, not about filing new work there.
     */
    public function test_a_task_cannot_be_moved_onto_a_completed_phase(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $task = $this->task($phases[1]);
        $phases[0]->forceFill(['completed_at' => now()])->save();

        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.tasks.update', $task->task_id), [
                'task_title' => $task->task_title,
                'task_description' => 'Description',
                'phase_id' => $phases[0]->phase_id,
                'technician_id' => $this->mate->technician_id,
                'start_date' => $this->day(11),
                'due_date' => $this->day(12),
            ])
            ->assertSessionHasErrors('phase_id');

        $this->assertSame((int) $phases[1]->phase_id, (int) $task->fresh()->phase_id);
    }

    public function test_a_project_whose_every_phase_is_complete_takes_no_new_tasks(): void
    {
        $this->finalize();

        foreach ($this->project->phases()->get() as $phase) {
            $phase->forceFill(['completed_at' => now()])->save();
        }

        $this->actingAs($this->superAdmin)
            ->getJson(route('super-admin.projects.task-form-data', $this->project->project_id))
            ->assertStatus(422)
            ->assertJsonFragment([
                'error' => 'Every phase of this project has been completed, so there is no open phase to add a task to.',
            ]);
    }

    // ------------------------------------------------------------------
    // Project completion
    // ------------------------------------------------------------------

    /**
     * Completing the project closes the phases nobody got to tick off.
     *
     * Completion already requires every task on the project to be closed, so
     * nothing here is outstanding except the click - and the click had become
     * impossible: requesting completion makes the project read-only, which is
     * what phase actions are refused on. Before this the last phase froze open
     * at the exact moment the lead finished the job.
     */
    public function test_completing_a_project_closes_the_phases_left_open(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();

        // A finished job: one task, on the first phase, closed.
        $task = $this->task($phases[0]);
        $task->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        // The lead ticks the first phase off themselves and completes.
        $this->actingAs($this->leadAccount)->post(
            route('technician.projects.phases.complete', [
                'project' => $this->project->project_id,
                'phase' => $phases[0]->phase_id,
            ])
        )->assertRedirect();

        $this->completeProject();

        $project = $this->project->fresh();

        $this->assertSame(
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            $project->status
        );
        $this->assertSame(['completed' => 4, 'total' => 4], $project->phaseProgress());

        // The one the lead closed keeps their name on it; the three the
        // completion closed name nobody.
        $after = $project->phases()->get();

        $this->assertFalse($after[0]->wasClosedWithProject());
        $this->assertSame($this->leadAccount->id, $after[0]->completed_by);

        foreach ([1, 2, 3] as $index) {
            $this->assertTrue($after[$index]->wasClosedWithProject());
            $this->assertNull($after[$index]->completed_by);
        }
    }

    /**
     * The panel says which of the two happened rather than crediting whoever
     * finished the project with a decision they never took.
     */
    public function test_an_auto_closed_phase_says_so_on_the_panel(): void
    {
        $this->finalize();
        $task = $this->task($this->project->phases()->get()->first());
        $task->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->completeProject();

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Closed when the project was completed');
    }

    /**
     * A finished project is not working through anything.
     */
    public function test_a_finished_project_shows_no_current_phase(): void
    {
        $this->finalize();
        $task = $this->task($this->project->phases()->get()->first());
        $task->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->completeProject();

        $summary = app(ProjectPhaseProgress::class)->summary($this->project->fresh());

        $this->assertNull($summary['currentPhaseId']);
        $this->assertEmpty($summary['phases']->where('isCurrent', true));
    }

    /**
     * Cancelled work stopped rather than finished, so its phases are not
     * closed - but nor is one of them badged as being worked on.
     */
    public function test_a_cancelled_project_reads_not_completed_rather_than_in_progress(): void
    {
        $this->finalize();

        $this->project->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        $summary = app(ProjectPhaseProgress::class)->summary($this->project->fresh());

        $this->assertNull($summary['currentPhaseId']);
        $this->assertSame('Not Completed', $summary['phases'][0]['label']);
        $this->assertSame(['completed' => 0, 'total' => 4], $this->project->fresh()->phaseProgress());
    }

    // ------------------------------------------------------------------
    // Reopening
    // ------------------------------------------------------------------

    /**
     * Reopening adds a phase for the new work rather than reopening the old
     * ones.
     *
     * Those stages really were finished; this is a fault found afterwards, and
     * it is work of its own. The count going 4/4 -> 4/5 is the point: a
     * finished project now has more to do, and the figure says so without
     * rewriting what was true.
     */
    public function test_reopening_adds_a_phase_for_the_new_work(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();

        $task = $this->task($phases[0]);
        $task->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        // The lead closes the first phase by hand before finishing.
        $this->actingAs($this->leadAccount)->post(
            route('technician.projects.phases.complete', [
                'project' => $this->project->project_id,
                'phase' => $phases[0]->phase_id,
            ])
        )->assertRedirect();

        $this->completeProject();
        $this->assertSame(['completed' => 4, 'total' => 4], $this->project->fresh()->phaseProgress());

        $this->reopenProject();

        $project = $this->project->fresh();

        $this->assertSame('ongoing', $project->status);
        $this->assertSame(['completed' => 4, 'total' => 5], $project->phaseProgress());

        // The original four are untouched - the record of what was finished
        // stays true, including who closed which.
        $after = $project->phases()->get();

        $this->assertCount(5, $after);
        $this->assertSame($this->leadAccount->id, $after[0]->completed_by);

        foreach ([1, 2, 3] as $index) {
            $this->assertTrue($after[$index]->isCompleted());
            $this->assertTrue($after[$index]->wasClosedWithProject());
        }

        // And the new one is open, last, and the phase the project is now on.
        $new = $after[4];

        $this->assertSame(ProjectReopen::REOPEN_PHASE_TITLE, $new->title);
        $this->assertSame(5, $new->sequence);
        $this->assertFalse($new->isCompleted());
        $this->assertSame((int) $new->phase_id, (int) $project->currentPhase()->phase_id);

        // The denominator moves with it, or the panel would report 4/4 on a
        // project with five phases.
        $this->assertSame(5, $project->phase_count);
    }

    /**
     * Reopened twice, two phases - each one is its own piece of extra work.
     */
    public function test_a_second_reopen_adds_a_second_phase(): void
    {
        $this->finalize();
        $task = $this->task($this->project->phases()->get()->first());
        $task->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->completeProject();
        $this->reopenProject();

        // Close the new phase's work off and finish again.
        $second = $this->task($this->project->fresh()->currentPhase(), 21, 22);
        $second->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->completeProject();
        $this->reopenProject();

        $project = $this->project->fresh();

        $this->assertSame(6, $project->phases()->count());
        $this->assertSame(6, $project->phase_count);
        $this->assertSame(['completed' => 5, 'total' => 6], $project->phaseProgress());
    }

    /**
     * The reason the restore exists: a reopened project has to be able to take
     * the work it was reopened for.
     */
    public function test_a_reopened_project_can_take_tasks_again(): void
    {
        $this->finalize();
        $task = $this->task($this->project->phases()->get()->first());
        $task->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->completeProject();
        $this->reopenProject();

        $project = $this->project->fresh();

        $this->assertNull(app(TaskPhaseRules::class)->blockReason($project));
        $this->assertSame(ProjectReopen::REOPEN_PHASE_TITLE, $project->currentPhase()->title);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.task.store', $project->project_id), [
                'task_title' => 'Put the panel back',
                'task_description' => 'Description',
                // The phase the reopen added. The original four are closed,
                // and filing new work under one of those is refused - which is
                // the whole reason the reopen adds one.
                'phase_id' => $project->currentPhase()->phase_id,
                'technician_id' => $this->mate->technician_id,
                // Inside the dates the reopen booked, not the ones the
                // original job ran on - those were released when it completed.
                'start_date' => $this->day(21),
                'due_date' => $this->day(22),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Task::count());
    }

    // ------------------------------------------------------------------
    // Urgent Actions
    // ------------------------------------------------------------------

    public function test_the_dashboard_reports_a_project_awaiting_phase_setup(): void
    {
        $this->actingAs($this->superAdmin);

        $action = $this->urgentAction('phase_setup_required');

        $this->assertNotNull($action);
        $this->assertSame(1, $action['count']);
        $this->assertSame('Set Up Phases', $action['action']);
        // One project, so the link is the setup screen itself rather than a
        // list to search.
        $this->assertSame(
            route('super-admin.projects.phases.setup', $this->project->project_id),
            $action['url']
        );
    }

    public function test_the_dashboard_stops_reporting_it_once_the_phases_are_finalized(): void
    {
        $this->finalize();

        $this->actingAs($this->superAdmin);

        $this->assertNull($this->urgentAction('phase_setup_required'));
    }

    /**
     * Not a tab: the row itself.
     *
     * A tab is only clicked by somebody who already suspects there is
     * something in it. This is a state a person should notice while scanning
     * the table they are already looking at, so it is drawn the way ACTIVE
     * TODAY is - a tint, an edge and a pill on the row.
     */
    public function test_the_projects_table_highlights_the_row_rather_than_offering_a_tab(): void
    {
        $this->assertArrayNotHasKey('phase_setup', Project::ATTENTION_TABS);
        $this->assertNotContains('phase_setup', $this->project->attentionTabKeys());

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects'))
            ->assertOk()
            ->assertSee('project-row-needs-phase-setup')
            ->assertSee('PHASE SETUP REQUIRED');

        $this->finalize();

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects'))
            ->assertOk()
            ->assertDontSee('project-row-needs-phase-setup')
            ->assertDontSee('PHASE SETUP REQUIRED');
    }

    /**
     * The lead's own table says it the same way, from the same component.
     */
    public function test_the_technician_table_highlights_the_row_too(): void
    {
        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects'))
            ->assertOk()
            ->assertSee('project-row-needs-phase-setup')
            ->assertSee('PHASE SETUP REQUIRED');
    }

    // ------------------------------------------------------------------
    // Phase progress on a projects table
    // ------------------------------------------------------------------

    /**
     * How far through a project is, on the row, for everybody who can see it.
     */
    public function test_the_projects_table_shows_the_phase_count(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $phases[0]->forceFill(['completed_at' => now()])->save();
        $phases[1]->forceFill(['completed_at' => now()])->save();
        $phases[2]->forceFill(['completed_at' => now()])->save();

        foreach ([
            [$this->superAdmin, 'super-admin.projects'],
            [$this->admin, 'super-admin.projects'],
            [$this->leadAccount, 'technician.projects'],
            [$this->mate->account, 'technician.projects'],
        ] as [$viewer, $route]) {
            $this->actingAs($viewer)
                ->get(route($route))
                ->assertOk()
                ->assertSee('project-phase-chip')
                ->assertSee('3/4');
        }
    }

    /**
     * The client sees it too - "all users" includes the person whose job it
     * is. Their My Projects page is a card grid rather than a table, and the
     * chip is the same component.
     */
    public function test_the_client_card_names_the_current_phase_and_draws_a_bar(): void
    {
        $this->finalize();
        $this->project->phases()->get()->first()->forceFill(['completed_at' => now()])->save();

        $response = $this->actingAs($this->clientFor($this->project))
            ->get(route('public.projects'))
            ->assertOk();

        // The stage by name, not just a count - and the bar that says the same
        // thing without being read.
        $response->assertSee('Phase 2/4: Installation');
        $response->assertSee('project-phase-line-bar', false);
        $response->assertSee('width: 25%', false);

        // Compact on a card: the phase's description belongs on the project's
        // own page, where there is room for it.
        $response->assertSee('is-compact', false);
        $response->assertDontSee('project-phase-line-detail', false);
    }

    /**
     * The booked dates came off the card when the phase line went on.
     *
     * They said when somebody was coming, which the project's own page still
     * gives in full; what a client asks between visits is how far along the
     * job is, and the card answers that instead.
     */
    public function test_the_client_card_no_longer_prints_the_timeline(): void
    {
        $this->finalize();

        $client = $this->clientFor($this->project);

        $this->actingAs($client)
            ->get(route('public.projects'))
            ->assertOk()
            ->assertDontSee('Timeline:');

        // Still there on the project itself.
        $this->actingAs($client)
            ->get(route('public.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Project Schedule');
    }

    /**
     * The grid draws one of these per card, so the phases have to arrive with
     * the projects rather than a query at a time.
     */
    public function test_the_client_grid_loads_its_phases_eagerly(): void
    {
        $this->finalize();

        $client = $this->clientFor($this->project);

        $projects = app(ClientProjects::class)->forUser($client);

        $this->assertTrue($projects->first()->relationLoaded('phases'));
        $this->assertCount(4, $projects->first()->phases);
    }

    /**
     * On the client's own project page: one line, above the reports, and not
     * the phase panel the staff portals draw.
     */
    public function test_the_client_project_page_names_the_current_phase(): void
    {
        $this->finalize();
        $phases = $this->project->phases()->get();
        $phases[0]->forceFill(['completed_at' => now()])->save();
        $phases[1]->forceFill(['completed_at' => now()])->save();

        $response = $this->actingAs($this->clientFor($this->project))
            ->get(route('public.projects.show', $this->project->project_id))
            ->assertOk();

        $response->assertSee('Phase 3/4: Testing');
        $response->assertSeeInOrder(['Phase 3/4: Testing', 'Technician Reports'], false);

        // The bar carries the same figure the words do: two of four closed.
        $response->assertSee('project-phase-line-bar', false);
        $response->assertSee('width: 50%', false);

        // The full form here, unlike the card: there is room for the phase's
        // own description on the project's own page.
        $response->assertSee('Test the completed installation.');

        // A client is following the project, not running it: no phase cards,
        // no task counts, no Complete Phase button.
        $response->assertDontSee('project-phase-card', false);
        $response->assertDontSee('Complete Phase');
    }

    public function test_the_client_is_told_when_every_phase_is_finished(): void
    {
        $this->finalize();

        foreach ($this->project->phases()->get() as $phase) {
            $phase->forceFill(['completed_at' => now()])->save();
        }

        $this->actingAs($this->clientFor($this->project))
            ->get(route('public.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('All 4 phases complete')
            ->assertDontSee('Phase 5/4');
    }

    /**
     * A project still in setup shows the client nothing: which stage a job is
     * at is worth telling them, and "nobody has configured this yet" is the
     * company's own housekeeping.
     */
    public function test_the_client_sees_no_phase_line_before_setup_is_finalized(): void
    {
        $this->actingAs($this->clientFor($this->project))
            ->get(route('public.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertDontSee('project-phase-line', false)
            ->assertDontSee('Phase Setup Required');
    }

    /**
     * Read from the counts the listing loads rather than queried per row.
     */
    public function test_the_phase_count_comes_from_the_eager_loaded_figures(): void
    {
        $this->finalize();
        $this->project->phases()->get()->first()->forceFill(['completed_at' => now()])->save();

        $row = Project::query()
            ->withCount([
                'phases',
                'phases as completed_phases_count' => fn ($query) => $query->whereNotNull('completed_at'),
            ])
            ->find($this->project->project_id);

        $this->assertSame(['completed' => 1, 'total' => 4], $row->phaseProgress());
    }

    public function test_a_project_still_in_setup_has_no_phase_count_to_show(): void
    {
        $this->assertNull($this->project->phaseProgress());
    }

    /**
     * A lead has no dashboard, so their copy of this lives on My Projects.
     */
    public function test_a_lead_is_told_on_my_projects(): void
    {
        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects'))
            ->assertOk()
            ->assertSee('Project Phase Setup Required')
            ->assertSee('Set Up Phases');
    }

    public function test_a_plain_technician_is_not_offered_the_setup_action(): void
    {
        $this->actingAs($this->mate->account)
            ->get(route('technician.projects'))
            ->assertOk()
            ->assertDontSee('Set Up Phases');
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * Close the project out the way a person does - through the endpoint, so
     * these tests rest on the real action rather than on a direct write.
     */
    private function completeProject(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.complete', $this->project->project_id), [
                'completion_date' => now()->toDateString(),
                'completion_summary' => 'The work is finished and the site is clear.',
            ])
            ->assertSessionHasNoErrors();

        $this->project = $this->project->fresh();
    }

    private function reopenProject(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.reopen', $this->project->project_id), [
                'reopen_reason' => 'The client reported a fault with the new unit.',
                'scheduling_mode' => Schedule::MODE_DATE_BASED,
                'start_date' => $this->day(21),
                'end_date' => $this->day(23),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->project = $this->project->fresh();
    }

    /**
     * A registered client account that owns the given project, for the two
     * public pages.
     */
    private function clientFor(Project $project): User
    {
        $account = $this->account('client', 'owner.of.'.$project->project_id.'@example.test');
        $account->forceFill($this->acceptedTerms())->save();

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Harbour Holdings',
            'firstname' => 'Client',
            'surname' => 'Person',
            'fullname' => 'Client Person',
            'email_address' => $account->email,
            'contact_number' => '09123456789',
        ]);

        return $account;
    }

    private function account(string $role, string $email): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test Person '.$sequence,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }

    private function assign(Technician $technician): ProjectTechnician
    {
        return ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    private function book(int $from, int $to): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => $this->day($from).' 08:00:00',
            'end_datetime' => $this->day($to).' 17:00:00',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        foreach ($this->project->projectTechnicians()->get() as $assignment) {
            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        }

        return $schedule;
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    /**
     * The four-phase structure the specification uses as its example.
     *
     * @return array<int, array{title: string, description: string}>
     */
    private function structure(int $count = 4): array
    {
        $rows = array_slice(ProjectPhase::SUGGESTED_PHASES, 0, $count);

        // Anything past the four suggested ones is made up, so a test asking
        // for five really gets five - which is the point when it is checking
        // that a locked structure refuses to grow.
        for ($extra = count($rows) + 1; $extra <= $count; $extra++) {
            $rows[] = [
                'title' => 'Extra Phase '.$extra,
                'description' => 'An extra stage.',
            ];
        }

        return $rows;
    }

    /**
     * Existing phases as submittable rows - which is what carries their
     * phase_id, and therefore what keeps the tasks on them attached.
     *
     * @param  Collection<int, ProjectPhase>  $phases
     * @return array<int, array{phase_id: int, title: string, description: string}>
     */
    private function rowsFor($phases): array
    {
        return $phases->map(fn (ProjectPhase $phase): array => [
            'phase_id' => $phase->phase_id,
            'title' => $phase->title,
            'description' => $phase->description,
        ])->values()->all();
    }

    /**
     * Put the project into State 2 the way a person does - through the
     * endpoint, so these tests rest on the real action rather than on a direct
     * write.
     */
    private function finalize(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => $this->structure(),
            ])
            ->assertRedirect();

        $this->project = $this->project->fresh();
    }

    private function unlock(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.override', $this->project->project_id))
            ->assertRedirect();

        $this->project = $this->project->fresh();
    }

    private function task(ProjectPhase $phase, int $startOffset = 11, int $dueOffset = 12): Task
    {
        return Task::create([
            'project_id' => $this->project->project_id,
            'phase_id' => $phase->phase_id,
            'technician_id' => $this->mate->technician_id,
            'task_title' => 'Task on '.$phase->label(),
            'task_description' => 'Description',
            'start_date' => $this->day($startOffset),
            'due_date' => $this->day($dueOffset),
            'status' => 'pending',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function urgentAction(string $key): ?array
    {
        foreach (app(DashboardMetrics::class)->urgentActions() as $action) {
            if ($action['key'] === $key) {
                return $action;
            }
        }

        return null;
    }
}
