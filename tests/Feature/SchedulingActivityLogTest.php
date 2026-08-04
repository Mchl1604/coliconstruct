<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scheduling leaves a trail: changing a project's dates, and putting a
 * technician on or off a project from their Schedules tab.
 */
class SchedulingActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    private function createTechnician(string $name, string $role = 'technician'): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role])->save();

        return Technician::create([
            'account_id' => $user->id,
            'role' => $role,
        ]);
    }

    private function createProject(string $status = 'ongoing'): Project
    {
        return Project::create([
            'name' => 'Project '.uniqid(),
            'reference_no' => 'REF-'.uniqid(),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
        ]);
    }

    private function book(Project $project, Technician $technician, string $startDate, string $endDate): Schedule
    {
        $projectTechnician = ProjectTechnician::firstOrCreate([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $startDate.' 00:00:00',
            'end_datetime' => $endDate.' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        ScheduleTechnician::create([
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $projectTechnician->project_technician_id,
        ]);

        return $schedule;
    }

    private function latest(string $action): ?ActivityLog
    {
        return ActivityLog::where('action', $action)->latest('activity_log_id')->first();
    }

    // ------------------------------------------------------------------
    // Editing a project's date ranges
    // ------------------------------------------------------------------

    public function test_editing_the_date_ranges_is_recorded(): void
    {
        $technician = $this->createTechnician('Jose Garcia');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day12 = CarbonImmutable::today()->addDays(12)->toDateString();

        $project = $this->createProject();
        $schedule = $this->book($project, $technician, $day10, $day10);

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $schedule->schedule_id,
                    'start_date' => $day10,
                    'end_date' => $day12,
                ],
            ],
        ]);

        $response->assertSessionHas('success');

        $entry = $this->latest(ActivityLog::PROJECT_RESCHEDULED);

        $this->assertNotNull($entry);
        $this->assertSame(ActivityLog::MODULE_PROJECTS, $entry->module);
        $this->assertStringContainsString($project->reference_no, $entry->description);
        // The before and after both appear, so the entry says what changed.
        $this->assertStringContainsString(CarbonImmutable::parse($day10)->format('M j, Y'), $entry->description);
        $this->assertStringContainsString(CarbonImmutable::parse($day12)->format('M j, Y'), $entry->description);
    }

    public function test_adding_a_second_range_is_recorded(): void
    {
        $technician = $this->createTechnician('Ana Cruz');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day20 = CarbonImmutable::today()->addDays(20)->toDateString();

        $project = $this->createProject();
        $schedule = $this->book($project, $technician, $day10, $day10);

        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                ['schedule_id' => $schedule->schedule_id, 'start_date' => $day10, 'end_date' => $day10],
                ['schedule_id' => null, 'start_date' => $day20, 'end_date' => $day20],
            ],
        ]);

        $entry = $this->latest(ActivityLog::PROJECT_RESCHEDULED);

        $this->assertNotNull($entry);
        $this->assertStringContainsString(CarbonImmutable::parse($day20)->format('M j, Y'), $entry->description);
    }

    /**
     * A rejected reschedule must not leave an entry claiming it happened.
     */
    public function test_a_rejected_reschedule_records_nothing(): void
    {
        $technician = $this->createTechnician('Mark Reyes');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day12 = CarbonImmutable::today()->addDays(12)->toDateString();
        $day14 = CarbonImmutable::today()->addDays(14)->toDateString();

        $project = $this->createProject();
        $schedule = $this->book($project, $technician, $day10, $day10);
        // The same technician is busy mid-range on another project.
        $this->book($this->createProject(), $technician, $day12, $day12);

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                ['schedule_id' => $schedule->schedule_id, 'start_date' => $day10, 'end_date' => $day14],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertNull($this->latest(ActivityLog::PROJECT_RESCHEDULED));
    }

    public function test_scheduling_from_the_calendar_is_recorded(): void
    {
        $technician = $this->createTechnician('Lito Santos');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->createProject('not_yet_scheduled');
        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        $response = $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $day10,
            'end_date' => $day10,
            'project_ids' => [$project->project_id],
        ]);

        $response->assertOk();

        $entry = $this->latest(ActivityLog::PROJECT_RESCHEDULED);

        $this->assertNotNull($entry);
        $this->assertStringContainsString($project->reference_no, $entry->description);
        $this->assertStringContainsString('calendar', $entry->description);
    }

    // ------------------------------------------------------------------
    // The technician's Schedules tab
    // ------------------------------------------------------------------

    public function test_assigning_a_technician_from_their_schedules_tab_is_recorded(): void
    {
        $technician = $this->createTechnician('Bea Free');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->createProject();
        $this->book($project, $this->createTechnician('Existing Member'), $day10, $day10);

        $response = $this->postJson(
            route('super-admin.technicians.projects.store', $technician->technician_id),
            ['project_ids' => [$project->project_id]]
        );

        $response->assertOk();

        $entry = $this->latest(ActivityLog::TECHNICIAN_ASSIGNED);

        $this->assertNotNull($entry);
        $this->assertSame(ActivityLog::MODULE_PROJECTS, $entry->module);
        $this->assertStringContainsString('Bea Free', $entry->description);
        $this->assertStringContainsString($project->reference_no, $entry->description);
        $this->assertSame('technician', $entry->subject_role);
    }

    /**
     * A lead joining a project is its own event.
     */
    public function test_assigning_a_lead_records_the_lead_action(): void
    {
        $lead = $this->createTechnician('Lead Person', 'lead_technician');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->createProject();
        $this->book($project, $this->createTechnician('Existing Member'), $day10, $day10);

        $this->postJson(
            route('super-admin.technicians.projects.store', $lead->technician_id),
            ['project_ids' => [$project->project_id]]
        );

        $this->assertNotNull($this->latest(ActivityLog::LEAD_TECHNICIAN_ASSIGNED));
        $this->assertNull($this->latest(ActivityLog::TECHNICIAN_ASSIGNED));
    }

    public function test_a_rejected_assignment_records_nothing(): void
    {
        $technician = $this->createTechnician('Aaron Booked');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->createProject();
        $this->book($project, $this->createTechnician('Existing Member'), $day10, $day10);
        // Busy elsewhere for exactly those dates.
        $this->book($this->createProject(), $technician, $day10, $day10);

        $response = $this->postJson(
            route('super-admin.technicians.projects.store', $technician->technician_id),
            ['project_ids' => [$project->project_id]]
        );

        $response->assertStatus(422);
        $this->assertNull($this->latest(ActivityLog::TECHNICIAN_ASSIGNED));
    }

    public function test_removing_a_technician_from_a_project_is_recorded(): void
    {
        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->createProject();
        $keeper = $this->createTechnician('Keeper Person');
        $leaving = $this->createTechnician('Leaving Person');

        $this->book($project, $keeper, $day10, $day10);
        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $leaving->technician_id,
        ]);

        $response = $this->deleteJson(route('super-admin.technicians.projects.destroy', [
            'technician' => $leaving->technician_id,
            'project' => $project->project_id,
        ]));

        $response->assertOk();

        $entry = $this->latest(ActivityLog::TECHNICIAN_REMOVED);

        $this->assertNotNull($entry);
        $this->assertStringContainsString('Leaving Person', $entry->description);
        $this->assertStringContainsString($project->reference_no, $entry->description);
    }
}
