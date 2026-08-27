<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\SystemReportService;
use App\Support\BusinessTime;
use App\Support\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One task, read from every portal, reading the same everywhere.
 *
 * Two of the four states nobody sets: Overdue is the passage of time, and
 * Finished Late is completed_at measured against due_date. Both are derived on
 * every read by TaskStatus, and the stored column is never rewritten to make a
 * page display correctly - so the original status, the due date, the
 * completion instant and who closed it all survive.
 *
 * The bug these pin: Project Details switched on the raw status column and so
 * called a task "Pending" a month after its deadline, while the Tasks board
 * called the same task Overdue.
 */
class TaskStatusConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Technician $technician;

    private Technician $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();

        $this->project = Project::create([
            'name' => 'Status Consistency',
            'reference_no' => 'REF-STATUS',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => CarbonImmutable::today()->subDays(30)->toDateString().' 00:00:00',
            'end_datetime' => CarbonImmutable::today()->addDays(30)->toDateString().' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        $this->technician = $this->technician('Ana Cruz');
        $this->lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);

        foreach ([$this->technician, $this->lead] as $member) {
            ProjectTechnician::create([
                'project_id' => $this->project->project_id,
                'technician_id' => $member->technician_id,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // The rules themselves
    // ------------------------------------------------------------------

    public function test_a_task_before_its_due_date_is_pending(): void
    {
        // Due Aug 28, today Aug 27.
        $task = $this->task(['due_date' => $this->businessDate(1)]);

        $this->assertSame(TaskStatus::PENDING, $task->derivedStatus());
        $this->assertSame('Pending', $task->statusLabel());
        $this->assertFalse($task->isOverdue());
    }

    public function test_a_task_due_today_is_still_pending(): void
    {
        // The deadline has not passed until the day has.
        $task = $this->task(['due_date' => $this->businessDate(0)]);

        $this->assertSame(TaskStatus::PENDING, $task->derivedStatus());
    }

    public function test_a_task_past_its_due_date_becomes_overdue_on_its_own(): void
    {
        // Due Aug 25, not completed, today Aug 27. Nobody edited the column:
        // it still says 'pending'.
        $task = $this->task(['due_date' => $this->businessDate(-2)]);

        $this->assertSame('pending', $task->status, 'The stored status must not be rewritten.');
        $this->assertSame(TaskStatus::OVERDUE, $task->derivedStatus());
        $this->assertSame('Overdue', $task->statusLabel());
        $this->assertSame('badge-overdue', $task->statusBadgeClass());
    }

    public function test_an_ongoing_task_past_its_due_date_is_overdue_too(): void
    {
        $task = $this->task(['status' => 'ongoing', 'due_date' => $this->businessDate(-1)]);

        $this->assertSame(TaskStatus::OVERDUE, $task->derivedStatus());
        $this->assertSame('ongoing', $task->status);
    }

    public function test_a_task_completed_on_its_due_date_is_completed(): void
    {
        // Due Aug 25, completed Aug 25 - on time, even at 11 PM.
        $task = $this->completed(dueOffset: -2, completedOffset: -2, hour: 23);

        $this->assertSame(TaskStatus::COMPLETED, $task->derivedStatus());
        $this->assertSame('Completed', $task->statusLabel());
        $this->assertFalse($task->wasFinishedLate());
    }

    public function test_a_task_completed_before_its_due_date_is_completed(): void
    {
        $task = $this->completed(dueOffset: 2, completedOffset: 0);

        $this->assertSame(TaskStatus::COMPLETED, $task->derivedStatus());
    }

    public function test_a_task_completed_after_its_due_date_is_finished_late(): void
    {
        // Due Aug 25, completed Aug 26.
        $task = $this->completed(dueOffset: -2, completedOffset: -1);

        $this->assertSame(TaskStatus::FINISHED_LATE, $task->derivedStatus());
        $this->assertSame('Finished Late', $task->statusLabel());
        $this->assertSame('badge-finished-late', $task->statusBadgeClass());
        $this->assertTrue($task->wasFinishedLate());

        // The record is intact: it is still a completion, and every fact
        // behind the label survives.
        $this->assertSame('completed', $task->status);
        $this->assertTrue($task->isCompleted());
        $this->assertNotNull($task->completed_at);
        $this->assertNotNull($task->due_date);
        $this->assertNotNull($task->completed_by);
    }

    public function test_how_late_a_completion_was_is_counted_in_whole_days(): void
    {
        // Due Aug 20, closed Aug 27 - seven days, not 6.67. Measuring between
        // two midnights in different timezones is what produced the fraction.
        $task = $this->completed(dueOffset: -7, completedOffset: 0, hour: 16);

        $this->assertSame(7, TaskStatus::daysLate($task));
        $this->assertIsInt(TaskStatus::daysLate($task));
    }

    public function test_a_task_finished_on_time_has_no_days_late(): void
    {
        $this->assertNull(TaskStatus::daysLate($this->completed(dueOffset: -1, completedOffset: -1)));
        $this->assertNull(TaskStatus::daysLate($this->task()));
    }

    public function test_a_completion_with_no_recorded_instant_is_not_accused_of_being_late(): void
    {
        // Work closed before the system kept a completion date.
        $task = $this->task([
            'status' => 'completed',
            'due_date' => $this->businessDate(-5),
            'completed_at' => null,
        ]);

        $this->assertSame(TaskStatus::COMPLETED, $task->derivedStatus());
    }

    public function test_a_task_with_no_due_date_is_never_overdue_or_late(): void
    {
        $open = $this->task(['due_date' => null]);
        $done = $this->task([
            'status' => 'completed',
            'due_date' => null,
            'completed_at' => CarbonImmutable::now(),
        ]);

        $this->assertSame(TaskStatus::PENDING, $open->derivedStatus());
        $this->assertSame(TaskStatus::COMPLETED, $done->derivedStatus());
    }

    public function test_a_cancelled_task_stays_cancelled_however_old(): void
    {
        $task = $this->task(['status' => 'cancelled', 'due_date' => $this->businessDate(-90)]);

        $this->assertSame(TaskStatus::CANCELLED, $task->derivedStatus());
    }

    public function test_an_unassigned_task_past_its_due_date_reads_overdue(): void
    {
        $task = $this->task([
            'status' => 'unassigned',
            'technician_id' => null,
            'due_date' => $this->businessDate(-3),
        ]);

        $this->assertSame(TaskStatus::OVERDUE, $task->derivedStatus());
    }

    // ------------------------------------------------------------------
    // Timezone
    // ------------------------------------------------------------------

    public function test_overdue_is_judged_on_the_office_clock_not_the_servers(): void
    {
        // Manila runs eight hours ahead of UTC, so in the small hours of the
        // Manila morning the server's date is still yesterday. At 3 AM on
        // Aug 27 in Manila it is still Aug 26 in UTC - and a task due Aug 26
        // is over its deadline for the person looking at it, while a UTC
        // comparison would go on calling it Pending until the office is
        // halfway through the morning.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-27 03:00', 'Asia/Manila'));

        $task = $this->task(['due_date' => '2026-08-26']);

        $this->assertSame('2026-08-26', CarbonImmutable::now('UTC')->toDateString(), 'Precondition: UTC is a day behind.');
        $this->assertSame(TaskStatus::OVERDUE, $task->derivedStatus());

        CarbonImmutable::setTestNow();
    }

    public function test_a_task_completed_late_in_the_office_evening_is_on_time(): void
    {
        // Closed at 7 AM Manila on the due date, which in UTC is 11 PM the
        // day BEFORE. The office clock is what the deadline was set on, so
        // this is on time - and reading the stored instant as UTC would file
        // it against the wrong day entirely.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 07:00', 'Asia/Manila'));

        $task = $this->task([
            'status' => 'completed',
            'due_date' => '2026-08-25',
            'completed_at' => CarbonImmutable::now(),
            'completed_by' => auth()->id(),
        ]);

        $this->assertSame(TaskStatus::COMPLETED, $task->derivedStatus());

        CarbonImmutable::setTestNow();
    }

    // ------------------------------------------------------------------
    // Every portal agrees
    // ------------------------------------------------------------------

    /**
     * The heart of it: one overdue task, opened from every page in every
     * portal that shows a task, reading "Overdue" on all of them.
     *
     * Driven by a loop rather than a data provider so the whole matrix fails
     * as one test naming the page that disagreed - which is the thing worth
     * knowing.
     */
    public function test_an_overdue_task_reads_overdue_in_every_portal(): void
    {
        $task = $this->task(['due_date' => $this->businessDate(-4)]);

        foreach (self::PORTAL_PAGES as $where => [$role, $routeName]) {
            $response = $this->actingAs($this->accountFor($role))
                ->get($this->url($routeName));

            $response->assertOk();
            $this->assertStringContainsString('Overdue', $response->getContent(), $where.' does not say Overdue.');
            // The failure this test exists for: the same task also being
            // called Pending somewhere.
            $this->assertStringNotContainsString('>Pending<', $response->getContent(), $where.' still calls it Pending.');
        }

        $this->assertSame(TaskStatus::OVERDUE, $task->fresh()->derivedStatus());
    }

    public function test_a_late_completion_reads_finished_late_in_every_portal(): void
    {
        $this->completed(dueOffset: -6, completedOffset: -2);

        foreach (self::PORTAL_PAGES as $where => [$role, $routeName]) {
            $response = $this->actingAs($this->accountFor($role))
                ->get($this->url($routeName));

            $response->assertOk();
            $this->assertStringContainsString(
                'Finished Late',
                $response->getContent(),
                $where.' does not say Finished Late.'
            );
        }
    }

    /**
     * Every page a task is shown on, with a role entitled to open it.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PORTAL_PAGES = [
        'Super Admin - Tasks page' => ['super_admin', 'super-admin.tasks.index'],
        'Super Admin - Project Details' => ['super_admin', 'super-admin.projects.show'],
        'Admin - Tasks page' => ['admin', 'super-admin.tasks.index'],
        'Admin - Project Details' => ['admin', 'super-admin.projects.show'],
        'Lead Technician - Tasks page' => ['lead_technician', 'technician.tasks'],
        'Lead Technician - Project Details' => ['lead_technician', 'technician.projects.show'],
        'Technician - Tasks page' => ['technician', 'technician.tasks'],
        'Technician - Project Details' => ['technician', 'technician.projects.show'],
    ];

    public function test_project_details_no_longer_calls_an_overdue_task_pending(): void
    {
        // The reported bug, stated directly. Before the fix this page switched
        // on the status column and printed "Pending".
        $this->task(['due_date' => $this->businessDate(-30)]);

        $this->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('badge-overdue', false)
            ->assertDontSee('bg-secondary">Pending', false);
    }

    public function test_the_technician_panel_payload_carries_the_derived_state(): void
    {
        // This payload used to be ucfirst() of the stored column, so the
        // browser-drawn panel showed "Pending" for an overdue task.
        $created = $this->task(['due_date' => $this->businessDate(-4)]);

        $response = $this->getJson(route('super-admin.technicians.assignment', [
            'technician' => $this->technician->technician_id,
            'project' => $this->project->project_id,
        ]))->assertOk();

        $task = collect($response->json('project.tasks'))
            ->firstWhere('task_id', $created->task_id);

        $this->assertNotNull($task, 'The technician panel returned no task.');
        $this->assertSame('Overdue', $task['status_label']);
        $this->assertSame(TaskStatus::OVERDUE, $task['status_key']);
        $this->assertSame('badge-overdue', $task['status_badge_class']);
        // The stored column travels alongside, untouched.
        $this->assertSame('pending', $task['status']);
    }

    // ------------------------------------------------------------------
    // Filters, counts and refresh
    // ------------------------------------------------------------------

    public function test_a_report_counts_overdue_and_late_tasks_under_their_own_headings(): void
    {
        $this->task(['due_date' => $this->businessDate(-4)]);
        $this->task(['due_date' => $this->businessDate(-5)]);
        $this->completed(dueOffset: -6, completedOffset: -2);
        $this->completed(dueOffset: -6, completedOffset: -6);
        $this->task(['due_date' => $this->businessDate(3)]);

        $report = app(SystemReportService::class)->exportReport('technician', [
            'start' => BusinessTime::today()->subDays(30),
            'end' => BusinessTime::today()->addDays(30),
        ]);

        $rows = collect($report['sections'])->firstWhere('key', 'technician_tasks');

        $this->assertNotNull($rows, 'The tasks report section is missing.');

        $counts = collect($rows['groups'])
            ->flatMap(fn (array $group) => $group['rows'])
            ->countBy('status_key');

        $this->assertSame(2, $counts[TaskStatus::OVERDUE] ?? 0);
        $this->assertSame(1, $counts[TaskStatus::FINISHED_LATE] ?? 0);
        $this->assertSame(1, $counts[TaskStatus::COMPLETED] ?? 0);
        $this->assertSame(1, $counts[TaskStatus::PENDING] ?? 0);

        // A Pending filter must not be quietly holding an overdue task.
        $this->assertNotContains(
            TaskStatus::PENDING,
            collect($rows['groups'])
                ->flatMap(fn (array $group) => $group['rows'])
                ->where('status_label', 'Overdue')
                ->pluck('status_key')
                ->all()
        );
    }

    public function test_the_status_changes_on_refresh_when_the_date_rolls_over(): void
    {
        // Due tomorrow. Today it is Pending on the page.
        $task = $this->task(['due_date' => BusinessTime::today()->addDay()->toDateString()]);

        $this->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Pending');

        $this->assertSame(TaskStatus::PENDING, $task->fresh()->derivedStatus());

        // Two days pass. Nothing is written to the task - the only thing that
        // changed is the date - and the very next page load says Overdue.
        CarbonImmutable::setTestNow(BusinessTime::now()->addDays(2));

        $this->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Overdue');

        $this->assertSame(TaskStatus::OVERDUE, $task->fresh()->derivedStatus());
        $this->assertSame('pending', $task->fresh()->status, 'Nothing was written to the task.');

        CarbonImmutable::setTestNow();
    }

    public function test_completing_an_overdue_task_turns_it_into_finished_late(): void
    {
        $task = $this->task(['due_date' => $this->businessDate(-3)]);

        $this->assertSame(TaskStatus::OVERDUE, $task->derivedStatus());

        // Through the endpoint the completion dialog posts to.
        $this->patch(route('super-admin.tasks.complete', $task->task_id), [
            'completion_notes' => 'Finished it, late.',
        ])->assertRedirect();

        $task = $task->fresh();

        $this->assertSame(TaskStatus::FINISHED_LATE, $task->derivedStatus());
        $this->assertSame('Finished Late', $task->statusLabel());
        $this->assertNotNull($task->completed_at);
        $this->assertNotNull($task->completed_by);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function url(string $name): string
    {
        return str_contains($name, 'projects.show')
            ? route($name, $this->project->project_id)
            : route($name);
    }

    private function accountFor(string $role): User
    {
        return match ($role) {
            'lead_technician' => $this->lead->account,
            'technician' => $this->technician->account,
            default => $this->administrator($role),
        };
    }

    /** @var array<string, User> */
    private array $administrators = [];

    private function administrator(string $role): User
    {
        if (isset($this->administrators[$role])) {
            return $this->administrators[$role];
        }

        $user = User::factory()->create([
            'name' => ucfirst($role).' Account',
            'email' => $role.'.viewer@example.test',
        ]);

        $user->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE])->save();

        return $this->administrators[$role] = $user;
    }

    /**
     * A day relative to today, on the office clock, as a 'Y-m-d' string.
     */
    private function businessDate(int $offsetDays): string
    {
        return BusinessTime::today()->addDays($offsetDays)->toDateString();
    }

    /**
     * A completed task: due `dueOffset` days from today, closed
     * `completedOffset` days from today at `hour` on the office clock.
     *
     * completed_at is built as a genuine Manila instant, which is what the
     * application actually stores - TaskController::complete() writes now(),
     * a real point in time. Building it from BusinessTime::today() instead
     * would produce a naive wall-clock value (see Schedule::businessNow(),
     * which re-parses the Manila clock in the app timezone), and converting
     * that back would move the completion onto the following day.
     */
    private function completed(int $dueOffset, int $completedOffset, int $hour = 10): Task
    {
        // Built as a Manila instant and handed over in UTC, which is exactly
        // what the application stores: both completion endpoints write now(),
        // a real UTC instant. The ->utc() is not cosmetic - Eloquent's
        // datetime cast keeps the wall-clock digits and relabels the zone, so
        // handing it a +08:00 value would store 23:00 UTC and shift the
        // completion onto the following office day.
        $closedAt = CarbonImmutable::parse(
            $this->businessDate($completedOffset).sprintf(' %02d:00', $hour),
            Schedule::BUSINESS_TIMEZONE
        )->utc();

        return $this->task([
            'status' => 'completed',
            'due_date' => $this->businessDate($dueOffset),
            'completed_at' => $closedAt,
            'completed_by' => auth()->id(),
            'completion_notes' => 'Done.',
        ]);
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
            'start_date' => $this->businessDate(-10),
            'due_date' => $this->businessDate(5),
            'status' => 'pending',
        ], $attributes));
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
