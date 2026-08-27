<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\TaskAssignmentGaps;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tasks that cannot proceed because they are incomplete.
 *
 * A task is stuck when nobody holds it, when it has no dates to be done
 * between, or when it has neither - and the three are deliberately told apart,
 * because sending somebody to assign a technician to a task that already has
 * one wastes their time.
 *
 * One rule decides it for both portals (Task::scopeNeedsAssignment), and each
 * portal applies its own permission filter on top: the Super Admin dashboard
 * over every project, a lead over the projects they are on. These pin both
 * halves of that - that the rule says the same thing to both, and that the
 * lead's half is genuinely narrowed.
 *
 * Nothing is stored. The alert is the current state of the task rows, so
 * filling in what is missing clears it on the next read.
 */
class UnassignedTaskAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Technician $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();

        $this->project = $this->project('Harbour Fit-Out');
        $this->technician = $this->technician('Ana Cruz');

        ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->technician->technician_id,
        ]);
    }

    // ------------------------------------------------------------------
    // The shared rule
    // ------------------------------------------------------------------

    public function test_a_task_with_no_technician_is_missing_a_technician(): void
    {
        $task = $this->task(['technician_id' => null, 'status' => 'unassigned']);

        $this->assertSame(Task::GAP_TECHNICIAN, $task->assignmentGap());
        $this->assertSame('Missing Technician', $task->assignmentGapLabel());
        $this->assertSame([$task->task_id], $this->needsAssignmentIds());
    }

    public function test_a_task_with_no_date_is_missing_a_date_not_a_technician(): void
    {
        // The case the old wording got wrong: this task HAS an owner, so
        // calling it "Unassigned" sends somebody to the wrong field.
        $task = $this->task(['due_date' => null]);

        $this->assertSame(Task::GAP_DATE, $task->assignmentGap());
        $this->assertSame('Missing Date', $task->assignmentGapLabel());
        $this->assertSame([$task->task_id], $this->needsAssignmentIds());
    }

    public function test_a_missing_start_date_counts_as_a_missing_date(): void
    {
        // A task is done BETWEEN two days, so either one missing leaves it
        // without a date to work to.
        $task = $this->task(['start_date' => null]);

        $this->assertSame(Task::GAP_DATE, $task->assignmentGap());
    }

    public function test_a_task_with_neither_is_missing_both(): void
    {
        $task = $this->task([
            'technician_id' => null,
            'start_date' => null,
            'due_date' => null,
            'status' => 'unassigned',
        ]);

        $this->assertSame(Task::GAP_BOTH, $task->assignmentGap());
        $this->assertSame('Missing Technician & Date', $task->assignmentGapLabel());
        $this->assertSame([$task->task_id], $this->needsAssignmentIds());
    }

    public function test_a_task_with_a_technician_and_dates_is_not_affected(): void
    {
        $task = $this->task();

        $this->assertNull($task->assignmentGap());
        $this->assertSame([], $this->needsAssignmentIds());
    }

    public function test_closed_work_is_not_a_backlog(): void
    {
        // A completed task that was never given dates is a record of work that
        // happened, not a job waiting to be arranged.
        $this->task(['due_date' => null, 'status' => 'completed']);
        $this->task(['technician_id' => null, 'status' => 'cancelled']);

        $this->assertSame([], $this->needsAssignmentIds());
    }

    public function test_work_nobody_is_allowed_to_fix_is_left_out(): void
    {
        // A held project refuses task edits until it resumes, so listing its
        // tasks would be an alert nobody can clear. Same for finished and
        // archived work.
        foreach ([
            ['status' => 'ongoing', 'on_hold' => true],
            ['status' => 'completed'],
            ['status' => 'ongoing', 'is_archived' => true],
        ] as $attributes) {
            $project = $this->project('Out Of Scope '.json_encode($attributes));
            $project->forceFill($attributes)->save();

            Task::create([
                'project_id' => $project->project_id,
                'technician_id' => null,
                'task_title' => 'Stranded',
                'task_description' => 'Description',
                'status' => 'unassigned',
            ]);
        }

        $this->assertSame([], $this->needsAssignmentIds());
    }

    public function test_the_three_gaps_are_counted_separately(): void
    {
        $this->task(['technician_id' => null, 'status' => 'unassigned']);
        $this->task(['technician_id' => null, 'status' => 'unassigned']);
        $this->task(['due_date' => null]);
        $this->task(['technician_id' => null, 'start_date' => null, 'due_date' => null, 'status' => 'unassigned']);
        // Not affected, so it must not reach any of the three.
        $this->task();

        $summary = app(TaskAssignmentGaps::class)->summarise();

        $this->assertSame(4, $summary['total']);
        $this->assertSame(2, $summary['counts'][Task::GAP_TECHNICIAN]);
        $this->assertSame(1, $summary['counts'][Task::GAP_DATE]);
        $this->assertSame(1, $summary['counts'][Task::GAP_BOTH]);

        $this->assertSame(
            ['1 task missing technician and date', '2 tasks missing technician', '1 task missing date'],
            array_column($summary['lines'], 'text')
        );
    }

    // ------------------------------------------------------------------
    // Super Admin
    // ------------------------------------------------------------------

    public function test_the_dashboard_raises_an_urgent_action(): void
    {
        $this->task(['technician_id' => null, 'status' => 'unassigned']);
        $this->task(['technician_id' => null, 'status' => 'unassigned']);
        $this->task(['due_date' => null]);
        $this->task(['technician_id' => null, 'start_date' => null, 'due_date' => null, 'status' => 'unassigned']);
        $this->task(['technician_id' => null, 'status' => 'unassigned']);

        $action = $this->urgentAction('unassigned_tasks');

        $this->assertNotNull($action, 'Unassigned tasks are missing from Urgent Actions.');
        $this->assertSame(5, $action['count']);
        $this->assertSame('Unassigned Tasks', $action['label']);
        $this->assertSame('5 tasks need a technician or date', $action['detail']);
        $this->assertStringContainsString('attention=all', $action['url']);
    }

    public function test_the_dashboard_says_nothing_when_every_task_is_complete(): void
    {
        $this->task();

        $this->assertNull($this->urgentAction('unassigned_tasks'));
    }

    public function test_the_urgent_action_reads_the_dashboard_page(): void
    {
        $this->task(['technician_id' => null, 'status' => 'unassigned']);

        $this->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('Unassigned Task')
            ->assertSee('1 task needs a technician or date')
            ->assertSee('attention=all', false);
    }

    public function test_the_super_admin_task_board_says_what_each_task_is_missing(): void
    {
        $this->task(['technician_id' => null, 'status' => 'unassigned']);
        $this->task(['due_date' => null]);
        $this->task(['technician_id' => null, 'start_date' => null, 'due_date' => null, 'status' => 'unassigned']);

        $response = $this->get(route('super-admin.tasks.index'))->assertOk();

        $response->assertSee('3 tasks need attention');
        $response->assertSee('Missing Technician');
        $response->assertSee('Missing Date');
        $response->assertSee('Missing Technician &amp; Date', false);
        // The chips the board filters on, and the row attribute they read.
        $response->assertSee('data-task-gap-chip="both"', false);
        $response->assertSee('data-task-gap="date"', false);
    }

    public function test_the_super_admin_board_draws_no_panel_when_nothing_is_stuck(): void
    {
        $this->task();

        $this->get(route('super-admin.tasks.index'))
            ->assertOk()
            ->assertDontSee('need attention')
            ->assertDontSee('data-task-attention', false);
    }

    // ------------------------------------------------------------------
    // Lead technician
    // ------------------------------------------------------------------

    public function test_a_lead_sees_the_alert_on_their_tasks_page(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);

        ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $lead->technician_id,
        ]);

        $this->task(['technician_id' => null, 'status' => 'unassigned']);
        $this->task(['technician_id' => null, 'status' => 'unassigned']);
        $this->task(['due_date' => null]);

        $response = $this->actingAs($lead->account)
            ->get(route('technician.tasks'))
            ->assertOk();

        $response->assertSee('3 tasks need attention');
        $response->assertSee('2 tasks missing technician');
        $response->assertSee('1 task missing date');
    }

    public function test_a_lead_is_not_told_about_another_teams_work(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);

        ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $lead->technician_id,
        ]);

        // One stuck task on their own project.
        $this->task(['technician_id' => null, 'status' => 'unassigned']);

        // Three on a project they are not on. The Super Admin figure counts
        // all four; this lead must be told about one.
        $elsewhere = $this->project('Somebody Else&rsquo;s Job');

        for ($i = 0; $i < 3; $i++) {
            Task::create([
                'project_id' => $elsewhere->project_id,
                'technician_id' => null,
                'task_title' => 'Not theirs '.$i,
                'task_description' => 'Description',
                'status' => 'unassigned',
            ]);
        }

        $this->assertSame(4, $this->urgentAction('unassigned_tasks')['count']);

        $this->actingAs($lead->account)
            ->get(route('technician.tasks'))
            ->assertOk()
            ->assertSee('1 task needs attention')
            ->assertDontSee('Not theirs 0');
    }

    public function test_a_lead_who_cannot_edit_the_project_is_told_who_can(): void
    {
        // manageTasks() needs a scheduled project, so a project with no
        // booking is one the lead may read but not run. The alert must still
        // name the problem without implying a control that is not there.
        $unscheduled = $this->project('Not Booked Yet', schedule: false);
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);

        ProjectTechnician::create([
            'project_id' => $unscheduled->project_id,
            'technician_id' => $lead->technician_id,
        ]);

        Task::create([
            'project_id' => $unscheduled->project_id,
            'technician_id' => null,
            'task_title' => 'Waiting on a booking',
            'task_description' => 'Description',
            'status' => 'unassigned',
        ]);

        $response = $this->actingAs($lead->account)
            ->get(route('technician.tasks'))
            ->assertOk();

        $response->assertSee('1 task needs attention');
        $response->assertSee('an administrator will need to fill in what is missing');
    }

    public function test_a_plain_technician_gets_no_alert(): void
    {
        // Their board is their own work, an unheld task is never on it, and
        // they may not assign anybody.
        $this->task(['technician_id' => $this->technician->technician_id, 'due_date' => null]);

        $this->actingAs($this->technician->account)
            ->get(route('technician.tasks'))
            ->assertOk()
            ->assertDontSee('need attention')
            ->assertDontSee('data-task-attention', false);
    }

    // ------------------------------------------------------------------
    // The alert clears itself
    // ------------------------------------------------------------------

    public function test_assigning_a_technician_clears_the_missing_technician_alert(): void
    {
        $task = $this->task(['technician_id' => null, 'status' => 'unassigned']);

        $this->assertSame(1, $this->summary()['counts'][Task::GAP_TECHNICIAN]);

        $task->update(['technician_id' => $this->technician->technician_id, 'status' => 'pending']);

        $summary = $this->summary();

        $this->assertSame(0, $summary['total']);
        $this->assertNull($task->fresh()->assignmentGap());
        $this->assertNull($this->urgentAction('unassigned_tasks'));
    }

    public function test_adding_a_date_clears_the_missing_date_alert(): void
    {
        $task = $this->task(['due_date' => null]);

        $this->assertSame(1, $this->summary()['counts'][Task::GAP_DATE]);

        $task->update(['due_date' => CarbonImmutable::today()->addDays(3)->toDateString()]);

        $this->assertSame(0, $this->summary()['total']);
        $this->assertNull($task->fresh()->assignmentGap());
    }

    public function test_fixing_one_field_leaves_the_other_gap_named_correctly(): void
    {
        // Missing both, then given an owner: it becomes Missing Date rather
        // than disappearing, which is the failure the labels exist to prevent.
        $task = $this->task([
            'technician_id' => null,
            'start_date' => null,
            'due_date' => null,
            'status' => 'unassigned',
        ]);

        $this->assertSame(1, $this->summary()['counts'][Task::GAP_BOTH]);

        $task->update(['technician_id' => $this->technician->technician_id, 'status' => 'pending']);

        $summary = $this->summary();

        $this->assertSame(1, $summary['total']);
        $this->assertSame(0, $summary['counts'][Task::GAP_BOTH]);
        $this->assertSame(1, $summary['counts'][Task::GAP_DATE]);
        $this->assertSame(Task::GAP_DATE, $task->fresh()->assignmentGap());
    }

    public function test_the_board_stops_mentioning_a_task_the_moment_it_is_fixed(): void
    {
        $task = $this->task(['technician_id' => null, 'status' => 'unassigned']);

        $this->get(route('super-admin.tasks.index'))
            ->assertOk()
            ->assertSee('1 task needs attention');

        // Through the endpoint the Edit Task dialog posts to, so this is the
        // real fix rather than a direct write.
        $this->put(route('super-admin.tasks.update', $task->task_id), [
            'task_title' => $task->task_title,
            'task_description' => $task->task_description,
            'technician_id' => $this->technician->technician_id,
            'start_date' => $task->start_date,
            'due_date' => $task->due_date,
        ])->assertRedirect();

        $this->get(route('super-admin.tasks.index'))
            ->assertOk()
            ->assertDontSee('need attention');

        $this->assertNull($this->urgentAction('unassigned_tasks'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{total: int, counts: array<string, int>, lines: array<int, array<string, mixed>>}
     */
    private function summary(): array
    {
        return app(TaskAssignmentGaps::class)->summarise();
    }

    /**
     * @return array<int, int>
     */
    private function needsAssignmentIds(): array
    {
        return Task::query()
            ->needsAssignment()
            ->orderBy('task_id')
            ->pluck('task_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function urgentAction(string $key): ?array
    {
        DashboardMetrics::flush();

        foreach (app(DashboardMetrics::class)->urgentActions() as $action) {
            if ($action['key'] === $key) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = []): Task
    {
        static $sequence = 0;

        return Task::create(array_merge([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->technician->technician_id,
            'task_title' => 'Task '.(++$sequence),
            'task_description' => 'Description',
            'start_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'due_date' => CarbonImmutable::today()->addDays(3)->toDateString(),
            'status' => 'pending',
        ], $attributes));
    }

    private function project(string $name, bool $schedule = true): Project
    {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        if ($schedule) {
            Schedule::create([
                'project_id' => $project->project_id,
                'start_datetime' => CarbonImmutable::today()->toDateString().' 00:00:00',
                'end_datetime' => CarbonImmutable::today()->addDays(10)->toDateString().' 23:59:59',
                'status' => 'scheduled',
                'remarks' => 'Booking',
            ]);
        }

        return $project;
    }

    private function technician(string $name, string $role = 'technician'): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE])->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }
}
