<?php

namespace Tests\Feature;

use App\Models\Client;
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
 * A project is overdue when its LAST scheduled day has passed but the project
 * is still open. Derived, never stored - extending the schedule or completing
 * the project clears it with nothing to migrate.
 */
class OverdueProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every administrative route is behind `auth` and a role check.
        $this->actingAsSuperAdmin();
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

    private function project(
        string $name,
        string $status = 'ongoing',
        bool $onHold = false,
        bool $archived = false
    ): Project {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'on_hold' => $onHold,
            'is_archived' => $archived,
        ]);

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $this->technician('Tech '.$project->project_id)->technician_id,
        ]);

        // The projects and details pages both read the client record.
        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'Client',
            'surname' => 'For '.$project->project_id,
            'fullname' => 'Client For '.$project->project_id,
            'email_address' => 'client'.$project->project_id.'@example.test',
            'contact_number' => '09123456789',
        ]);

        return $project;
    }

    private function schedule(Project $project, string $start, string $end): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $start.' 00:00:00',
            'end_datetime' => $end.' 23:59:59',
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

    public function test_a_project_whose_last_range_has_passed_is_overdue(): void
    {
        $project = $this->project('Late Project');
        $this->schedule($project, $this->day(-10), $this->day(-5));

        $this->assertTrue($project->fresh()->isOverdue());
        $this->assertSame('Overdue', $project->fresh()->statusLabel());
        $this->assertSame('badge-overdue', $project->fresh()->statusBadgeClass());
        $this->assertSame(Project::OVERDUE_COLOR, $project->fresh()->calendarColor());
    }

    /**
     * The whole point of using the LAST end date: an earlier range being in
     * the past means nothing if a later one is still to come.
     */
    public function test_a_multi_range_project_uses_its_last_end_date(): void
    {
        $project = $this->project('Split Project');
        $this->schedule($project, $this->day(-10), $this->day(-5));
        $this->schedule($project, $this->day(5), $this->day(9));

        $this->assertFalse($project->fresh()->isOverdue());
        $this->assertSame('Ongoing', $project->fresh()->statusLabel());

        // Once that later range is gone, the project is late after all.
        $project->schedules()->whereDate('start_datetime', $this->day(5))->delete();

        $this->assertTrue($project->fresh()->isOverdue());
    }

    public function test_a_project_ending_today_is_not_overdue(): void
    {
        $project = $this->project('Ends Today');
        $this->schedule($project, $this->day(-3), $this->day(0));

        $this->assertFalse($project->fresh()->isOverdue());
    }

    public function test_projects_that_cannot_be_late_are_never_overdue(): void
    {
        $cases = [
            'completed' => $this->project('Completed Project', 'completed'),
            'cancelled' => $this->project('Cancelled Project', 'cancelled'),
            'archived' => $this->project('Archived Project', 'archived', false, true),
            'unscheduled' => $this->project('Unscheduled Project', 'unscheduled'),
        ];

        foreach ($cases as $label => $project) {
            $this->schedule($project, $this->day(-10), $this->day(-5));
            $this->assertFalse(
                $project->fresh()->isOverdue(),
                "A {$label} project must not be reported as overdue."
            );
        }

        // On hold is a deliberate pause, so it reports On Hold, not Overdue.
        $onHold = $this->project('Paused Project', 'ongoing', true);
        $this->schedule($onHold, $this->day(-10), $this->day(-5));

        $this->assertFalse($onHold->fresh()->isOverdue());
        $this->assertSame('On Hold', $onHold->fresh()->statusLabel());
    }

    public function test_a_project_with_no_schedule_is_not_overdue(): void
    {
        $project = $this->project('No Schedule Project');

        $this->assertFalse($project->fresh()->isOverdue());
    }

    /**
     * The SQL scope backs the tab count and must agree with isOverdue().
     */
    public function test_the_overdue_scope_matches_the_model_check(): void
    {
        $late = $this->project('Late Project');
        $this->schedule($late, $this->day(-10), $this->day(-5));

        $onTrack = $this->project('On Track Project');
        $this->schedule($onTrack, $this->day(-2), $this->day(5));

        $futureRange = $this->project('Split Project');
        $this->schedule($futureRange, $this->day(-10), $this->day(-8));
        $this->schedule($futureRange, $this->day(3), $this->day(4));

        $done = $this->project('Completed Project', 'completed');
        $this->schedule($done, $this->day(-10), $this->day(-5));

        $scoped = Project::overdue()->pluck('project_id')->sort()->values()->all();
        $derived = Project::with('schedules')->get()
            ->filter->isOverdue()
            ->pluck('project_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$late->project_id], $scoped);
        $this->assertSame($scoped, $derived);
    }

    public function test_the_projects_page_shows_an_overdue_tab_with_a_count(): void
    {
        $late = $this->project('Late Project');
        $this->schedule($late, $this->day(-10), $this->day(-5));

        $onTrack = $this->project('On Track Project');
        $this->schedule($onTrack, $this->day(-2), $this->day(5));

        $response = $this->get(route('super-admin.projects'));

        $response->assertOk();
        $response->assertSee('data-status-filter="overdue"', false);
        $response->assertSee('Overdue');
        $this->assertSame(1, $response->viewData('overdueCount'));
        $response->assertSee('data-overdue="1"', false);
        $response->assertSee('data-overdue="0"', false);
    }

    public function test_the_details_page_warns_and_offers_both_ways_out(): void
    {
        $late = $this->project('Late Project');
        $this->schedule($late, $this->day(-10), $this->day(-5));

        $response = $this->get(route('super-admin.projects.show', $late->project_id));

        $response->assertOk();
        $response->assertSee('This project is overdue');
        $response->assertSee('Add New Schedule');
        $response->assertSee('Mark as Complete');
        $response->assertSee('completeOverdueProjectModal', false);
        $response->assertSee(route('super-admin.projects.complete', $late->project_id), false);
    }

    public function test_the_details_page_has_no_warning_when_on_track(): void
    {
        $onTrack = $this->project('On Track Project');
        $this->schedule($onTrack, $this->day(-2), $this->day(5));

        $response = $this->get(route('super-admin.projects.show', $onTrack->project_id));

        $response->assertOk();
        $response->assertDontSee('This project is overdue');
        $response->assertDontSee('completeOverdueProjectModal', false);
    }

    // ------------------------------------------------------------------
    // Calendars
    // ------------------------------------------------------------------

    public function test_cancelled_and_on_hold_projects_are_off_the_schedules_calendar(): void
    {
        $ongoing = $this->project('Ongoing Project');
        $this->schedule($ongoing, $this->day(-2), $this->day(5));

        $late = $this->project('Late Project');
        $this->schedule($late, $this->day(-10), $this->day(-5));

        $cancelled = $this->project('Cancelled Project', 'cancelled');
        $this->schedule($cancelled, $this->day(-2), $this->day(5));

        $onHold = $this->project('Paused Project', 'ongoing', true);
        $this->schedule($onHold, $this->day(-2), $this->day(5));

        $response = $this->get(route('super-admin.schedules.index'));
        $response->assertOk();

        $events = collect($response->viewData('calendarEvents'));
        $names = $events->pluck('extendedProps.projectName')->unique()->values()->all();

        $this->assertContains('Ongoing Project', $names);
        $this->assertContains('Late Project', $names);
        $this->assertNotContains('Cancelled Project', $names);
        $this->assertNotContains('Paused Project', $names);

        // Overdue events are orange and labelled Overdue. Bookings are drawn
        // outlined rather than filled, so the colour is on the border and the
        // lettering and the bar itself is transparent.
        $lateEvent = $events->firstWhere('extendedProps.projectName', 'Late Project');
        // The ink cut, not the fill: OVERDUE_COLOR is the badge background and
        // is too pale to write with - see Project::CALENDAR_INK.
        $this->assertSame(Project::CALENDAR_INK['overdue'], $lateEvent['borderColor']);
        $this->assertSame(Project::CALENDAR_INK['overdue'], $lateEvent['textColor']);
        $this->assertSame('transparent', $lateEvent['backgroundColor']);
        $this->assertSame('Overdue', $lateEvent['extendedProps']['statusLabel']);
    }

    public function test_cancelled_and_on_hold_projects_are_off_the_technician_calendar(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $late = $this->project('Late Project');
        $cancelled = $this->project('Cancelled Project', 'cancelled');
        $onHold = $this->project('Paused Project', 'ongoing', true);

        foreach ([$late, $cancelled, $onHold] as $project) {
            ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $ana->technician_id,
            ]);
        }

        $this->schedule($late, $this->day(-10), $this->day(-5));
        $this->schedule($cancelled, $this->day(-2), $this->day(5));
        $this->schedule($onHold, $this->day(-2), $this->day(5));

        $response = $this->getJson(route('super-admin.technicians.calendar', $ana->technician_id));
        $response->assertOk();

        $events = collect($response->json('events'));
        $names = $events->pluck('extendedProps.projectName')->unique()->values()->all();

        $this->assertSame(['Late Project'], $names);
        // Outlined rather than filled, the same as every other calendar.
        $this->assertSame(Project::CALENDAR_INK['overdue'], $events->first()['borderColor']);
        $this->assertSame(Project::CALENDAR_INK['overdue'], $events->first()['textColor']);
        $this->assertSame('transparent', $events->first()['backgroundColor']);
        $this->assertSame('Overdue', $events->first()['extendedProps']['statusLabel']);

        // Cancelled work is dropped from the assignments table and its count
        // too; an on-hold project is still an open assignment, so it stays.
        $tableNames = collect($response->json('projects'))->pluck('name')->all();
        $this->assertNotContains('Cancelled Project', $tableNames);
        $this->assertContains('Paused Project', $tableNames);
        $this->assertContains('Late Project', $tableNames);
        // Both are open work, so both count towards the figure beside the
        // calendar.
        $this->assertSame(2, $response->json('activeCount'));
    }

    /**
     * A completed project must not stay booked. Dates past the completion date
     * are released, so it stops occupying the calendar and its technicians
     * stop reading as busy.
     *
     * The dates go at the moment completion is requested, not when the client
     * confirms it: waiting a week for a reply would keep a finished project
     * reading as booked, and its crew as busy, for that week.
     */
    public function test_completing_a_project_releases_its_future_dates(): void
    {
        $project = $this->project('Finished Early');
        $this->schedule($project, $this->day(-2), $this->day(4));
        $future = $this->schedule($project, $this->day(20), $this->day(25));

        $this->post(route('super-admin.projects.complete', $project->project_id), [
            'completion_date' => CarbonImmutable::today()->toDateString(),
            'completion_summary' => 'Work finished ahead of schedule.',
        ])->assertRedirect();

        $project->refresh();
        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->status);

        // The range wholly ahead is gone; the one already running is kept but
        // cut short at the completion date.
        $this->assertDatabaseMissing('tbl_schedule', ['schedule_id' => $future->schedule_id]);
        $this->assertSame(1, $project->schedules()->count());
        $this->assertSame(
            CarbonImmutable::today()->toDateString(),
            CarbonImmutable::parse($project->schedules->first()->end_datetime)->toDateString()
        );
    }

    /**
     * Releasing those dates must also let go of the technicians booked on
     * them, which is the whole point of clearing the calendar.
     */
    public function test_releasing_dates_frees_the_technicians_booked_on_them(): void
    {
        $project = $this->project('Finished Early');
        $future = $this->schedule($project, $this->day(20), $this->day(25));

        $this->assertDatabaseHas('tbl_schedule_technicians', [
            'schedule_id' => $future->schedule_id,
        ]);

        $this->post(route('super-admin.projects.complete', $project->project_id), [
            'completion_date' => CarbonImmutable::today()->toDateString(),
            'completion_summary' => 'Done.',
        ])->assertRedirect();

        $this->assertDatabaseMissing('tbl_schedule_technicians', [
            'schedule_id' => $future->schedule_id,
        ]);
    }

    /**
     * A project that ran to plan keeps every day it was booked for - nothing
     * is in the future to release.
     */
    public function test_completing_on_time_leaves_past_dates_alone(): void
    {
        $project = $this->project('Finished On Time');
        $this->schedule($project, $this->day(-10), $this->day(-1));

        $this->post(route('super-admin.projects.complete', $project->project_id), [
            'completion_date' => CarbonImmutable::today()->toDateString(),
            'completion_summary' => 'Done.',
        ])->assertRedirect();

        $project->refresh();
        $this->assertSame(1, $project->schedules()->count());
        $this->assertSame(
            $this->day(-1),
            CarbonImmutable::parse($project->schedules->first()->end_datetime)->toDateString()
        );
    }

    public function test_extending_the_schedule_clears_the_overdue_state(): void
    {
        $project = $this->project('Late Project');
        $this->schedule($project, $this->day(-10), $this->day(-5));

        $this->assertTrue($project->fresh()->isOverdue());

        $this->schedule($project, $this->day(2), $this->day(6));

        $this->assertFalse($project->fresh()->isOverdue());
        $this->assertSame(0, Project::overdue()->count());
    }
}
