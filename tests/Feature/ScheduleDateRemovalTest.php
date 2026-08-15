<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taking one calendar date off a project's schedule, from the panel the
 * schedules calendar opens when a date is clicked.
 *
 * A schedule row is a solid block of booked time, so a date removed from the
 * middle of one leaves the two blocks either side of it. The endpoints only
 * move inwards, and a booking covering a single date has nothing left once
 * that date goes.
 */
class ScheduleDateRemovalTest extends TestCase
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

    /**
     * @param  array<int, Technician>  $technicians
     */
    private function createProject(
        array $technicians = [],
        string $status = 'ongoing',
        string $clientType = 'Commercial'
    ): Project {
        $project = Project::create([
            'name' => 'Project '.uniqid(),
            'reference_no' => 'PRJ-'.strtoupper(substr(md5(uniqid()), 0, 8)),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $project->clients()->create([
            'client_type' => $clientType,
            'firstname' => 'Client',
            'fullname' => 'Client Person',
            'email_address' => 'client'.uniqid().'@example.test',
            'contact_number' => '09171234567',
        ]);

        foreach ($technicians as $technician) {
            ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
            ]);
        }

        return $project;
    }

    private function book(Project $project, string $startDate, string $endDate): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $startDate.' 00:00:00',
            'end_datetime' => $endDate.' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        $project->projectTechnicians()->get()->each(function (ProjectTechnician $assignment) use ($schedule): void {
            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        });

        return $schedule;
    }

    private function bookHours(Project $project, string $date, string $from, string $to): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $date.' '.$from.':00',
            'end_datetime' => $date.' '.$to.':00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        $project->projectTechnicians()->get()->each(function (ProjectTechnician $assignment) use ($schedule): void {
            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        });

        return $schedule;
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    private function removeDate(Schedule $schedule, string $date)
    {
        return $this->deleteJson(route('super-admin.schedules.dates.destroy', [
            'schedule' => $schedule->schedule_id,
            'date' => $date,
        ]));
    }

    /**
     * @return array<int, array{start: string, end: string}>
     */
    private function rangesOf(Project $project): array
    {
        return Schedule::query()
            ->where('project_id', $project->project_id)
            ->orderBy('start_datetime')
            ->get()
            ->map(fn (Schedule $schedule): array => [
                'start' => $schedule->startsOn()->toDateString(),
                'end' => $schedule->endsOn()->toDateString(),
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // The shapes a removal can leave behind
    // ------------------------------------------------------------------

    public function test_removing_a_date_inside_a_range_splits_it_in_two(): void
    {
        $technician = $this->createTechnician('Jose Garcia');
        $project = $this->createProject([$technician]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(13))->assertOk();

        $this->assertSame([
            ['start' => $this->day(10), 'end' => $this->day(12)],
            ['start' => $this->day(14), 'end' => $this->day(15)],
        ], $this->rangesOf($project));
    }

    public function test_removing_the_first_day_moves_the_start_forward(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(10))->assertOk();

        $this->assertSame(
            [['start' => $this->day(11), 'end' => $this->day(15)]],
            $this->rangesOf($project)
        );
    }

    public function test_removing_the_last_day_moves_the_end_back(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(15))->assertOk();

        $this->assertSame(
            [['start' => $this->day(10), 'end' => $this->day(14)]],
            $this->rangesOf($project)
        );
    }

    public function test_removing_the_only_day_of_a_one_day_range_deletes_it(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $kept = $this->book($project, $this->day(20), $this->day(21));
        $single = $this->book($project, $this->day(10), $this->day(10));

        $this->removeDate($single, $this->day(10))->assertOk();

        $this->assertDatabaseMissing('tbl_schedule', ['schedule_id' => $single->schedule_id]);
        $this->assertDatabaseHas('tbl_schedule', ['schedule_id' => $kept->schedule_id]);
    }

    public function test_removing_the_date_of_a_partial_day_deletes_it(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')], 'ongoing', 'Residential');
        $morning = $this->bookHours($project, $this->day(10), '08:00', '12:00');
        $afternoon = $this->bookHours($project, $this->day(10), '13:00', '17:00');

        $this->removeDate($morning, $this->day(10))->assertOk();

        // Only the booking that was named goes: the afternoon is a separate
        // promise about the same day.
        $this->assertDatabaseMissing('tbl_schedule', ['schedule_id' => $morning->schedule_id]);
        $this->assertDatabaseHas('tbl_schedule', ['schedule_id' => $afternoon->schedule_id]);
    }

    public function test_a_project_with_several_ranges_only_loses_the_one_named(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $first = $this->book($project, $this->day(10), $this->day(12));
        $this->book($project, $this->day(20), $this->day(22));

        $this->removeDate($first, $this->day(11))->assertOk();

        $this->assertSame([
            ['start' => $this->day(10), 'end' => $this->day(10)],
            ['start' => $this->day(12), 'end' => $this->day(12)],
            ['start' => $this->day(20), 'end' => $this->day(22)],
        ], $this->rangesOf($project));
    }

    public function test_removing_several_dates_over_time_keeps_working(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(13))->assertOk();

        $head = Schedule::where('project_id', $project->project_id)->orderBy('start_datetime')->first();
        $this->removeDate($head, $this->day(11))->assertOk();

        $this->assertSame([
            ['start' => $this->day(10), 'end' => $this->day(10)],
            ['start' => $this->day(12), 'end' => $this->day(12)],
            ['start' => $this->day(14), 'end' => $this->day(15)],
        ], $this->rangesOf($project));
    }

    // ------------------------------------------------------------------
    // What the removal must preserve
    // ------------------------------------------------------------------

    public function test_the_half_left_behind_keeps_its_technicians_booked(): void
    {
        $technician = $this->createTechnician('Jose Garcia');
        $project = $this->createProject([$technician]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(13))->assertOk();

        $schedules = Schedule::where('project_id', $project->project_id)->get();

        $this->assertCount(2, $schedules);

        foreach ($schedules as $half) {
            $this->assertSame(
                1,
                ScheduleTechnician::where('schedule_id', $half->schedule_id)->count(),
                'Both halves must stay booked to the technician who was on the original.'
            );
        }
    }

    public function test_the_removed_date_frees_the_technician_and_the_rest_does_not(): void
    {
        $technician = $this->createTechnician('Jose Garcia');
        $project = $this->createProject([$technician]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(13))->assertOk();

        $availability = app(TechnicianAvailabilityService::class);

        $freeOnRemovedDate = $availability->findConflicts(
            [$technician->technician_id],
            [['start' => CarbonImmutable::parse($this->day(13)), 'end' => CarbonImmutable::parse($this->day(13))]]
        );

        $busyOnKeptDate = $availability->findConflicts(
            [$technician->technician_id],
            [['start' => CarbonImmutable::parse($this->day(14)), 'end' => CarbonImmutable::parse($this->day(14))]]
        );

        $this->assertTrue($freeOnRemovedDate->isEmpty(), 'The removed date must free the technician.');
        $this->assertTrue($busyOnKeptDate->isNotEmpty(), 'The days either side of it must stay booked.');
    }

    public function test_the_removal_is_recorded_in_the_activity_log(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(13))->assertOk();

        $entry = ActivityLog::query()
            ->where('record_id', $project->project_id)
            ->where('action', ActivityLog::PROJECT_RESCHEDULED)
            ->latest('activity_log_id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString(
            CarbonImmutable::parse($this->day(13))->format('F j, Y'),
            $entry->description
        );
    }

    // ------------------------------------------------------------------
    // Status, tasks and who hears about it
    // ------------------------------------------------------------------

    public function test_a_project_left_with_no_dates_becomes_unscheduled(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(10));

        $this->removeDate($schedule, $this->day(10))->assertOk();

        $this->assertSame('unscheduled', $project->fresh()->status);
        // The team survives an empty schedule: they are still the people on
        // this project, and scheduling it again books them.
        $this->assertDatabaseHas('tbl_project_technicians', ['project_id' => $project->project_id]);
    }

    public function test_a_project_that_still_holds_dates_keeps_its_status(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(13))->assertOk();

        $this->assertSame('ongoing', $project->fresh()->status);
    }

    /**
     * Only a removal that moves the outer bounds of the scheduled period can
     * strand a task - taking the last day off does exactly that. A date taken
     * out of the middle leaves those bounds alone, which is covered by
     * TaskScheduleRangeTest.
     */
    public function test_a_task_that_no_longer_fits_loses_its_dates_and_is_reported(): void
    {
        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $project = $this->createProject([$lead]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $stranded = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'task_title' => 'Fit the ducting',
            'task_description' => 'Ends on the removed last day',
            'status' => 'pending',
            'start_date' => $this->day(14),
            'due_date' => $this->day(15),
        ]);

        $untouched = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'task_title' => 'Test the unit',
            'task_description' => 'Still inside the period that remains',
            'status' => 'pending',
            'start_date' => $this->day(10),
            'due_date' => $this->day(11),
        ]);

        $response = $this->removeDate($schedule, $this->day(15));

        $response->assertOk();
        $response->assertJsonPath('cleared_tasks', 1);

        $this->assertNull($stranded->fresh()->start_date);
        $this->assertNull($stranded->fresh()->due_date);
        $this->assertSame($this->day(10), $untouched->fresh()->start_date);

        // The lead runs the task list, so they are told the work needs dates.
        $this->assertDatabaseHas('tbl_notifications', [
            'user_id' => $lead->account_id,
            'title' => 'Task Dates Cleared',
        ]);

        $notification = Notification::where('title', 'Task Dates Cleared')->first();
        $this->assertStringContainsString('Fit the ducting', $notification->message);
    }

    /**
     * A task dated on the very day being removed loses its dates.
     *
     * Removing the day is saying nobody is on site then, and a task cannot
     * start - or fall due - on a day the project does not exist on. The lead
     * is told, which is what the cleared-dates notification is for.
     *
     * Contrast the task that merely SPANS the removed day: the gap between two
     * booked stretches is the project's own business, and a task running
     * across it keeps its dates. Only the endpoints have to be booked.
     */
    public function test_a_task_on_the_removed_day_loses_its_dates(): void
    {
        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $project = $this->createProject([$lead]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $task = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'task_title' => 'Fit the ducting',
            'task_description' => 'On the removed day',
            'status' => 'pending',
            'start_date' => $this->day(13),
            'due_date' => $this->day(13),
        ]);

        $this->removeDate($schedule, $this->day(13))
            ->assertOk()
            ->assertJsonPath('cleared_tasks', 1);

        $this->assertNull($task->fresh()->start_date);
        $this->assertNull($task->fresh()->due_date);
    }

    /**
     * The other half of the rule: a task running ACROSS the removed day is
     * left alone, because both the day it starts on and the day it is due are
     * still booked.
     */
    public function test_a_task_spanning_the_removed_day_keeps_its_dates(): void
    {
        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $project = $this->createProject([$lead]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $task = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'task_title' => 'Fit the ducting',
            'task_description' => 'Runs across the removed day',
            'status' => 'pending',
            'start_date' => $this->day(11),
            'due_date' => $this->day(15),
        ]);

        // Splits the booking into day 10-12 and day 14-15. The task starts on
        // day 11 and is due on day 15, both still booked.
        $this->removeDate($schedule, $this->day(13))
            ->assertOk()
            ->assertJsonPath('cleared_tasks', 0);

        $this->assertSame($this->day(11), $task->fresh()->start_date);
        $this->assertSame($this->day(15), $task->fresh()->due_date);
    }

    public function test_a_task_still_covered_raises_no_notification(): void
    {
        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $project = $this->createProject([$lead]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'task_title' => 'Test the unit',
            'task_description' => 'Inside a kept range',
            'status' => 'pending',
            'start_date' => $this->day(10),
            'due_date' => $this->day(11),
        ]);

        $this->removeDate($schedule, $this->day(13))->assertJsonPath('cleared_tasks', 0);

        $this->assertDatabaseMissing('tbl_notifications', ['title' => 'Task Dates Cleared']);
    }

    // ------------------------------------------------------------------
    // What must be refused
    // ------------------------------------------------------------------

    public function test_a_completed_project_refuses_the_removal(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')], 'completed');
        $schedule = $this->book($project, $this->day(-10), $this->day(-5));

        $this->removeDate($schedule, $this->day(-7))
            ->assertStatus(422)
            ->assertJsonPath('error', 'This project is completed and its schedule can no longer be changed.');

        $this->assertSame(
            [['start' => $this->day(-10), 'end' => $this->day(-5)]],
            $this->rangesOf($project)
        );
    }

    public function test_a_cancelled_project_refuses_the_removal(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')], 'cancelled');
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(13))->assertStatus(422);

        $this->assertSame(
            [['start' => $this->day(10), 'end' => $this->day(15)]],
            $this->rangesOf($project)
        );
    }

    public function test_an_on_hold_project_refuses_the_removal(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $project->update(['on_hold' => true]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(13))->assertStatus(422);
    }

    public function test_an_archived_project_refuses_the_removal(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));
        $project->update(['is_archived' => true, 'status' => 'archived']);

        $this->removeDate($schedule, $this->day(13))->assertStatus(422);
    }

    public function test_a_date_the_schedule_does_not_cover_is_refused(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(20))->assertStatus(422);

        $this->assertSame(
            [['start' => $this->day(10), 'end' => $this->day(15)]],
            $this->rangesOf($project)
        );
    }

    public function test_an_unparseable_date_is_refused(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->deleteJson('/super-admin/schedules/'.$schedule->schedule_id.'/dates/not-a-date')
            ->assertStatus(422);
    }

    public function test_a_past_date_may_still_be_removed(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(-5), $this->day(-1));

        $this->removeDate($schedule, $this->day(-3))->assertOk();

        $this->assertSame([
            ['start' => $this->day(-5), 'end' => $this->day(-4)],
            ['start' => $this->day(-2), 'end' => $this->day(-1)],
        ], $this->rangesOf($project));
    }

    // ------------------------------------------------------------------
    // Who may do it
    // ------------------------------------------------------------------

    public function test_an_admin_may_remove_a_date(): void
    {
        $admin = User::factory()->create(['email' => 'plain.admin@example.test']);
        $admin->forceFill(['role' => 'admin', 'status' => User::STATUS_ACTIVE])->save();

        $this->actingAs($admin);

        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $this->removeDate($schedule, $this->day(13))->assertOk();

        $this->assertCount(2, $this->rangesOf($project));
    }

    public function test_a_technician_may_not_remove_a_date(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $technicianAccount = User::factory()->create(['email' => 'plain.tech@example.test']);
        $technicianAccount->forceFill(['role' => 'technician', 'status' => User::STATUS_ACTIVE])->save();

        $this->actingAs($technicianAccount);

        $this->removeDate($schedule, $this->day(13))->assertForbidden();

        $this->assertSame(
            [['start' => $this->day(10), 'end' => $this->day(15)]],
            $this->rangesOf($project)
        );
    }

    // ------------------------------------------------------------------
    // The panel the action is offered from
    // ------------------------------------------------------------------

    public function test_the_date_panel_carries_a_removal_target_per_booking(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')]);
        $schedule = $this->book($project, $this->day(10), $this->day(15));

        $response = $this->getJson(route('super-admin.schedules.date', $this->day(13)));

        $response->assertOk();
        $response->assertJsonPath('projects.0.read_only', false);
        $response->assertJsonPath('projects.0.ranges.0.schedule_id', $schedule->schedule_id);
        $response->assertJsonPath(
            'projects.0.ranges.0.remove_url',
            route('super-admin.schedules.dates.destroy', [
                'schedule' => $schedule->schedule_id,
                'date' => $this->day(13),
            ])
        );
    }

    public function test_the_date_panel_marks_a_completed_project_read_only(): void
    {
        $project = $this->createProject([$this->createTechnician('Jose Garcia')], 'completed');
        $this->book($project, $this->day(10), $this->day(15));

        $this->getJson(route('super-admin.schedules.date', $this->day(13)))
            ->assertOk()
            ->assertJsonPath('projects.0.read_only', true);
    }
}
