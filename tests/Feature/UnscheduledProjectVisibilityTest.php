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
 * A project is allowed to hold no dates at all.
 *
 * Nothing forces a schedule onto it: giving up every date leaves the project
 * Unscheduled, which takes it off the schedules calendar and out of the
 * schedules table until it is booked again. Its editor is still rendered, so
 * the Update Schedule link on the project's own page still reaches it.
 */
class UnscheduledProjectVisibilityTest extends TestCase
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

    private function createProject(string $name, string $status): Project
    {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
        ]);

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $this->createTechnician('Tech '.$name)->technician_id,
        ]);

        return $project;
    }

    private function bookProject(Project $project, string $startDate, string $endDate): Schedule
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

        return $schedule;
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    public function test_a_project_holding_no_dates_is_off_the_calendar_and_the_table(): void
    {
        $scheduled = $this->createProject('Scheduled Project', 'pending');
        $this->bookProject($scheduled, $this->day(5), $this->day(6));

        $bare = $this->createProject('Bare Project', 'unscheduled');

        $response = $this->get(route('super-admin.schedules.index'));
        $response->assertOk();

        $listedIds = $response->viewData('scheduledProjects')->pluck('project_id')->all();

        $this->assertContains($scheduled->project_id, $listedIds);
        $this->assertNotContains($bare->project_id, $listedIds);

        $calendarIds = collect($response->viewData('calendarEvents'))
            ->pluck('extendedProps.projectId')
            ->all();

        $this->assertNotContains($bare->project_id, $calendarIds);

        // Its editor is still on the page: that is what the Update Schedule
        // link on the project's own page opens, and the only way back onto
        // the calendar from here.
        $response->assertSee('scheduleEditModal'.$bare->project_id, false);
    }

    /**
     * The list behind the Needs Scheduling button: everything the calendar
     * cannot draw where it belongs, and only what can actually be booked.
     */
    public function test_the_unscheduled_list_offers_every_project_waiting_for_dates(): void
    {
        $waiting = $this->createProject('Waiting Project', 'unscheduled');

        $scheduled = $this->createProject('Scheduled Project', 'pending');
        $this->bookProject($scheduled, $this->day(5), $this->day(6));

        // A hold releases the dates and the team, so an on-hold project has
        // none - but it has to be resumed before it can be booked, and the
        // server would refuse it. It is not offered here.
        $onHold = $this->createProject('Paused Project', 'unscheduled');
        $onHold->update(['on_hold' => true]);

        // Historical work is never scheduled again either.
        $completed = $this->createProject('Completed Project', 'completed');

        $response = $this->get(route('super-admin.schedules.index'));
        $response->assertOk();

        $offeredIds = $response->viewData('needsSchedulingProjects')->pluck('project_id')->all();

        $this->assertSame([$waiting->project_id], $offeredIds);
        $this->assertNotContains($scheduled->project_id, $offeredIds);
        $this->assertNotContains($onHold->project_id, $offeredIds);
        $this->assertNotContains($completed->project_id, $offeredIds);

        // The button that opens the list, and the row that schedules it.
        $response->assertSee('Needs Scheduling');
        $response->assertSee('data-unscheduled-pick="'.$waiting->project_id.'"', false);
    }

    /**
     * Overdue work belongs in the same list. Its dates have all gone by while
     * the job is still open, so the calendar only shows it in the past - and
     * the fix is the same one the list already offers: give it dates.
     */
    public function test_the_list_also_offers_projects_whose_dates_have_all_passed(): void
    {
        $overdue = $this->createProject('Overdue Project', 'ongoing');
        $this->bookProject($overdue, $this->day(-6), $this->day(-2));

        $waiting = $this->createProject('Waiting Project', 'unscheduled');

        // Still running today, so it is where it should be on the calendar.
        $current = $this->createProject('Current Project', 'ongoing');
        $this->bookProject($current, $this->day(-1), $this->day(3));

        $response = $this->get(route('super-admin.schedules.index'));
        $response->assertOk();

        $offeredIds = $response->viewData('needsSchedulingProjects')->pluck('project_id')->all();

        $this->assertContains($overdue->project_id, $offeredIds);
        $this->assertContains($waiting->project_id, $offeredIds);
        $this->assertNotContains($current->project_id, $offeredIds);

        // Overdue is the more pressing of the two, so it sorts first.
        $this->assertSame($overdue->project_id, $offeredIds[0]);

        // And the card says which of the two it is, rather than leaving the
        // reader to infer it from a missing date.
        $response->assertSee('data-unscheduled-pick="'.$overdue->project_id.'"', false);
        $response->assertSee('Reschedule');
    }

    /**
     * Giving overdue work new dates takes it out of the list, because it is
     * no longer overdue - the same endpoint, and the same result.
     */
    public function test_rescheduling_overdue_work_takes_it_out_of_the_list(): void
    {
        $overdue = $this->createProject('Overdue Project', 'ongoing');
        $this->bookProject($overdue, $this->day(-6), $this->day(-2));

        $response = $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(2),
            'end_date' => $this->day(4),
            'project_ids' => [$overdue->project_id],
        ]);

        $response->assertOk();
        $this->assertFalse($overdue->fresh()->isOverdue());

        $page = $this->get(route('super-admin.schedules.index'));
        $page->assertOk();

        $this->assertNotContains(
            $overdue->project_id,
            $page->viewData('needsSchedulingProjects')->pluck('project_id')->all()
        );
    }

    /**
     * Scheduling from that list goes through the same endpoint the calendar's
     * Add Project flow uses, so a project booked this way is promoted and
     * lands on the calendar exactly as it would from anywhere else.
     */
    public function test_scheduling_from_the_unscheduled_list_puts_the_project_on_the_calendar(): void
    {
        $project = $this->createProject('Waiting Project', 'unscheduled');

        $response = $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
            'project_ids' => [$project->project_id],
        ]);

        $response->assertOk();

        $this->assertSame(1, $project->schedules()->count());
        $this->assertSame('pending', $project->fresh()->status);

        $page = $this->get(route('super-admin.schedules.index'));
        $page->assertOk();

        // On the calendar and in the table, and no longer waiting.
        $this->assertContains(
            $project->project_id,
            collect($page->viewData('calendarEvents'))->pluck('extendedProps.projectId')->all()
        );
        $this->assertContains(
            $project->project_id,
            $page->viewData('scheduledProjects')->pluck('project_id')->all()
        );
        $this->assertSame([], $page->viewData('needsSchedulingProjects')->all());
    }

    public function test_saving_with_no_ranges_gives_up_every_date_and_leaves_the_project_unscheduled(): void
    {
        $project = $this->createProject('Emptied Project', 'pending');
        $this->bookProject($project, $this->day(5), $this->day(6));

        $response = $this->put(route('super-admin.schedules.update', $project->project_id));

        $response->assertRedirect(route('super-admin.schedules.index'));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $this->assertSame(0, $project->schedules()->count());
        $this->assertSame('unscheduled', $project->fresh()->status);

        // And it has left both listings.
        $page = $this->get(route('super-admin.schedules.index'));
        $page->assertOk();

        $this->assertNotContains(
            $project->project_id,
            $page->viewData('scheduledProjects')->pluck('project_id')->all()
        );
        $this->assertSame([], $page->viewData('calendarEvents'));

        // And it is waiting in the Needs Scheduling list, which is the way
        // back onto both.
        $this->assertContains(
            $project->project_id,
            $page->viewData('needsSchedulingProjects')->pluck('project_id')->all()
        );
    }

    /**
     * A task can only sit inside a date the project still holds, which is the
     * same rule that applies when a single date is removed.
     */
    public function test_giving_up_every_date_clears_the_dates_of_its_tasks(): void
    {
        $project = $this->createProject('Emptied Project', 'ongoing');
        $this->bookProject($project, $this->day(5), $this->day(6));

        $task = Task::create([
            'project_id' => $project->project_id,
            'task_title' => 'Install the unit',
            'task_description' => 'Description',
            'status' => 'pending',
            'start_date' => $this->day(5),
            'due_date' => $this->day(6),
        ]);

        $this->put(route('super-admin.schedules.update', $project->project_id))
            ->assertSessionMissing('error');

        $task->refresh();

        $this->assertNull($task->start_date);
        $this->assertNull($task->due_date);
    }

    /**
     * Removing rows one at a time must reach the same place as submitting
     * none: the schedule that is kept stays, and the rest go.
     */
    public function test_a_kept_range_survives_while_the_others_are_given_up(): void
    {
        $project = $this->createProject('Trimmed Project', 'pending');
        $kept = $this->bookProject($project, $this->day(5), $this->day(6));
        $this->bookProject($project, $this->day(10), $this->day(11));

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [[
                'schedule_id' => $kept->schedule_id,
                'start_date' => $this->day(5),
                'end_date' => $this->day(6),
            ]],
        ]);

        $response->assertSessionMissing('error');

        $this->assertSame([$kept->schedule_id], $project->schedules()->pluck('schedule_id')->all());
        $this->assertSame('pending', $project->fresh()->status);
    }
}
