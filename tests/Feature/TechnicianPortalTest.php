<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The lead technician portal: what a lead can see and do, and - just as
 * importantly - what they cannot.
 *
 * A lead runs the task board and the report log for the projects they are
 * assigned to. They are not an administrator: another project's board, a
 * technician who is not on the project, and anything that edits the project
 * record itself are all out of reach.
 */
class TechnicianPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $leadAccount;

    private Technician $lead;

    private Technician $mate;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->project = $this->project('Aircon Retrofit', 'REF-LEAD-1');
        $this->assign($this->project, $this->lead);
        $this->assign($this->project, $this->mate);
        $this->schedule($this->project, 10, 20);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

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

    private function project(string $name, string $reference, string $status = 'ongoing'): Project
    {
        return Project::create([
            'name' => $name,
            'reference_no' => $reference,
            'status' => $status,
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
    }

    private function assign(Project $project, Technician $technician): ProjectTechnician
    {
        return ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    private function schedule(Project $project, int $from, int $to): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day($from).' 00:00:00',
            'end_datetime' => $this->day($to).' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        foreach ($project->projectTechnicians()->get() as $assignment) {
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

    private function task(Technician $technician, string $title, string $status = 'pending'): Task
    {
        return Task::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $technician->technician_id,
            'task_title' => $title,
            'task_description' => 'Do the thing',
            'start_date' => $this->day(11),
            'due_date' => $this->day(12),
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayload(array $overrides = []): array
    {
        return array_merge([
            'task_title' => 'Install condenser',
            'task_description' => 'Mount and wire the outdoor unit.',
            'technician_id' => $this->mate->technician_id,
            'start_date' => $this->day(11),
            'due_date' => $this->day(13),
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    /**
     * Both roles get the same portal. What differs is what may be done on it,
     * not which pages exist.
     */
    public function test_both_technician_roles_get_the_calendar_schedule(): void
    {
        foreach ([$this->leadAccount, $this->mate->account] as $account) {
            $this->actingAs($account);
            $page = $this->get(route('technician.schedule'));

            $page->assertOk();
            $page->assertSee('technicianCalendar', false);
            $page->assertSee('Assigned Tasks');
        }
    }

    /**
     * One card per project, one filter above them, and a single New Task
     * button that asks which project first.
     */
    public function test_the_task_page_groups_every_project_task_not_only_the_leads_own(): void
    {
        $this->task($this->mate, 'Mate task');
        $this->task($this->lead, 'Lead task');

        $this->actingAs($this->leadAccount);
        $response = $this->get(route('technician.tasks'));

        $response->assertOk();
        $response->assertSee('Mate task');
        $response->assertSee('Lead task');
        $response->assertSee('New Task');

        // The card layout and its filter.
        $response->assertSee('data-project-card', false);
        $response->assertSee('data-project-filter', false);
        $response->assertSee('All Projects');

        // Exactly one create dialog, asking for the project up front.
        $this->assertSame(1, substr_count($response->getContent(), 'data-task-create-modal'));
        $response->assertSee('Choose the project first');
    }

    /**
     * A finished project has no board left to run, so its card drops off the
     * task page - while My Projects keeps it, under the Completed tab.
     */
    public function test_the_task_page_drops_completed_projects(): void
    {
        $this->task($this->mate, 'Task on the live project');

        $finished = $this->project('Finished Job', 'REF-DONE', 'completed');
        $this->assign($finished, $this->lead);

        Task::create([
            'project_id' => $finished->project_id,
            'technician_id' => $this->mate->technician_id,
            'task_title' => 'Task on the finished project',
            'task_description' => 'Do the thing',
            'status' => 'completed',
        ]);

        $this->actingAs($this->leadAccount);

        $tasksPage = $this->get(route('technician.tasks'));
        $tasksPage->assertOk();
        $tasksPage->assertSee('Task on the live project');
        $tasksPage->assertDontSee('REF-DONE');
        $tasksPage->assertDontSee('Task on the finished project');

        // My Projects still lists it, and offers the tab to find it by.
        $projectsPage = $this->get(route('technician.projects'));
        $projectsPage->assertOk();
        $projectsPage->assertSee('REF-DONE');
        $projectsPage->assertSee('data-status-filter="completed"', false);
    }

    /**
     * The status tabs need cancelled work on the page to filter to, so My
     * Projects is the one page in the portal that keeps it.
     */
    public function test_my_projects_carries_the_status_tabs_and_cancelled_work(): void
    {
        $cancelled = $this->project('Called Off', 'REF-CANCELLED', 'cancelled');
        $this->assign($cancelled, $this->lead);

        $this->actingAs($this->leadAccount);
        $response = $this->get(route('technician.projects'));

        $response->assertOk();
        $response->assertSee('REF-CANCELLED');

        foreach (['all', 'pending', 'ongoing', 'overdue', 'completed', 'cancelled'] as $tab) {
            $response->assertSee('data-status-filter="'.$tab.'"', false);
        }

        // Rows carry what the tabs filter on.
        $response->assertSee('data-status="cancelled"', false);
        $response->assertSee('data-overdue=', false);
    }

    /**
     * The project view is a page of its own, not a modal, carrying the same
     * information the Super Admin project details page shows.
     */
    public function test_the_project_page_shows_the_super_admin_details_view_only(): void
    {
        $this->task($this->mate, 'Mate task');

        $this->actingAs($this->leadAccount);
        $response = $this->get(route('technician.projects.show', $this->project));

        $response->assertOk();
        $response->assertSee('Project Details');
        // The heading is the client, exactly as the Super Admin page has it.
        $response->assertSee('REF-LEAD-1');
        $response->assertSee('Assigned Team');
        $response->assertSee('Project Schedule');
        $response->assertSee('Project Activity');
        $response->assertSee('Mate task');

        // None of the controls that edit the project record itself.
        $response->assertDontSee('Edit Project Details');
        $response->assertDontSee('Edit Assigned Team');
        $response->assertDontSee('Update Schedule');
        $response->assertDontSee('Cancel Project');
    }

    public function test_the_project_page_is_closed_to_an_unassigned_lead(): void
    {
        $other = $this->project('Someone Elses Job', 'REF-OTHER');

        $this->actingAs($this->leadAccount);

        $this->get(route('technician.projects.show', $other))->assertForbidden();
    }

    /**
     * A completed task keeps its eye icon, and what opens shows the notes and
     * photos the technician submitted.
     */
    public function test_a_completed_task_shows_its_completion_details(): void
    {
        $task = $this->task($this->lead, 'Finished task', 'completed');
        $task->update([
            'completion_notes' => 'Coil cleaned and refrigerant topped up.',
            'completed_at' => now(),
        ]);
        $task->images()->create(['image_path' => 'task-completions/proof.jpg']);

        $this->actingAs($this->leadAccount);

        foreach ([
            route('technician.tasks'),
            route('technician.projects.show', $this->project),
        ] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('Completion Details');
            $response->assertSee('Coil cleaned and refrigerant topped up.');
            $response->assertSee('task-completions/proof.jpg');
            // View only: a completed task offers no save button.
            $response->assertDontSee('Edit Task');
        }
    }

    public function test_the_reports_page_lists_only_this_leads_own_reports(): void
    {
        TechnicianReport::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->lead->technician_id,
            'report_type' => 'progress',
            'report_title' => 'Mine',
            'report_description' => 'Description',
            'report_date' => now()->toDateString(),
        ]);

        TechnicianReport::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->mate->technician_id,
            'report_type' => 'progress',
            'report_title' => 'Someone elses',
            'report_description' => 'Description',
            'report_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->leadAccount);
        $response = $this->get(route('technician.reports'));

        $response->assertOk();
        $response->assertSee('Mine');
        $response->assertDontSee('Someone elses');
    }

    // ------------------------------------------------------------------
    // Reading a project
    // ------------------------------------------------------------------

    public function test_project_details_returns_the_whole_board_and_the_leads_permissions(): void
    {
        $this->task($this->mate, 'Mate task');
        $this->task($this->lead, 'Lead task');

        $this->actingAs($this->leadAccount);
        $response = $this->getJson(route('technician.projects.details', $this->project));

        $response->assertOk();
        $response->assertJsonCount(2, 'tasks');
        $response->assertJsonPath('project.reference_no', 'REF-LEAD-1');
        $response->assertJsonPath('permissions.manage_tasks', true);
        $response->assertJsonPath('permissions.submit_report', true);
        $response->assertJsonCount(2, 'task_form.technicians');
    }

    public function test_mine_only_narrows_the_task_list_to_the_leads_own_work(): void
    {
        $this->task($this->mate, 'Mate task');
        $this->task($this->lead, 'Lead task');

        $this->actingAs($this->leadAccount);
        $response = $this->getJson(
            route('technician.projects.details', $this->project).'?mine_only=1'
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'tasks');
        $response->assertJsonPath('tasks.0.title', 'Lead task');
        $response->assertJsonPath('tasks.0.can_complete', true);
    }

    /**
     * The schedule panel renders straight from this payload, so the completion
     * photos have to travel with it - the notes alone left the panel showing a
     * description with no picture.
     */
    public function test_the_project_payload_carries_completion_notes_and_photos(): void
    {
        $task = $this->task($this->lead, 'Finished task', 'completed');
        $task->update([
            'completion_notes' => 'Belt replaced.',
            'completed_at' => now(),
        ]);
        $task->images()->create(['image_path' => 'task-completions/panel-proof.jpg']);

        $this->actingAs($this->leadAccount);

        $response = $this->getJson(
            route('technician.projects.details', $this->project).'?mine_only=1'
        );

        $response->assertOk();
        $response->assertJsonPath('tasks.0.completion_notes', 'Belt replaced.');
        $response->assertJsonCount(1, 'tasks.0.images');
        $this->assertStringContainsString(
            'task-completions/panel-proof.jpg',
            $response->json('tasks.0.images.0.url')
        );
    }

    public function test_a_lead_cannot_read_a_project_they_are_not_assigned_to(): void
    {
        $other = $this->project('Someone Elses Job', 'REF-OTHER');

        $this->actingAs($this->leadAccount);

        $this->getJson(route('technician.projects.details', $other))
            ->assertForbidden();
    }

    /**
     * A technician reads their own project - that is the whole portal for
     * them - but every action that changes it stays with the lead.
     */
    public function test_a_plain_technician_reads_a_project_but_cannot_act_on_it(): void
    {
        $task = $this->task($this->mate, 'Mate task');

        $this->actingAs($this->mate->account);

        // Reading is fine: the page, and the payload behind the schedule panel.
        $this->get(route('technician.projects.show', $this->project))->assertOk();
        $this->getJson(route('technician.projects.details', $this->project))->assertOk();

        // Everything that writes is out of reach.
        $this->postJson(route('technician.tasks.store', $this->project), $this->taskPayload())
            ->assertForbidden();
        $this->postJson(route('technician.tasks.update', $task), $this->taskPayload())
            ->assertForbidden();
        $this->deleteJson(route('technician.tasks.destroy', $task))
            ->assertForbidden();
        $this->postJson(route('technician.projects.complete', $this->project), [])
            ->assertForbidden();
        $this->postJson(route('technician.reports.store', $this->project), [])
            ->assertForbidden();
        $this->get(route('technician.reports'))
            ->assertRedirect(route('technician.schedule'));
    }

    /**
     * The one thing a technician may do: close their own task.
     */
    public function test_a_plain_technician_can_complete_their_own_task(): void
    {
        $mine = $this->task($this->mate, 'Mine to finish');
        $theirs = $this->task($this->lead, 'Not mine');

        $this->actingAs($this->mate->account);

        $this->post(route('technician.tasks.complete', $mine), [
            'completion_notes' => 'Fitted and tested.',
        ])->assertRedirect();

        $this->assertSame('completed', $mine->refresh()->status);

        $this->postJson(route('technician.tasks.complete', $theirs), [
            'completion_notes' => 'Not mine to close.',
        ])->assertForbidden();

        $this->assertSame('pending', $theirs->refresh()->status);
    }

    /**
     * The pages render without any of the lead's controls.
     */
    public function test_a_plain_technician_sees_a_view_only_portal(): void
    {
        $this->task($this->mate, 'Mate task');

        $this->actingAs($this->mate->account);

        $tasks = $this->get(route('technician.tasks'));
        $tasks->assertOk();
        $tasks->assertSee('Mate task');
        $tasks->assertSee('data-project-card', false);
        $tasks->assertDontSee('New Task');
        $tasks->assertDontSee('data-task-create-modal', false);
        $tasks->assertDontSee('Delete task');
        $tasks->assertDontSee('View / edit task');
        // Their own task still offers the one action they have.
        $tasks->assertSee('Mark as completed');

        $projects = $this->get(route('technician.projects'));
        $projects->assertOk();
        $projects->assertSee('data-status-filter="all"', false);
        $projects->assertDontSee('Complete project');

        $details = $this->get(route('technician.projects.show', $this->project));
        $details->assertOk();
        $details->assertDontSee('Complete Project');
        $details->assertDontSee('Assign New Task');
        $details->assertDontSee('Add Report');
    }

    // ------------------------------------------------------------------
    // Creating tasks
    // ------------------------------------------------------------------

    public function test_a_lead_can_assign_a_task_to_a_technician_on_the_project(): void
    {
        $this->actingAs($this->leadAccount);

        $response = $this->postJson(
            route('technician.tasks.store', $this->project),
            $this->taskPayload()
        );

        $response->assertCreated();
        $response->assertJsonPath('task.technician_id', $this->mate->technician_id);

        $this->assertSame(1, Task::count());
        $this->assertSame('pending', Task::first()->status);
    }

    /**
     * The Assign New Task dialog on the project page is a plain Blade form,
     * so the same endpoint has to answer a normal post with a redirect.
     */
    public function test_assigning_a_task_from_the_project_page_redirects_back(): void
    {
        $this->actingAs($this->leadAccount);

        $response = $this->from(route('technician.projects.show', $this->project))
            ->post(route('technician.tasks.store', $this->project), $this->taskPayload());

        $response->assertRedirect(route('technician.projects.show', $this->project));
        $response->assertSessionHas('success');

        $this->assertSame(1, Task::count());
    }

    public function test_editing_a_task_from_the_project_page_redirects_back(): void
    {
        $task = $this->task($this->mate, 'Original title');

        $this->actingAs($this->leadAccount);

        $response = $this->from(route('technician.tasks'))
            ->post(route('technician.tasks.update', $task), $this->taskPayload([
                'task_title' => 'Renamed task',
            ]));

        $response->assertRedirect(route('technician.tasks'));
        $response->assertSessionHas('success');

        $this->assertSame('Renamed task', $task->refresh()->task_title);
    }

    public function test_a_technician_who_is_not_on_the_project_cannot_be_given_a_task(): void
    {
        $outsiderAccount = $this->account('technician', 'outsider@example.test');
        $outsider = Technician::create([
            'account_id' => $outsiderAccount->id,
            'role' => 'technician',
        ]);

        $this->actingAs($this->leadAccount);

        $this->postJson(
            route('technician.tasks.store', $this->project),
            $this->taskPayload(['technician_id' => $outsider->technician_id])
        )->assertStatus(422);

        $this->assertSame(0, Task::count());
    }

    /**
     * The same rule the Super Admin task form enforces, now shared through
     * TaskScheduleRules rather than copied.
     */
    public function test_task_dates_have_to_sit_inside_the_project_schedule(): void
    {
        $this->actingAs($this->leadAccount);

        $this->postJson(
            route('technician.tasks.store', $this->project),
            $this->taskPayload(['start_date' => $this->day(25), 'due_date' => $this->day(26)])
        )->assertStatus(422);

        $this->assertSame(0, Task::count());
    }

    public function test_an_identical_open_task_is_rejected_as_a_duplicate(): void
    {
        $this->actingAs($this->leadAccount);

        $this->postJson(
            route('technician.tasks.store', $this->project),
            $this->taskPayload()
        )->assertCreated();

        $this->postJson(
            route('technician.tasks.store', $this->project),
            $this->taskPayload()
        )->assertStatus(422);

        $this->assertSame(1, Task::count());
    }

    public function test_a_lead_cannot_add_tasks_to_a_completed_project(): void
    {
        $this->project->update(['status' => 'completed']);

        $this->actingAs($this->leadAccount);

        $this->postJson(
            route('technician.tasks.store', $this->project),
            $this->taskPayload()
        )->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Completing tasks
    // ------------------------------------------------------------------

    /**
     * The dialog is a plain Blade form shared with the Super Admin portal, so
     * a normal post lands back on the page with a flash message.
     */
    public function test_completing_a_task_stores_the_notes_and_the_photo(): void
    {
        Storage::fake('public');

        $task = $this->task($this->lead, 'Lead task');

        $this->actingAs($this->leadAccount);

        $response = $this->from(route('technician.tasks'))
            ->post(route('technician.tasks.complete', $task), [
                'completion_notes' => 'Unit mounted and tested.',
                'images' => [UploadedFile::fake()->image('done.jpg')],
            ]);

        $response->assertRedirect(route('technician.tasks'));
        $response->assertSessionHas('success');

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame('Unit mounted and tested.', $task->completion_notes);
        $this->assertNotNull($task->completed_at);
        $this->assertCount(1, $task->images);
        Storage::disk('public')->assertExists($task->images->first()->image_path);
    }

    public function test_completing_a_task_without_an_image_is_allowed(): void
    {
        $task = $this->task($this->lead, 'Lead task');

        $this->actingAs($this->leadAccount);

        $this->post(route('technician.tasks.complete', $task), [
            'completion_notes' => 'Nothing to photograph.',
        ])->assertRedirect();

        $this->assertSame('completed', $task->refresh()->status);
    }

    /**
     * The schedule panel calls the same endpoint over fetch, because a reload
     * would throw away the project it has open.
     */
    public function test_completing_a_task_over_ajax_answers_with_the_updated_task(): void
    {
        $task = $this->task($this->lead, 'Lead task');

        $this->actingAs($this->leadAccount);

        $response = $this->postJson(route('technician.tasks.complete', $task), [
            'completion_notes' => 'Closed from the schedule panel.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('task.status', 'completed');
        $response->assertJsonPath('task.completion_notes', 'Closed from the schedule panel.');
    }

    public function test_completion_notes_are_required(): void
    {
        $task = $this->task($this->lead, 'Lead task');

        $this->actingAs($this->leadAccount);

        $this->postJson(route('technician.tasks.complete', $task), [])
            ->assertStatus(422);

        $this->assertSame('pending', $task->refresh()->status);
    }

    /**
     * A lead closes work that is finished on site but never marked. They did
     * not do it, so nothing is demanded of them - the record instead says who
     * closed it.
     */
    public function test_a_lead_may_close_somebody_elses_task_without_any_details(): void
    {
        $task = $this->task($this->mate, 'Mate task');

        $this->actingAs($this->leadAccount);

        $this->postJson(route('technician.tasks.complete', $task), [])
            ->assertOk();

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertNull($task->completion_notes);
        $this->assertCount(0, $task->images);
        $this->assertSame($this->leadAccount->id, (int) $task->completed_by);
        $this->assertTrue($task->wasClosedOnBehalf());
    }

    /**
     * Closing your own task still asks what was done - the exemption is only
     * for someone closing it on your behalf.
     */
    public function test_a_lead_closing_their_own_task_still_needs_notes(): void
    {
        $task = $this->task($this->lead, 'Lead task');

        $this->actingAs($this->leadAccount);

        $this->postJson(route('technician.tasks.complete', $task), [])
            ->assertStatus(422);

        $this->assertSame('pending', $task->refresh()->status);
    }

    public function test_a_lead_cannot_close_a_task_on_a_project_they_are_not_on(): void
    {
        $other = $this->project('Someone Elses Job', 'REF-OTHER');
        $this->assign($other, $this->mate);

        $task = Task::create([
            'project_id' => $other->project_id,
            'technician_id' => $this->mate->technician_id,
            'task_title' => 'Not their business',
            'task_description' => 'Do the thing',
            'status' => 'pending',
        ]);

        $this->actingAs($this->leadAccount);

        $this->postJson(route('technician.tasks.complete', $task), [])
            ->assertForbidden();

        $this->assertSame('pending', $task->refresh()->status);
    }

    // ------------------------------------------------------------------
    // Deleting tasks
    // ------------------------------------------------------------------

    public function test_a_lead_can_delete_a_task_on_a_project_they_run(): void
    {
        $task = $this->task($this->mate, 'Raised in error');

        $this->actingAs($this->leadAccount);

        $this->from(route('technician.tasks'))
            ->delete(route('technician.tasks.destroy', $task))
            ->assertRedirect(route('technician.tasks'))
            ->assertSessionHas('success');

        $this->assertSame(0, Task::count());
    }

    /**
     * A task raised in error is still an error once somebody ticks it off, so
     * a completed one can go too - and its photos go with it rather than being
     * left orphaned on disk.
     */
    public function test_deleting_a_completed_task_takes_its_photos_with_it(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('task-completions/proof.jpg', 'x');

        $task = $this->task($this->mate, 'Done then deleted', 'completed');
        $task->images()->create(['image_path' => 'task-completions/proof.jpg']);

        $this->actingAs($this->leadAccount);

        $this->delete(route('technician.tasks.destroy', $task))->assertRedirect();

        $this->assertSame(0, Task::count());
        $this->assertDatabaseCount('tbl_task_images', 0);
        Storage::disk('public')->assertMissing('task-completions/proof.jpg');
    }

    public function test_a_lead_cannot_delete_a_task_on_a_project_they_are_not_on(): void
    {
        $other = $this->project('Someone Elses Job', 'REF-OTHER');
        $this->assign($other, $this->mate);

        $task = Task::create([
            'project_id' => $other->project_id,
            'technician_id' => $this->mate->technician_id,
            'task_title' => 'Not theirs to remove',
            'task_description' => 'Do the thing',
            'status' => 'pending',
        ]);

        $this->actingAs($this->leadAccount);

        $this->deleteJson(route('technician.tasks.destroy', $task))->assertForbidden();

        $this->assertSame(1, Task::count());
    }

    public function test_a_plain_technician_cannot_delete_a_task(): void
    {
        $task = $this->task($this->mate, 'Mate task');

        $this->actingAs($this->mate->account);

        $this->deleteJson(route('technician.tasks.destroy', $task))->assertForbidden();

        $this->assertSame(1, Task::count());
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    public function test_a_report_is_always_filed_under_the_signed_in_lead(): void
    {
        Storage::fake('public');

        $this->actingAs($this->leadAccount);

        $response = $this->post(route('technician.reports.store', $this->project), [
            'report_type' => 'incident',
            'report_title' => 'Cable damaged',
            'report_description' => 'Found a damaged run behind the riser.',
            // Even when the form claims otherwise, the lead owns the report.
            'technician_id' => $this->mate->technician_id,
            'images' => [UploadedFile::fake()->image('damage.jpg')],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $report = TechnicianReport::first();
        $this->assertSame($this->lead->technician_id, $report->technician_id);
        $this->assertSame('incident', $report->report_type);
        $this->assertCount(1, $report->images);
    }

    /**
     * technician_id on tbl_technician_reports is a tbl_technicians id, not a
     * users id. The two spaces only overlap by accident, so this files a
     * report as a technician whose id is beyond every user id - which is what
     * the original foreign key rejected.
     */
    public function test_a_report_files_when_the_technician_id_is_not_also_a_user_id(): void
    {
        $account = $this->account('lead_technician', 'faraway@example.test');

        DB::table('tbl_technicians')->insert([
            'technician_id' => 9999,
            'account_id' => $account->id,
            'role' => 'lead_technician',
        ]);

        $this->assign($this->project, Technician::find(9999));
        $this->assertNull(User::find(9999));

        $this->actingAs($account);

        $this->postJson(route('technician.reports.store', $this->project), [
            'report_type' => 'progress',
            'report_title' => 'Filed from a high id',
            'report_description' => 'Description',
        ])->assertCreated();

        $this->assertSame(9999, (int) TechnicianReport::first()->technician_id);
    }

    public function test_a_completed_project_can_no_longer_receive_reports(): void
    {
        $this->project->update(['status' => 'completed']);

        $this->actingAs($this->leadAccount);

        $this->postJson(route('technician.reports.store', $this->project), [
            'report_type' => 'progress',
            'report_title' => 'Too late',
            'report_description' => 'Description',
        ])->assertForbidden();

        $this->assertSame(0, TechnicianReport::count());
    }

    // ------------------------------------------------------------------
    // Completing a project
    // ------------------------------------------------------------------

    public function test_a_project_with_open_tasks_cannot_be_completed(): void
    {
        $this->task($this->mate, 'Still open');

        $this->actingAs($this->leadAccount);

        $response = $this->postJson(
            route('technician.projects.complete', $this->project),
            []
        );

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'still open',
            implode(' ', $response->json('blockers'))
        );
        $this->assertSame('ongoing', $this->project->refresh()->status);
    }

    /**
     * The completion report a lead has to file: the same fields the Super
     * Admin modal collects, with the photographs required.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function completionPayload(array $overrides = []): array
    {
        return array_merge([
            'completion_date' => CarbonImmutable::today()->toDateString(),
            'completion_summary' => 'Everything on site is finished.',
            'completion_photos' => [UploadedFile::fake()->image('handover.jpg')],
        ], $overrides);
    }

    /**
     * Finishing ahead of schedule is a good outcome, not something to argue
     * with: the dates still to come are released rather than blocking the
     * completion.
     */
    public function test_completing_a_project_early_releases_its_future_dates(): void
    {
        $this->task($this->mate, 'Done', 'completed');

        // The fixture schedule runs from day +10 to +20, entirely ahead.
        $this->assertSame(1, $this->project->schedules()->count());

        Storage::fake('public');
        $this->actingAs($this->leadAccount);

        $this->post(
            route('technician.projects.complete', $this->project),
            $this->completionPayload()
        )->assertRedirect();

        $this->assertSame('completed', $this->project->refresh()->status);
        $this->assertSame(0, $this->project->schedules()->count());
    }

    /**
     * A range already under way records days the crew actually worked, so it
     * is kept and cut short rather than deleted outright.
     */
    public function test_a_range_already_running_is_trimmed_not_deleted(): void
    {
        $this->project->schedules()->delete();
        $this->schedule($this->project, -3, 20);
        $this->task($this->mate, 'Done', 'completed');

        $this->actingAs($this->leadAccount);

        $this->post(
            route('technician.projects.complete', $this->project),
            $this->completionPayload()
        )->assertRedirect();

        $schedules = $this->project->refresh()->schedules;

        $this->assertCount(1, $schedules);
        $this->assertSame(
            CarbonImmutable::today()->toDateString(),
            CarbonImmutable::parse($schedules->first()->end_datetime)->toDateString()
        );
        // The days already worked are untouched.
        $this->assertSame(
            $this->day(-3),
            CarbonImmutable::parse($schedules->first()->start_datetime)->toDateString()
        );
    }

    /**
     * A lead is on site, so the photographs are the evidence that the work is
     * done. Unlike the Super Admin form, they are not optional here.
     */
    public function test_completion_photos_are_required(): void
    {
        $this->project->schedules()->delete();
        $this->schedule($this->project, -10, -1);
        $this->task($this->mate, 'Done', 'completed');

        $this->actingAs($this->leadAccount);

        $response = $this->postJson(
            route('technician.projects.complete', $this->project),
            $this->completionPayload(['completion_photos' => []])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'At least one completion photo is required.');
        $this->assertSame('ongoing', $this->project->refresh()->status);
    }

    public function test_a_completion_summary_is_required(): void
    {
        $this->project->schedules()->delete();
        $this->schedule($this->project, -10, -1);
        $this->task($this->mate, 'Done', 'completed');

        $this->actingAs($this->leadAccount);

        $this->postJson(
            route('technician.projects.complete', $this->project),
            $this->completionPayload(['completion_summary' => ''])
        )->assertStatus(422);

        $this->assertSame('ongoing', $this->project->refresh()->status);
    }

    public function test_a_project_with_no_tasks_cannot_be_completed(): void
    {
        $this->project->schedules()->delete();
        $this->schedule($this->project, -10, -1);

        $this->actingAs($this->leadAccount);

        $response = $this->postJson(
            route('technician.projects.complete', $this->project),
            []
        );

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'no tasks',
            implode(' ', $response->json('blockers'))
        );
    }

    public function test_a_project_with_everything_done_can_be_completed(): void
    {
        $this->project->schedules()->delete();
        $this->schedule($this->project, -10, -1);
        $this->task($this->mate, 'Done', 'completed');

        $this->actingAs($this->leadAccount);

        $this->post(
            route('technician.projects.complete', $this->project),
            $this->completionPayload(['completion_remarks' => 'Client walked the site.'])
        )->assertRedirect();

        $this->project->refresh();
        $this->assertSame('completed', $this->project->status);
        $this->assertSame('Client walked the site.', $this->project->completion_remarks);
        $this->assertSame('Everything on site is finished.', $this->project->completion_summary);
        $this->assertCount(1, $this->project->completionPhotos);
    }

    public function test_a_lead_cannot_complete_a_project_they_are_not_on(): void
    {
        $other = $this->project('Someone Elses Job', 'REF-OTHER');

        $this->actingAs($this->leadAccount);

        $this->postJson(route('technician.projects.complete', $other), [])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Boundaries
    // ------------------------------------------------------------------

    /**
     * The portal grants no administrative reach: every Super Admin route
     * still turns a lead away.
     */
    public function test_a_lead_still_has_no_administrative_reach(): void
    {
        $this->actingAs($this->leadAccount);

        $this->get(route('super-admin.projects'))
            ->assertRedirect(route('technician.schedule'));

        $this->get(route('super-admin.configuration.index'))
            ->assertRedirect(route('technician.schedule'));

        $this->delete(route('super-admin.tasks.destroy', 1))
            ->assertRedirect(route('technician.schedule'));

        $this->put(route('super-admin.projects.team.update', $this->project), [])
            ->assertRedirect(route('technician.schedule'));
    }
}
