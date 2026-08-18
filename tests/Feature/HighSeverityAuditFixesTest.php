<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectTeamCandidates;
use App\Services\SystemReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The high-severity findings from the system audit, each pinned by the case
 * that found it.
 *
 * They are gathered here rather than scattered because they are one finding
 * wearing several hats: a rule enforced on one path and missing on another.
 * The technician portal checked that a task's owner was on the project and the
 * administrative board did not; the technician's own page refused a second
 * lead and the team editor did not; a lead technician could not close a
 * project with work outstanding and an administrator could, silently. Each
 * test below is the second path.
 */
class HighSeverityAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function technician(string $name, string $role = 'technician', array $accountAttributes = []): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => mb_strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        // array_merge, not `+`: the left operand wins on `+`, so an override
        // of `status` would be silently discarded.
        $user->forceFill(array_merge(
            ['role' => $role, 'status' => User::STATUS_ACTIVE],
            $accountAttributes
        ))->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }

    /**
     * @param  array<int, Technician>  $technicians
     */
    private function project(array $technicians = [], string $status = 'ongoing'): Project
    {
        $project = Project::create([
            'name' => 'Audit Project '.uniqid(),
            'reference_no' => 'REF-'.strtoupper(uniqid()),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Audit Holdings',
            'firstname' => 'Audit',
            'surname' => 'Client',
            'fullname' => 'Audit Client',
            'email_address' => 'audit.client@example.test',
            'contact_number' => '09123456789',
        ]);

        foreach ($technicians as $technician) {
            ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
            ]);
        }

        return $project->fresh();
    }

    private function book(Project $project, int $startOffset, int $endOffset): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => CarbonImmutable::today()->addDays($startOffset)->startOfDay(),
            'end_datetime' => CarbonImmutable::today()->addDays($endOffset)->endOfDay(),
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        ProjectTechnician::where('project_id', $project->project_id)
            ->pluck('project_technician_id')
            ->each(fn ($id) => ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $id,
            ]));

        return $schedule;
    }

    private function finishedWork(Project $project): Task
    {
        return Task::create([
            'project_id' => $project->project_id,
            'task_title' => 'Work that was carried out',
            'task_description' => 'Description',
            'status' => 'completed',
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function completionPayload(array $overrides = []): array
    {
        return array_merge([
            'completion_date' => CarbonImmutable::today()->toDateString(),
            'completion_summary' => 'The work on site is finished.',
        ], $overrides);
    }

    // ==================================================================
    // BUG-004 - a switched-off account cannot be given work
    // ==================================================================

    public function test_a_deactivated_technician_cannot_be_added_to_a_team(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $deactivated = $this->technician('Gone Away', 'technician', ['status' => User::STATUS_DEACTIVATED]);

        $project = $this->project([$lead]);
        $this->book($project, 0, 2);

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$deactivated->technician_id],
        ])->assertSessionHasErrors('technicians');

        $this->assertDatabaseMissing('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $deactivated->technician_id,
        ]);
    }

    public function test_an_archived_technician_cannot_be_added_to_a_team(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $archived = $this->technician('Long Gone', 'technician', ['is_archived' => true]);

        $project = $this->project([$lead]);
        $this->book($project, 0, 2);

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$archived->technician_id],
        ])->assertSessionHasErrors('technicians');
    }

    /**
     * Somebody already on the team stays there. Taking work off a technician
     * unassigns their tasks and leaves them for somebody else to pick up, so
     * it is a decision for a person - not a side effect of an account being
     * switched off, and certainly not something that should make the team form
     * impossible to save.
     */
    public function test_a_technician_already_on_the_team_survives_being_deactivated(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $member = $this->technician('Still Here');

        $project = $this->project([$lead, $member]);
        $this->book($project, 0, 2);

        $member->account->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$member->technician_id],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $member->technician_id,
        ]);
    }

    public function test_the_picker_marks_a_deactivated_technician_unselectable(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $deactivated = $this->technician('Gone Away', 'technician', ['status' => User::STATUS_DEACTIVATED]);
        $free = $this->technician('Perfectly Fine');

        $project = $this->project([$lead]);
        $this->book($project, 0, 2);

        $candidates = app(ProjectTeamCandidates::class)
            ->forProject(Project::with(['projectTechnicians', 'schedules', 'projectTypes'])->find($project->project_id))
            ->keyBy('id');

        $this->assertFalse($candidates[$deactivated->technician_id]['available']);
        $this->assertFalse($candidates[$deactivated->technician_id]['selectable']);
        $this->assertSame('Account is no longer active', $candidates[$deactivated->technician_id]['reason']);

        // And the guard is narrow: an ordinary technician is still offered.
        $this->assertTrue($candidates[$free->technician_id]['available']);
        $this->assertTrue($candidates[$free->technician_id]['selectable']);
        $this->assertSame('', $candidates[$free->technician_id]['reason']);
    }

    public function test_the_technicians_page_refuses_to_assign_a_deactivated_account(): void
    {
        $deactivated = $this->technician('Gone Away', 'technician', ['status' => User::STATUS_DEACTIVATED]);
        $project = $this->project([], 'unscheduled');

        $this->postJson(route('super-admin.technicians.projects.store', $deactivated->technician_id), [
            'project_ids' => [$project->project_id],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('tbl_project_technicians', [
            'technician_id' => $deactivated->technician_id,
        ]);
    }

    // ==================================================================
    // BUG-005 / BUG-006 - one lead, and a real one
    // ==================================================================

    public function test_a_plain_technician_cannot_be_saved_as_the_lead(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $plain = $this->technician('Plain Person');

        $project = $this->project([$lead]);
        $this->book($project, 0, 2);

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $plain->technician_id,
            'technicians' => [],
        ])->assertSessionHasErrors('lead_tech');

        // The real lead is still on the project rather than quietly dropped.
        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
        ]);
    }

    public function test_a_second_lead_technician_cannot_join_the_team(): void
    {
        $lead = $this->technician('First Lead', 'lead_technician');
        $otherLead = $this->technician('Second Lead', 'lead_technician');

        $project = $this->project([$lead]);
        $this->book($project, 0, 2);

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$otherLead->technician_id],
        ])->assertSessionHasErrors('technicians');

        $leads = ProjectTechnician::with('technician.account')
            ->where('project_id', $project->project_id)
            ->get()
            ->filter(fn (ProjectTechnician $assignment): bool => (bool) $assignment->technician?->isLead())
            ->count();

        $this->assertSame(1, $leads);
    }

    public function test_the_wizard_refuses_a_team_with_no_real_lead(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $plainA = $this->technician('Plain One');
        $plainB = $this->technician('Plain Two');

        $this->post(route('super-admin.projects.create.store'), [
            'client_type' => 'Commercial',
            'company_name' => 'Wizard Co',
            'firstname' => 'Wiz', 'surname' => 'Ard',
            'client_email' => 'wizard@example.test',
            'client_phone' => '09123456789',
            'project_address' => 'Address',
            'project_description' => 'Description',
            'quotation_amount' => 1000,
            'project_types' => ['Aircon Installation'],
            'lead_tech' => $plainA->technician_id,
            'technicians' => [$plainB->technician_id],
            'start_date' => CarbonImmutable::today()->addDays(2)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(4)->toDateString(),
            'assessment_report' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
            'approved_quotation' => [UploadedFile::fake()->create('q.pdf', 10, 'application/pdf')],
            'contract' => [UploadedFile::fake()->create('c.pdf', 10, 'application/pdf')],
        ])->assertSessionHasErrors('lead_tech');

        $this->assertSame(0, Project::where('name', 'Wizard Co')->count());
    }

    // ==================================================================
    // BUG-007 - an override, stated and recorded
    // ==================================================================

    public function test_an_administrator_cannot_silently_complete_a_project_with_open_tasks(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $project = $this->project([$lead]);
        $this->book($project, 0, 4);

        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'task_title' => 'Still to do',
            'task_description' => 'Description',
            'status' => 'pending',
        ]);

        $this->post(route('super-admin.projects.complete', $project->project_id), $this->completionPayload())
            ->assertSessionHas('error');

        $this->assertSame('ongoing', $project->fresh()->status);
    }

    public function test_an_administrator_may_complete_it_anyway_by_saying_why(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $project = $this->project([$lead]);
        $this->book($project, 0, 4);

        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'task_title' => 'Still to do',
            'task_description' => 'Description',
            'status' => 'pending',
        ]);

        $this->post(route('super-admin.projects.complete', $project->project_id), $this->completionPayload([
            'completion_override_reason' => 'Client signed off on site; the remaining task was cancelled by them.',
        ]))->assertSessionHas('success');

        $project->refresh();

        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->status);
        $this->assertTrue($project->completionWasOverridden());
        $this->assertStringContainsString('Client signed off on site', $project->completion_override_reason);
        $this->assertNotNull($project->completion_overridden_by);

        // What the rules objected to is kept as it read at the time, because
        // the task it names may later be finished or deleted.
        $this->assertNotEmpty($project->completion_override_blockers);
        $this->assertStringContainsString('task is still open', implode(' ', $project->completion_override_blockers));
    }

    public function test_an_override_is_written_to_the_activity_log_and_told_to_the_administrators(): void
    {
        $other = User::create([
            'user_code' => 'EMP-0500', 'name' => 'Other Admin', 'first_name' => 'Other',
            'last_name' => 'Admin', 'email' => 'other.admin@example.test', 'role' => 'admin',
            'status' => User::STATUS_ACTIVE, 'password' => 'secret-password',
        ]);

        $lead = $this->technician('Real Lead', 'lead_technician');
        $project = $this->project([$lead]);
        $this->book($project, 0, 4);

        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'task_title' => 'Still to do',
            'task_description' => 'Description',
            'status' => 'pending',
        ]);

        $this->post(route('super-admin.projects.complete', $project->project_id), $this->completionPayload([
            'completion_override_reason' => 'Client signed off on site; the remaining task was cancelled by them.',
        ]))->assertSessionHas('success');

        $entry = ActivityLog::where('action', ActivityLog::PROJECT_COMPLETION_OVERRIDDEN)->first();

        $this->assertNotNull($entry, 'An override writes an entry of its own.');
        $this->assertStringContainsString('task is still open', (string) $entry->description);
        $this->assertStringContainsString('Client signed off on site', (string) $entry->description);

        $this->assertContains(
            'Project Completed Over Its Blockers',
            Notification::forUser($other)->get()->pluck('title')->all()
        );
    }

    /**
     * The reason belongs to the completion it was given for. A project
     * reopened and completed again cleanly must not still be carrying last
     * time's override.
     */
    public function test_completing_cleanly_records_no_override(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $project = $this->project([$lead]);
        $this->book($project, 0, 4);
        $this->finishedWork($project);

        $this->post(route('super-admin.projects.complete', $project->project_id), $this->completionPayload([
            // Sent even though nothing objects - there is nothing to override,
            // so nothing is recorded as overridden.
            'completion_override_reason' => 'Not needed, and should not be stored.',
        ]))->assertSessionHas('success');

        $project->refresh();

        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->status);
        $this->assertFalse($project->completionWasOverridden());
        $this->assertNull($project->completion_overridden_by);
        $this->assertSame(0, ActivityLog::where('action', ActivityLog::PROJECT_COMPLETION_OVERRIDDEN)->count());
    }

    public function test_the_completion_dialog_shows_what_it_is_being_asked_to_override(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $project = $this->project([$lead]);
        $this->book($project, 0, 4);

        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'task_title' => 'Still to do',
            'task_description' => 'Description',
            'status' => 'pending',
        ]);

        $response = $this->get(route('super-admin.projects'));

        $response->assertOk();
        $response->assertSee('This project is not ready to be completed');
        $response->assertSee('1 task is still open. Every task has to be completed first.');
        $response->assertSee('completion_override_reason', false);
    }

    // ==================================================================
    // BUG-008 - cancelled work is not scheduled time
    // ==================================================================

    public function test_the_schedule_report_leaves_cancelled_work_out(): void
    {
        $technician = $this->technician('Report Tech');

        $live = $this->project([$technician]);
        $live->update(['reference_no' => 'REF-LIVE']);
        $this->book($live, 0, 2);

        $cancelled = $this->project([$this->technician('Cancelled Tech')]);
        $cancelled->update(['reference_no' => 'REF-CANCELLED', 'status' => 'cancelled']);
        $this->book($cancelled, 0, 10);

        $period = app(SystemReportService::class)->resolveExportPeriod(
            'monthly',
            (int) CarbonImmutable::today()->format('n'),
            (int) CarbonImmutable::today()->format('Y')
        );

        $section = app(SystemReportService::class)->exportReport('schedule', $period)['sections'][0];
        $references = collect($section['rows'])->pluck('reference_no')->all();

        $this->assertContains('REF-LIVE', $references);
        $this->assertNotContains('REF-CANCELLED', $references);

        // And the totals follow the rows rather than counting days nobody
        // worked.
        $summary = collect($section['summary'])->pluck('value', 'label');
        $this->assertSame('1', $summary['Total Scheduled Projects']);
        $this->assertSame('3', $summary['Total Scheduled Days']);
    }

    /**
     * Cancelled work is still in the Project Report, which is about the work
     * rather than about the calendar - so this is a narrowing of one report,
     * not of reporting.
     */
    public function test_the_project_report_still_lists_cancelled_work(): void
    {
        $cancelled = $this->project([$this->technician('Cancelled Tech')]);
        $cancelled->update(['reference_no' => 'REF-CANCELLED', 'status' => 'cancelled']);
        $this->book($cancelled, 0, 10);

        $period = app(SystemReportService::class)->resolveExportPeriod(
            'monthly',
            (int) CarbonImmutable::today()->format('n'),
            (int) CarbonImmutable::today()->format('Y')
        );

        $rows = app(SystemReportService::class)->exportReport('project', $period)['sections'][0]['rows'];

        $this->assertContains('REF-CANCELLED', collect($rows)->pluck('reference_no')->all());
    }

    // ==================================================================
    // BUG-009 - a switched-off account loses access everywhere
    // ==================================================================

    public function test_a_deactivated_client_loses_the_public_project_pages_mid_session(): void
    {
        $project = $this->project([$this->technician('Client Tech')]);

        Client::where('project_id', $project->project_id)
            ->update(['email_address' => 'live.client@example.test']);

        $client = User::factory()->create(['name' => 'Live Client', 'email' => 'live.client@example.test']);
        $client->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE])->save();

        $this->actingAs($client);
        $this->get('/my-projects')->assertOk();

        $client->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        $this->get('/my-projects')->assertRedirect(route('auth.login'));
        $this->assertGuest();
    }

    public function test_a_deactivated_client_cannot_confirm_a_completed_project(): void
    {
        $project = $this->project([$this->technician('Confirm Tech')]);

        Client::where('project_id', $project->project_id)
            ->update(['email_address' => 'confirming@example.test']);

        $project->update([
            'status' => Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            'completed_at' => CarbonImmutable::today(),
            'completion_summary' => 'Done.',
            'completion_requested_at' => CarbonImmutable::now(),
        ]);

        $client = User::factory()->create(['name' => 'Confirming Client', 'email' => 'confirming@example.test']);
        $client->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_DEACTIVATED])->save();

        $this->actingAs($client);
        $this->post(route('public.projects.confirm', $project->project_id))
            ->assertRedirect(route('auth.login'));

        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->fresh()->status);
    }

    public function test_a_deactivated_account_loses_profile_and_notifications_too(): void
    {
        $client = User::factory()->create(['name' => 'Profile Client', 'email' => 'profile.client@example.test']);
        $client->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE])->save();

        $this->actingAs($client);
        $this->get(route('profile.edit'))->assertOk();

        $client->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        $this->get(route('profile.edit'))->assertRedirect(route('auth.login'));

        $this->actingAs($client->fresh());
        $this->get(route('notifications.index'))->assertRedirect(route('auth.login'));
    }

    /**
     * A guest is not an inactive account, and the sign-in page still has to be
     * reachable - otherwise the fix locks everybody out instead of one person.
     */
    public function test_guests_and_active_accounts_are_untouched(): void
    {
        $this->post(route('auth.logout'));

        $this->get('/')->assertOk();
        $this->get(route('auth.login'))->assertOk();
        $this->get('/my-projects')->assertOk();
    }

    // ==================================================================
    // BUG-010 - a task belongs to somebody on the project
    // ==================================================================

    public function test_the_administrative_board_refuses_a_technician_who_is_not_on_the_project(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $outsider = $this->technician('Not Involved');

        $project = $this->project([$lead]);
        $this->book($project, 0, 4);

        $this->post(route('super-admin.task.store', $project->project_id), [
            'task_title' => 'Wire the panel',
            'task_description' => 'Description',
            'technician_id' => $outsider->technician_id,
            'start_date' => CarbonImmutable::today()->toDateString(),
            'due_date' => CarbonImmutable::today()->addDay()->toDateString(),
        ])->assertSessionHasErrors('technician_id');

        $this->assertSame(0, Task::where('project_id', $project->project_id)->count());
    }

    public function test_a_task_cannot_be_reassigned_off_the_project_either(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $outsider = $this->technician('Not Involved');

        $project = $this->project([$lead]);
        $this->book($project, 0, 4);

        $task = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'task_title' => 'Wire the panel',
            'task_description' => 'Description',
            'start_date' => CarbonImmutable::today()->toDateString(),
            'due_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'status' => 'pending',
        ]);

        $this->put(route('super-admin.tasks.update', $task->task_id), [
            'task_title' => 'Wire the panel',
            'task_description' => 'Description',
            'technician_id' => $outsider->technician_id,
            'start_date' => CarbonImmutable::today()->toDateString(),
            'due_date' => CarbonImmutable::today()->addDay()->toDateString(),
        ])->assertSessionHasErrors('technician_id');

        $this->assertSame((int) $lead->technician_id, (int) $task->fresh()->technician_id);
    }

    public function test_a_technician_on_the_project_is_still_accepted(): void
    {
        $lead = $this->technician('Real Lead', 'lead_technician');
        $member = $this->technician('On The Team');

        $project = $this->project([$lead, $member]);
        $this->book($project, 0, 4);

        $this->post(route('super-admin.task.store', $project->project_id), [
            'task_title' => 'Wire the panel',
            'task_description' => 'Description',
            'technician_id' => $member->technician_id,
            'start_date' => CarbonImmutable::today()->toDateString(),
            'due_date' => CarbonImmutable::today()->addDay()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_tasks', [
            'project_id' => $project->project_id,
            'technician_id' => $member->technician_id,
            'task_title' => 'Wire the panel',
        ]);
    }
}
