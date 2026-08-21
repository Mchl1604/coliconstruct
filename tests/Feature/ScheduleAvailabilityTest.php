<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleAvailabilityTest extends TestCase
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

    /**
     * Create a project with one schedule range and the given technician on it.
     */
    private function createScheduledProject(
        Technician $technician,
        string $startDate,
        string $endDate,
        string $status = 'ongoing'
    ): Project {
        $project = Project::create([
            'name' => 'Project '.uniqid(),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $projectTechnician = ProjectTechnician::create([
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

        return $project;
    }

    /**
     * The schedules page must reject a range that spans a day the technician
     * is already booked on another project, even when both endpoints are free.
     */
    public function test_it_rejects_a_range_spanning_a_busy_middle_day(): void
    {
        $technician = $this->createTechnician('Jose Garcia');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day12 = CarbonImmutable::today()->addDays(12)->toDateString();
        $day14 = CarbonImmutable::today()->addDays(14)->toDateString();

        // The project being edited, currently booked on day 10 only.
        $project = $this->createScheduledProject($technician, $day10, $day10);
        // The same technician is busy on day 12 for a different project.
        $this->createScheduledProject($technician, $day12, $day12);

        $schedule = $project->schedules()->first();

        // Ask to stretch this project to day 10 -> day 14. Both endpoints are
        // free, but day 12 is not.
        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $schedule->schedule_id,
                    'start_date' => $day10,
                    'end_date' => $day14,
                ],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('is unavailable on', session('error'));

        // The original range must be untouched.
        $this->assertSame(
            $day10,
            CarbonImmutable::parse($schedule->fresh()->start_datetime)->toDateString()
        );
        $this->assertSame(
            $day10,
            CarbonImmutable::parse($schedule->fresh()->end_datetime)->toDateString()
        );
    }

    public function test_it_accepts_a_range_where_every_day_is_free(): void
    {
        $technician = $this->createTechnician('Ana Mendoza');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day11 = CarbonImmutable::today()->addDays(11)->toDateString();
        $day20 = CarbonImmutable::today()->addDays(20)->toDateString();

        $project = $this->createScheduledProject($technician, $day10, $day10);
        // Busy far away from the requested range.
        $this->createScheduledProject($technician, $day20, $day20);

        $schedule = $project->schedules()->first();

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $schedule->schedule_id,
                    'start_date' => $day10,
                    'end_date' => $day11,
                ],
            ],
        ]);

        $response->assertSessionHas('success');
        $this->assertSame(
            $day11,
            CarbonImmutable::parse($schedule->fresh()->end_datetime)->toDateString()
        );
    }

    /**
     * A project's own booked dates must never block itself.
     */
    public function test_a_project_does_not_conflict_with_its_own_schedule(): void
    {
        $technician = $this->createTechnician('Kevin Lopez');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day12 = CarbonImmutable::today()->addDays(12)->toDateString();

        $project = $this->createScheduledProject($technician, $day10, $day12);
        $schedule = $project->schedules()->first();

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
    }

    /**
     * Bookings on completed / cancelled projects must not block anything.
     */
    public function test_it_ignores_completed_and_cancelled_project_bookings(): void
    {
        $technician = $this->createTechnician('Maria Santos');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day14 = CarbonImmutable::today()->addDays(14)->toDateString();
        $day12 = CarbonImmutable::today()->addDays(12)->toDateString();

        $project = $this->createScheduledProject($technician, $day10, $day10);
        $this->createScheduledProject($technician, $day12, $day12, 'completed');
        $this->createScheduledProject($technician, $day12, $day12, 'cancelled');

        $schedule = $project->schedules()->first();

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $schedule->schedule_id,
                    'start_date' => $day10,
                    'end_date' => $day14,
                ],
            ],
        ]);

        $response->assertSessionHas('success');
    }

    /**
     * The mirror of the range-spanning test above, for the other end.
     *
     * Pulling a start date BACK over a day the technician is already booked on
     * books that day just as surely as stretching an end date forward over it
     * does, so it has to be refused on the same terms. Both endpoints of the
     * resulting range are free here; a day between them is not.
     */
    public function test_it_rejects_a_start_date_pulled_back_over_a_busy_day(): void
    {
        $technician = $this->createTechnician('Rosa Villanueva');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day15 = CarbonImmutable::today()->addDays(15)->toDateString();
        $day20 = CarbonImmutable::today()->addDays(20)->toDateString();
        $day25 = CarbonImmutable::today()->addDays(25)->toDateString();

        // The project being edited, booked day 20 -> day 25.
        $project = $this->createScheduledProject($technician, $day20, $day25);
        // The same technician is busy on day 15 for a different project.
        $this->createScheduledProject($technician, $day15, $day15);

        $schedule = $project->schedules()->first();

        // Only the start moves. The end is resubmitted as it stands, which is
        // what the editor sends when somebody touches one field.
        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $schedule->schedule_id,
                    'start_date' => $day10,
                    'end_date' => $day25,
                ],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('is unavailable on', session('error'));

        // Nothing moved.
        $this->assertSame(
            $day20,
            CarbonImmutable::parse($schedule->fresh()->start_datetime)->toDateString()
        );
        $this->assertSame(
            $day25,
            CarbonImmutable::parse($schedule->fresh()->end_datetime)->toDateString()
        );
    }

    /**
     * The busy day sitting just outside the range is what makes this the
     * boundary case: starting the day after it is allowed, starting on it is
     * not, and neither answer may depend on which field was edited.
     */
    public function test_it_accepts_a_start_date_that_stops_short_of_the_busy_day(): void
    {
        $technician = $this->createTechnician('Elena Ramos');

        $day15 = CarbonImmutable::today()->addDays(15)->toDateString();
        $day16 = CarbonImmutable::today()->addDays(16)->toDateString();
        $day20 = CarbonImmutable::today()->addDays(20)->toDateString();
        $day25 = CarbonImmutable::today()->addDays(25)->toDateString();

        $project = $this->createScheduledProject($technician, $day20, $day25);
        $this->createScheduledProject($technician, $day15, $day15);

        $schedule = $project->schedules()->first();

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $schedule->schedule_id,
                    'start_date' => $day16,
                    'end_date' => $day25,
                ],
            ],
        ]);

        $response->assertSessionHas('success');
        $this->assertSame(
            $day16,
            CarbonImmutable::parse($schedule->fresh()->start_datetime)->toDateString()
        );
    }

    /**
     * A booking may still be moved clear of a busy day altogether.
     *
     * Both dates change at once here, and the day that used to sit inside the
     * range no longer does. Judging the resulting range rather than whichever
     * field moved is what makes this possible - and it is why the editor's
     * pickers leave a start beyond the current end alone rather than refusing
     * it as a range spanning everything in between.
     */
    public function test_it_accepts_a_booking_moved_clear_of_a_busy_day(): void
    {
        $technician = $this->createTechnician('Paolo Reyes');

        $day20 = CarbonImmutable::today()->addDays(20)->toDateString();
        $day22 = CarbonImmutable::today()->addDays(22)->toDateString();
        $day25 = CarbonImmutable::today()->addDays(25)->toDateString();
        $day30 = CarbonImmutable::today()->addDays(30)->toDateString();
        $day35 = CarbonImmutable::today()->addDays(35)->toDateString();

        $project = $this->createScheduledProject($technician, $day20, $day25);
        // Busy inside the range as it stands, and outside where it is going.
        $this->createScheduledProject($technician, $day22, $day22);

        $schedule = $project->schedules()->first();

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $schedule->schedule_id,
                    'start_date' => $day30,
                    'end_date' => $day35,
                ],
            ],
        ]);

        $response->assertSessionHas('success');
        $this->assertSame(
            $day30,
            CarbonImmutable::parse($schedule->fresh()->start_datetime)->toDateString()
        );
    }

    /**
     * Every assigned technician is checked across the resulting range, not
     * only the first of them, and the refusal names whichever one cannot work
     * it rather than the team.
     */
    public function test_it_checks_every_assigned_technician_across_the_range(): void
    {
        $lead = $this->createTechnician('Carla Bautista');
        $second = $this->createTechnician('Miguel Ocampo');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day15 = CarbonImmutable::today()->addDays(15)->toDateString();
        $day20 = CarbonImmutable::today()->addDays(20)->toDateString();
        $day25 = CarbonImmutable::today()->addDays(25)->toDateString();

        $project = $this->createScheduledProject($lead, $day20, $day25);
        $schedule = $project->schedules()->first();

        // The second technician joins the project, and is the one who is busy.
        $projectTechnician = ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $second->technician_id,
        ]);

        ScheduleTechnician::create([
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $projectTechnician->project_technician_id,
        ]);

        $this->createScheduledProject($second, $day15, $day15);

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $schedule->schedule_id,
                    'start_date' => $day10,
                    'end_date' => $day25,
                ],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Miguel Ocampo', session('error'));
    }
}
