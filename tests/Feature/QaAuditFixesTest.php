<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\TechnicianAvailabilityService;
use App\Support\AccountAge;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The fixes from the end-to-end QA pass of Sep 2026: the office clock at
 * night, schedule edits that stranded tasks, unbounded closing dates, held
 * days that stopped booking their crew, lost concurrent edits and the rest.
 */
class QaAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->actingAsSuperAdmin();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function day(int $offset): string
    {
        return Schedule::businessToday()->addDays($offset)->toDateString();
    }

    private function technician(string $name, string $role = 'technician'): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role])->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }

    /**
     * An ongoing project booked from $from to $to, with a lead and one
     * technician on it for the whole of that.
     *
     * @return array{0: Project, 1: Technician, 2: Technician, 3: Schedule}
     */
    private function project(int $from = -3, int $to = 10, string $name = 'Warehouse Fit-out'): array
    {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'PRJ-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        $this->finalizePhases($project);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day($from).' 00:00:00',
            'end_datetime' => $this->day($to).' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        $lead = $this->technician($name.' Lead', 'lead_technician');
        $tech = $this->technician($name.' Tech');

        foreach ([$lead, $tech] as $member) {
            $span = ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $member->technician_id,
                'team_role' => $member->account->role,
                'joined_at' => $this->day($from - 1).' 08:00:00',
            ]);

            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $span->project_technician_id,
            ]);
        }

        return [$project, $lead, $tech, $schedule];
    }

    private function task(Project $project, Technician $holder, string $start, string $due, string $status = 'pending', string $title = 'Fit the ducting'): Task
    {
        return Task::create([
            'project_id' => $project->project_id,
            'phase_id' => $this->defaultPhaseId($project),
            'technician_id' => $holder->technician_id,
            'task_title' => $title,
            'task_description' => 'Work',
            'status' => $status,
            'start_date' => $start,
            'due_date' => $due,
        ]);
    }

    // ------------------------------------------------------------------
    // The office clock between midnight and 8 AM in Manila
    // ------------------------------------------------------------------

    public function test_the_activity_log_today_filter_uses_the_office_day(): void
    {
        // 00:30 on Sep 19 in Manila is still Sep 18 on the server.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 16:30:00', 'UTC'));

        $row = fn (string $at): int => DB::table('tbl_activity_logs')->insertGetId([
            'actor_name' => 'Someone',
            'action' => ActivityLog::TECHNICIAN_ASSIGNED,
            'module' => 'Projects',
            'description' => 'x',
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $yesterdayInManila = $row('2026-09-18 15:00:00'); // Sep 18, 11 PM Manila
        $todayInManila = $row('2026-09-18 16:10:00');     // Sep 19, 12:10 AM Manila

        $ids = ActivityLog::query()->withinRange('today')->pluck('activity_log_id')->all();

        $this->assertContains($todayInManila, $ids);
        $this->assertNotContains($yesterdayInManila, $ids);
    }

    public function test_a_report_filed_after_midnight_is_dated_the_office_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 16:30:00', 'UTC'));

        [$project] = $this->project();

        $this->post(route('super-admin.technician.reports.store', $project->project_id), [
            'report_type' => 'progress',
            'report_title' => 'Night report',
            'report_description' => 'Filed at half past midnight.',
        ]);

        $this->assertSame('2026-09-19', TechnicianReport::query()->latest('id')->first()->report_date->toDateString());
    }

    public function test_the_age_limit_counts_from_the_office_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 16:30:00', 'UTC'));

        // Born 2008-09-19: eighteen today in Manila.
        $this->assertSame('2008-09-19', AccountAge::latestAllowed());
    }

    // ------------------------------------------------------------------
    // Schedule edits and the tasks on them
    // ------------------------------------------------------------------

    public function test_a_schedule_save_that_would_clear_open_task_dates_asks_first(): void
    {
        [$project, , $tech, $schedule] = $this->project(1, 10);
        $task = $this->task($project, $tech, $this->day(8), $this->day(9));

        $ranges = ['ranges' => [[
            'schedule_id' => $schedule->schedule_id,
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(1),
            'end_date' => $this->day(5),
        ]]];

        $impact = $this->postJson(route('super-admin.schedules.task-impact', $project->project_id), $ranges);
        $impact->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.task_id', $task->task_id);

        $this->put(route('super-admin.schedules.update', $project->project_id), $ranges)
            ->assertSessionHas('warning');

        $this->assertSame($this->day(10), $schedule->fresh()->endsOn()->toDateString(), 'Nothing is saved without confirmation.');
        $this->assertNotNull($task->fresh()->due_date);

        // The message names the task by the dates it had, not the ones it lost.
        $had = CarbonImmutable::parse($this->day(8))->format('M j, Y');

        $this->put(route('super-admin.schedules.update', $project->project_id), $ranges + ['stranded_tasks_confirmed' => 1])
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, $had));

        $this->assertNull($task->fresh()->start_date);
        $this->assertSame($this->day(5), $schedule->fresh()->endsOn()->toDateString());
    }

    public function test_a_schedule_change_never_clears_a_completed_tasks_dates(): void
    {
        [$project, , $tech, $schedule] = $this->project(1, 10);
        $done = $this->task($project, $tech, $this->day(8), $this->day(9), 'completed');

        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [[
                'schedule_id' => $schedule->schedule_id,
                'scheduling_mode' => Schedule::MODE_DATE_BASED,
                'start_date' => $this->day(1),
                'end_date' => $this->day(5),
            ]],
        ])->assertSessionHas('success');

        $this->assertSame($this->day(8), CarbonImmutable::parse($done->fresh()->start_date)->toDateString());
    }

    public function test_an_admin_cannot_remove_a_schedule_that_is_under_way(): void
    {
        [$project] = $this->project(-2, 5);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $admin->forceFill(['role' => 'admin', 'status' => User::STATUS_ACTIVE, 'must_change_password' => false])->save();
        $this->actingAs($admin);

        $this->put(route('super-admin.schedules.update', $project->project_id), ['ranges' => []])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'already under way'));

        $this->assertSame(1, $project->schedules()->count());
    }

    // ------------------------------------------------------------------
    // Closing a project
    // ------------------------------------------------------------------

    public function test_completion_is_always_recorded_as_today_and_keeps_worked_days(): void
    {
        [$project, , , $schedule] = $this->project(-3, 10);

        $this->post(route('super-admin.projects.complete', $project->project_id), [
            'completion_date' => '2020-01-01',
            'completion_summary' => 'Done.',
            'completion_override_reason' => 'Finishing it for the test.',
        ]);

        $project->refresh();

        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->status);
        $this->assertSame($this->day(0), $project->completed_at->toDateString());
        $this->assertSame($this->day(-3), $schedule->fresh()->startsOn()->toDateString(), 'Days already worked are kept.');
    }

    public function test_cancellation_is_always_recorded_as_today(): void
    {
        [$project] = $this->project(-3, 10);

        $this->post(route('super-admin.projects.cancel', $project->project_id), [
            'cancellation_date' => '1999-01-01',
            'cancellation_reason' => 'Client withdrew',
        ]);

        $this->assertSame($this->day(0), $project->fresh()->cancelled_at->toDateString());
    }

    // ------------------------------------------------------------------
    // Holds
    // ------------------------------------------------------------------

    public function test_the_day_a_project_is_held_still_books_its_crew(): void
    {
        [$held, , $tech] = $this->project(-2, 10, 'Held Job');

        $this->put(route('super-admin.projects.hold', $held->project_id))->assertSessionHas('success');

        $this->assertSame($this->day(0), $held->fresh()->held_on->toDateString());

        $today = Schedule::businessToday();
        $conflicts = app(TechnicianAvailabilityService::class)->findConflicts(
            [$tech->technician_id],
            [['mode' => Schedule::MODE_DATE_BASED, 'start' => $today->startOfDay(), 'end' => $today->endOfDay()]]
        );

        $this->assertTrue($conflicts->isNotEmpty(), 'Today was kept by the held project, so the crew is still busy.');

        $tomorrow = $today->addDay();
        $later = app(TechnicianAvailabilityService::class)->findConflicts(
            [$tech->technician_id],
            [['mode' => Schedule::MODE_DATE_BASED, 'start' => $tomorrow->startOfDay(), 'end' => $tomorrow->endOfDay()]]
        );

        $this->assertTrue($later->isEmpty(), 'Preserved days book nobody until the project resumes.');
    }

    // ------------------------------------------------------------------
    // Editing a project
    // ------------------------------------------------------------------

    private function editPayload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Client',
            'address' => 'New address',
            'contact_number' => '09171234567',
            'email_address' => 'client@example.test',
            'quotation' => '1000',
            'project_description' => 'Description',
            'project_types' => [ProjectType::query()->firstOrCreate(['type_name' => 'Aircon Repair'])->type_id],
            'quotation_change' => 'none',
            'loaded_version' => $project->updated_at->format('Y-m-d H:i:s'),
        ], $overrides);
    }

    public function test_a_quotation_larger_than_the_column_is_refused_with_a_message(): void
    {
        [$project] = $this->project();

        $this->put(route('super-admin.projects.update', $project->project_id), $this->editPayload($project, [
            'quotation' => '99999999999',
            'quotation_change' => 'amount',
        ]))->assertSessionHasErrors(['quotation' => Project::MAX_QUOTATION_MESSAGE]);
    }

    public function test_an_edit_made_from_a_stale_page_is_refused(): void
    {
        [$project] = $this->project();
        $stale = $this->editPayload($project, ['address' => 'Written from an old page']);

        // Somebody else saves in between.
        $this->travel(2)->seconds();
        $project->update(['address' => 'Their change']);

        $this->put(route('super-admin.projects.update', $project->project_id), $stale)
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Someone else changed this project'));

        $this->assertSame('Their change', $project->fresh()->address);
    }

    // ------------------------------------------------------------------
    // Dashboard, notifications, caching, specialties
    // ------------------------------------------------------------------

    public function test_never_booked_work_is_counted_as_needing_rescheduling_on_the_dashboard(): void
    {
        Project::create([
            'name' => 'Never booked',
            'reference_no' => 'PRJ-NOBOOK',
            'status' => 'unscheduled',
            'address' => 'A',
            'description' => 'D',
        ]);

        $counts = app(DashboardMetrics::class)->projectCounts();

        $this->assertSame(1, $counts['overdue']);
        $this->assertSame(0, $counts['pending']);
    }

    public function test_a_notification_link_never_leaves_this_site(): void
    {
        $notification = Notification::create([
            'user_id' => $this->superAdmin->id,
            'title' => 'Old link',
            'message' => 'Stored with a development host',
            'module' => 'Projects',
            'url' => 'http://127.0.0.1:8000/super-admin/projects?status=overdue',
            'is_read' => false,
        ]);

        $this->get(route('notifications.open', $notification->notification_id))
            ->assertRedirect(url('/super-admin/projects?status=overdue'));
    }

    public function test_signed_in_pages_are_not_stored_by_the_browser(): void
    {
        $response = $this->get(route('profile.edit'));

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_specialty_request_must_name_at_least_one_specialty(): void
    {
        $tech = $this->technician('Ria Tech');

        $tech->account->forceFill(['status' => User::STATUS_ACTIVE, 'must_change_password' => false])->save();

        $this->actingAs($tech->account)
            ->post(route('profile.specialties.request'), ['skill_ids' => []])
            ->assertSessionHasErrorsIn('specialties', 'skill_ids');

        $this->assertDatabaseCount('tbl_specialty_requests', 0);
    }

    public function test_a_removal_names_the_tasks_it_leaves_running_into_the_removed_days(): void
    {
        [$project, , $tech] = $this->project(0, 10);
        $this->task($project, $tech, $this->day(3), $this->day(6), 'pending', 'Leak test');

        $this->deleteJson(route('super-admin.technicians.projects.destroy', [$tech->technician_id, $project->project_id]), [
            'mode' => 'from',
            'from' => $this->day(5),
        ])
            ->assertOk()
            ->assertJsonPath('affected_tasks.0', fn (string $label): bool => str_contains($label, 'Leak test'))
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Technician Not Assigned for Dates'));
    }
}
