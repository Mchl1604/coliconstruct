<?php

namespace Tests\Feature;

use App\Models\Client;
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
 * A hold is a pause, not an ending.
 *
 * The days still to come go, because they were promises about dates that are
 * no longer promised and the crew must read as free for other work on them.
 * The days already worked stay, up to and including the day of the hold: those
 * are the project's record of what actually happened, not a promise anyone is
 * withdrawing. The crew stays too - the same people are expected to do the
 * work when it resumes, so resuming is a rescheduling rather than a rebuild.
 */
class ProjectOnHoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * A project with a crew on it and nothing booked yet, so a test can lay
     * out exactly the ranges it wants to watch the hold cut.
     *
     * @param  array<int, Technician>  $technicians
     */
    private function projectWithTeam(array $technicians, string $status = 'ongoing'): Project
    {
        $project = Project::create([
            'name' => 'Some Project',
            'reference_no' => 'REF-0001',
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 250000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Some Holdings',
            'firstname' => 'Client',
            'surname' => 'One',
            'fullname' => 'Client One',
            'email_address' => 'client@example.test',
            'contact_number' => '09123456789',
        ]);

        foreach ($technicians as $technician) {
            ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
            ]);
        }

        return $project;
    }

    /**
     * A scheduled project with a crew booked on it and a task in flight.
     *
     * @param  array<int, Technician>  $technicians
     */
    private function scheduledProject(array $technicians, string $status = 'ongoing'): Project
    {
        $project = $this->projectWithTeam($technicians, $status);

        $this->book($project, $this->day(0), $this->day(4));

        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $technicians[0]->technician_id,
            'task_title' => 'Pull the wiring',
            'task_description' => 'Description',
            'start_date' => $this->day(0)->toDateString(),
            'due_date' => $this->day(2)->toDateString(),
            'status' => 'ongoing',
        ]);

        return $project;
    }

    /**
     * One booking, with every one of the project's crew on it.
     *
     * Whole-day unless hours are given, which is what makes a partial day.
     */
    private function book(
        Project $project,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $from = null,
        ?string $to = null
    ): Schedule {
        $partial = $from !== null && $to !== null;

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $partial
                ? $start->toDateString().' '.$from.':00'
                : $start->toDateString().' 00:00:00',
            'end_datetime' => $partial
                ? $start->toDateString().' '.$to.':00'
                : $end->toDateString().' 23:59:59',
            'scheduling_mode' => $partial ? Schedule::MODE_PARTIAL_DAY : Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        ProjectTechnician::where('project_id', $project->project_id)
            ->pluck('project_technician_id')
            ->each(fn ($assignmentId) => ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignmentId,
            ]));

        return $schedule;
    }

    /**
     * A day relative to the one the hold will be placed on.
     *
     * The office's today, not the server's: that is the date the cutoff draws
     * its line at, and the two are not the same clock.
     */
    private function day(int $offset): CarbonImmutable
    {
        return Schedule::businessToday()->addDays($offset);
    }

    /**
     * Every booking the project still holds, as "start|end" date strings in
     * calendar order - the shape an expectation can be written in directly.
     *
     * @return array<int, string>
     */
    private function ranges(Project $project): array
    {
        return Schedule::where('project_id', $project->project_id)
            ->get()
            ->map(fn (Schedule $schedule): string => $schedule->startsOn()->toDateString()
                .'|'.$schedule->endsOn()->toDateString())
            ->sort()
            ->values()
            ->all();
    }

    public function test_a_hold_releases_the_dates_but_keeps_the_team(): void
    {
        $lead = $this->technician('Jose Garcia', 'lead_technician');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->scheduledProject([$lead, $ana]);

        $response = $this->put(route('super-admin.projects.hold', $project->project_id));

        $response->assertSessionHasNoErrors();

        $project->refresh();

        $this->assertTrue((bool) $project->on_hold);
        $this->assertSame('On Hold', $project->statusLabel());
        // It holds nothing ahead of it any more, so it needs new dates before
        // it can run again.
        $this->assertSame('unscheduled', $project->status);

        // The booking ran from today to four days out. Today was worked, so
        // today is what is left of it.
        $this->assertSame([$this->day(0)->toDateString().'|'.$this->day(0)->toDateString()], $this->ranges($project));

        // The crew stay booked on the part that was kept - one row each, not
        // a second copy.
        $this->assertSame(2, ScheduleTechnician::query()->count());

        // The team is not.
        $assigned = ProjectTechnician::where('project_id', $project->project_id)
            ->pluck('technician_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect([$lead->technician_id, $ana->technician_id])->sort()->values()->all(),
            $assigned
        );
    }

    /**
     * A task keeps the person holding it and loses only the dates, which
     * lived inside a booking that no longer exists.
     */
    public function test_a_hold_clears_task_dates_without_unassigning_them(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->scheduledProject([$ana]);

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $task = Task::where('project_id', $project->project_id)->first();

        $this->assertSame($ana->technician_id, $task->technician_id);
        $this->assertSame('ongoing', $task->status);
        $this->assertNull($task->start_date);
        $this->assertNull($task->due_date);
    }

    /**
     * Resuming leaves a project ready to be rescheduled, with its crew still
     * on it - not rebuilt from nothing.
     */
    public function test_resuming_finds_the_team_still_in_place(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->scheduledProject([$ana]);

        $this->put(route('super-admin.projects.hold', $project->project_id));
        $this->put(route('super-admin.projects.resume', $project->project_id))
            ->assertSessionHasNoErrors();

        $project->refresh();

        $this->assertFalse((bool) $project->on_hold);
        $this->assertSame(1, ProjectTechnician::where('project_id', $project->project_id)->count());
        // Only what the hold kept: resuming restores nothing.
        $this->assertSame([$this->day(0)->toDateString().'|'.$this->day(0)->toDateString()], $this->ranges($project));
    }

    // ------------------------------------------------------------------
    // Where the hold draws its line
    // ------------------------------------------------------------------

    /**
     * The whole rule in one project: days already worked stay, the day of the
     * hold stays, everything after it goes - and each booking is judged on its
     * own, so the gaps between them survive.
     */
    public function test_a_hold_keeps_worked_days_and_releases_the_rest(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        $this->book($project, $this->day(-11), $this->day(-9));  // over and done
        $this->book($project, $this->day(-6), $this->day(-4));   // over and done
        $this->book($project, $this->day(-1), $this->day(2));    // running through today
        $this->book($project, $this->day(4), $this->day(6));     // still to come
        $this->book($project, $this->day(20), $this->day(22));   // still to come

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $this->assertSame([
            $this->day(-11)->toDateString().'|'.$this->day(-9)->toDateString(),
            $this->day(-6)->toDateString().'|'.$this->day(-4)->toDateString(),
            // Shortened to the day of the hold, which is kept.
            $this->day(-1)->toDateString().'|'.$this->day(0)->toDateString(),
        ], $this->ranges($project));
    }

    /**
     * Each of the four cases on its own, including the two edges the rule
     * turns on: a booking that ends on the hold date is untouched, and one
     * that starts on it keeps that single day.
     */
    public function test_each_booking_is_cut_by_where_it_falls(): void
    {
        $ana = $this->technician('Ana Mendoza');

        foreach ([
            'ended before' => [[-6, -4], [-6, -4]],
            'ends on the day' => [[-2, 0], [-2, 0]],
            'crosses the day' => [[-1, 2], [-1, 0]],
            'starts on the day' => [[0, 4], [0, 0]],
            'starts after' => [[1, 3], null],
        ] as $case => [$booked, $expected]) {
            $project = $this->projectWithTeam([$ana]);
            $this->book($project, $this->day($booked[0]), $this->day($booked[1]));

            $this->put(route('super-admin.projects.hold', $project->project_id))
                ->assertSessionHasNoErrors();

            $this->assertSame(
                $expected === null
                    ? []
                    : [$this->day($expected[0])->toDateString().'|'.$this->day($expected[1])->toDateString()],
                $this->ranges($project),
                sprintf('A booking that %s the hold date was cut wrongly.', $case)
            );

            // Each case gets its own project, and REF-0001 is not unique.
            $project->schedules()->delete();
            $project->delete();
        }
    }

    /**
     * A released booking takes its crew's link to it with it, and a kept one
     * does not - which is what hands those days back to the technician while
     * leaving the record of the days they worked alone.
     */
    public function test_only_the_assignments_on_released_bookings_go(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        $past = $this->book($project, $this->day(-6), $this->day(-4));
        $crossing = $this->book($project, $this->day(-1), $this->day(3));
        $future = $this->book($project, $this->day(5), $this->day(7));

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ScheduleTechnician::where('schedule_id', $past->schedule_id)->count());
        $this->assertSame(1, ScheduleTechnician::where('schedule_id', $crossing->schedule_id)->count());
        $this->assertSame(0, ScheduleTechnician::where('schedule_id', $future->schedule_id)->count());

        // The technician stays on the project itself; only the dates went.
        $this->assertSame(1, ProjectTechnician::where('project_id', $project->project_id)->count());
    }

    /**
     * The released days are free again as far as the availability rules are
     * concerned, which is what lets the technician be booked elsewhere on them.
     */
    public function test_the_technician_is_free_for_the_released_days(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        $this->book($project, $this->day(-1), $this->day(4));

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $unavailable = app(TechnicianAvailabilityService::class)->unavailableDatesByTechnician(
            [$ana->technician_id],
            [['start' => $this->day(1), 'end' => $this->day(4)]]
        );

        $this->assertSame([], $unavailable[$ana->technician_id] ?? []);
    }

    /**
     * A partial day covers one date, so it is kept whole or released whole -
     * and a kept one still carries the hours it was booked for.
     */
    public function test_a_partial_day_keeps_its_hours_or_goes_entirely(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        $today = $this->book($project, $this->day(0), $this->day(0), '08:00', '12:00');
        $this->book($project, $this->day(3), $this->day(3), '13:00', '17:00');

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $remaining = Schedule::where('project_id', $project->project_id)->get();

        $this->assertCount(1, $remaining);
        $this->assertSame($today->schedule_id, $remaining->first()->schedule_id);
        $this->assertTrue($remaining->first()->isPartialDay());
        $this->assertSame('8:00 AM - 12:00 PM', $remaining->first()->timeRange());
    }

    /**
     * Nothing is copied and nothing is merged: a shortened booking is the row
     * that was already there, and the gap between two bookings stays a gap.
     */
    public function test_the_cutoff_neither_duplicates_nor_merges(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        $first = $this->book($project, $this->day(-8), $this->day(-6));
        $second = $this->book($project, $this->day(-2), $this->day(5));

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $remaining = Schedule::where('project_id', $project->project_id)
            ->orderBy('schedule_id')
            ->get();

        $this->assertCount(2, $remaining);
        // The same two rows, not replacements for them.
        $this->assertSame(
            [$first->schedule_id, $second->schedule_id],
            $remaining->pluck('schedule_id')->all()
        );
        $this->assertSame(2, ScheduleTechnician::query()->count());
    }

    /**
     * A project whose every booking is still to come keeps none of them - the
     * behaviour a hold has always had, and still the right one.
     */
    public function test_a_project_booked_only_in_the_future_keeps_nothing(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        $this->book($project, $this->day(2), $this->day(4));
        $this->book($project, $this->day(8), $this->day(9));

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->ranges($project));
        $this->assertSame(0, ScheduleTechnician::query()->count());
        $this->assertSame(1, ProjectTechnician::where('project_id', $project->project_id)->count());
    }

    /**
     * Archiving still breaks up the crew: that is an ending, not a pause, and
     * the people on it should not go on reading as committed to it.
     */
    public function test_archiving_still_releases_the_team(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->scheduledProject([$ana]);

        $this->post(route('super-admin.projects.archive', $project->project_id))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, ProjectTechnician::where('project_id', $project->project_id)->count());
        $this->assertSame(0, Schedule::where('project_id', $project->project_id)->count());
    }
}
