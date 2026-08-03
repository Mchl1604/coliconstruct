<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Completed / cancelled / archived projects are historical records. They must
 * not be editable from the schedules page, must not be schedulable onto new
 * dates, and cancelled work must not clutter the task list.
 */
class ReadOnlyProjectVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every administrative route is behind `auth` and a role check.
        $this->actingAsSuperAdmin();
    }

    private function createTechnician(string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => 'technician'])->save();

        return Technician::create([
            'account_id' => $user->id,
            'role' => 'technician',
        ]);
    }

    private function createProject(string $name, string $status, bool $archived = false): Project
    {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'is_archived' => $archived,
        ]);

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $this->createTechnician('Tech '.$project->project_id)->technician_id,
        ]);

        return $project;
    }

    private function bookProject(Project $project, string $startDate, string $endDate): void
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $startDate.' 00:00:00',
            'end_datetime' => $endDate.' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        $project->projectTechnicians()->get()->each(function ($projectTechnician) use ($schedule): void {
            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $projectTechnician->project_technician_id,
            ]);
        });
    }

    private function addTask(Project $project, string $title): Task
    {
        return Task::create([
            'project_id' => $project->project_id,
            'task_title' => $title,
            'task_description' => 'Description',
            'status' => 'pending',
        ]);
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    public function test_schedules_page_renders_no_edit_modal_for_read_only_projects(): void
    {
        $ongoing = $this->createProject('Ongoing Project', 'ongoing');
        $completed = $this->createProject('Completed Project', 'completed');
        $cancelled = $this->createProject('Cancelled Project', 'cancelled');

        $this->bookProject($ongoing, $this->day(5), $this->day(6));
        $this->bookProject($completed, $this->day(5), $this->day(6));
        $this->bookProject($cancelled, $this->day(5), $this->day(6));

        $response = $this->get(route('super-admin.schedules.index'));

        $response->assertOk();
        $response->assertSee('scheduleEditModal'.$ongoing->project_id, false);
        $response->assertDontSee('scheduleEditModal'.$completed->project_id, false);
        $response->assertDontSee('scheduleEditModal'.$cancelled->project_id, false);
    }

    public function test_read_only_projects_are_never_offered_for_a_new_date_range(): void
    {
        $this->createProject('Ongoing Project', 'ongoing');
        $completed = $this->createProject('Completed Project', 'completed');
        $cancelled = $this->createProject('Cancelled Project', 'cancelled');
        $archived = $this->createProject('Archived Project', 'archived', true);

        $response = $this->getJson(route('super-admin.schedules.assignable', [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
        ]));

        $response->assertOk();

        $returnedIds = collect($response->json('projects'))
            ->concat($response->json('blocked'))
            ->pluck('project_id')
            ->all();

        $this->assertNotContains($completed->project_id, $returnedIds);
        $this->assertNotContains($cancelled->project_id, $returnedIds);
        $this->assertNotContains($archived->project_id, $returnedIds);
    }

    public function test_read_only_projects_cannot_be_scheduled_even_by_direct_post(): void
    {
        $completed = $this->createProject('Completed Project', 'completed');
        $cancelled = $this->createProject('Cancelled Project', 'cancelled');

        foreach ([$completed, $cancelled] as $project) {
            $response = $this->postJson(route('super-admin.schedules.assign'), [
                'start_date' => $this->day(5),
                'end_date' => $this->day(6),
                'project_ids' => [$project->project_id],
            ]);

            $response->assertStatus(422);
            $this->assertStringContainsString(
                'can no longer be scheduled',
                (string) $response->json('error')
            );
        }

        $this->assertSame(0, Schedule::count());
    }

    public function test_existing_schedule_of_a_read_only_project_cannot_be_updated(): void
    {
        $cancelled = $this->createProject('Cancelled Project', 'cancelled');
        $this->bookProject($cancelled, $this->day(5), $this->day(6));

        $schedule = $cancelled->schedules()->first();

        $response = $this->put(route('super-admin.schedules.update', $cancelled->project_id), [
            'ranges' => [[
                'schedule_id' => $schedule->schedule_id,
                'start_date' => $this->day(10),
                'end_date' => $this->day(11),
            ]],
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(
            $this->day(5),
            CarbonImmutable::parse($schedule->fresh()->start_datetime)->toDateString()
        );
    }

    /**
     * The task list is for live work only: pending and ongoing projects.
     * Everything else - not-yet-scheduled, on-hold, completed, cancelled,
     * archived - is left off the page entirely.
     */
    public function test_tasks_page_only_lists_tasks_of_pending_or_ongoing_projects(): void
    {
        $pending = $this->createProject('Pending Project', 'pending');
        $ongoing = $this->createProject('Ongoing Project', 'ongoing');
        $notYetScheduled = $this->createProject('Not Scheduled Project', 'not_yet_scheduled');
        $completed = $this->createProject('Completed Project', 'completed');
        $cancelled = $this->createProject('Cancelled Project', 'cancelled');
        $archived = $this->createProject('Archived Project', 'archived', true);

        $this->addTask($pending, 'Visible Pending Task');
        $this->addTask($ongoing, 'Visible Ongoing Task');
        $this->addTask($notYetScheduled, 'Hidden Unscheduled Task');
        $this->addTask($completed, 'Hidden Completed Task');
        $this->addTask($cancelled, 'Hidden Cancelled Task');
        $this->addTask($archived, 'Hidden Archived Task');

        $response = $this->get(route('super-admin.tasks.index'));

        $response->assertOk();
        $response->assertSee('Visible Pending Task');
        $response->assertSee('Visible Ongoing Task');
        $response->assertDontSee('Hidden Unscheduled Task');
        $response->assertDontSee('Hidden Completed Task');
        $response->assertDontSee('Hidden Cancelled Task');
        $response->assertDontSee('Hidden Archived Task');
    }

    /**
     * The board shows a card for every pending/ongoing project, including ones
     * with no tasks yet, and the filter above it lists exactly those.
     */
    public function test_the_filter_dropdown_offers_every_pending_or_ongoing_project(): void
    {
        $ongoingWithTask = $this->createProject('Ongoing With Task', 'ongoing');
        $ongoingNoTask = $this->createProject('Ongoing No Task', 'ongoing');
        $pendingNoTask = $this->createProject('Pending No Task', 'pending');

        $notYetScheduled = $this->createProject('Not Scheduled Project', 'not_yet_scheduled');
        $completed = $this->createProject('Completed Project', 'completed');
        $cancelled = $this->createProject('Cancelled Project', 'cancelled');
        $archived = $this->createProject('Archived Project', 'archived', true);

        $this->addTask($ongoingWithTask, 'Visible Ongoing Task');
        $this->addTask($cancelled, 'Hidden Cancelled Task');

        $response = $this->get(route('super-admin.tasks.index'));
        $response->assertOk();

        $filterIds = $response->viewData('projects')->pluck('project_id')->all();

        // Listed whether or not they have tasks.
        $this->assertContains($ongoingWithTask->project_id, $filterIds);
        $this->assertContains($ongoingNoTask->project_id, $filterIds);
        $this->assertContains($pendingNoTask->project_id, $filterIds);

        // Anything the table can never show stays out.
        $this->assertNotContains($notYetScheduled->project_id, $filterIds);
        $this->assertNotContains($completed->project_id, $filterIds);
        $this->assertNotContains($cancelled->project_id, $filterIds);
        $this->assertNotContains($archived->project_id, $filterIds);
    }

    public function test_add_task_dropdown_excludes_completed_cancelled_and_archived(): void
    {
        $ongoing = $this->createProject('Ongoing Project', 'ongoing');
        $completed = $this->createProject('Completed Project', 'completed');
        $cancelled = $this->createProject('Cancelled Project', 'cancelled');
        $archived = $this->createProject('Archived Project', 'archived', true);

        $response = $this->get(route('super-admin.tasks.index'));
        $response->assertOk();

        $selectable = $response->viewData('schedulableProjects')->pluck('project_id')->all();

        $this->assertContains($ongoing->project_id, $selectable);
        $this->assertNotContains($completed->project_id, $selectable);
        $this->assertNotContains($cancelled->project_id, $selectable);
        $this->assertNotContains($archived->project_id, $selectable);
    }

    /**
     * A hand-rolled "no rows" <tr> has a single colspan cell, which DataTables
     * cannot parse - it throws "Requested unknown parameter '1' for row 0".
     * The empty message must come from DataTables' own emptyTable option, so
     * the server-rendered tbody stays empty when there is nothing to show.
     */
    public function test_empty_task_table_renders_no_placeholder_row(): void
    {
        // A listed project with nothing on its board still renders its card,
        // and that card's table body has to come back empty.
        $this->createProject('Ongoing No Tasks', 'ongoing');

        // Tasks exist elsewhere, but every one of them is filtered out.
        $cancelled = $this->createProject('Cancelled Project', 'cancelled');
        $this->addTask($cancelled, 'Hidden Cancelled Task');

        $response = $this->get(route('super-admin.tasks.index'));
        $response->assertOk();

        $html = $response->getContent();
        $tbody = $this->tasksTableBody($html);

        $this->assertNotNull($tbody, 'Could not locate the tasks table body.');
        $this->assertStringNotContainsString('colspan', $tbody);
        $this->assertStringNotContainsString('<tr', $tbody);

        // The message is configured on the DataTable instead, in the script
        // that builds every card's table.
        $this->assertStringContainsString('/js/taskBoard.js', $html);
        $this->assertStringContainsString(
            'emptyTable',
            (string) file_get_contents(public_path('js/taskBoard.js'))
        );
    }

    /**
     * Pull out just the <tbody> of the tasks table.
     */
    private function tasksTableBody(string $html): ?string
    {
        if (! preg_match('/<table[^>]*data-project-tasks-table.*?<tbody>(.*?)<\/tbody>/s', $html, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The Super Admin board is the technician portal's, card for card: a
     * project card each, one filter, and a single Add Task dialog.
     */
    public function test_the_tasks_page_uses_the_shared_project_card_board(): void
    {
        $ongoing = $this->createProject('Ongoing Project', 'ongoing');
        $this->addTask($ongoing, 'Some Ongoing Task');

        $response = $this->get(route('super-admin.tasks.index'));

        $response->assertOk();
        $response->assertSee('data-task-board', false);
        $response->assertSee('data-project-card', false);
        $response->assertSee('data-project-filter', false);
        $response->assertSee('All Projects');

        $this->assertSame(1, substr_count($response->getContent(), 'data-task-create-modal'));
        $response->assertSee('Choose the project first');

        // Every row action the board offers.
        $response->assertSee('Delete task');
        $response->assertSee('View / edit task');
    }

    public function test_tasks_page_shows_the_project_status(): void
    {
        $pending = $this->createProject('Pending Project', 'pending');
        $ongoing = $this->createProject('Ongoing Project', 'ongoing');

        $this->addTask($pending, 'Some Pending Task');
        $this->addTask($ongoing, 'Some Ongoing Task');

        $response = $this->get(route('super-admin.tasks.index'));

        $response->assertOk();
        $response->assertSee('Some Pending Task');
        $response->assertSee('badge bg-warning', false);
        $response->assertSee('Pending');

        $response->assertSee('Some Ongoing Task');
        $response->assertSee('badge bg-primary', false);
        $response->assertSee('Ongoing');
    }
}
