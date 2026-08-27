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
 * The days already worked stay, up to and including the day of the hold: those
 * are the project's record of what actually happened. The days still to come
 * stay as well, as the project's PROPOSED schedule - they are what it intends
 * to do next, and throwing them away meant rebuilding a schedule from memory
 * to resume. The crew stays too, so resuming is a rescheduling rather than a
 * rebuild.
 *
 * What is released is the crew's TIME, and the status change is what releases
 * it: a held project is Unscheduled, which is not one of
 * Project::ACTIVE_PROJECT_STATUSES, so none of its bookings count against
 * anybody's availability while the hold stands. That is why the preserved days
 * are free for other work, and why resuming has to ask whether they still are.
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
    private function projectWithTeam(
        array $technicians,
        string $status = 'ongoing',
        string $reference = 'REF-0001'
    ): Project {
        $project = Project::create([
            'name' => 'Some Project',
            'reference_no' => $reference,
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

    public function test_a_hold_preserves_the_dates_and_keeps_the_team(): void
    {
        $lead = $this->technician('Jose Garcia', 'lead_technician');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->scheduledProject([$lead, $ana]);

        $response = $this->put(route('super-admin.projects.hold', $project->project_id));

        $response->assertSessionHasNoErrors();

        $project->refresh();

        $this->assertTrue((bool) $project->on_hold);
        $this->assertSame('On Hold', $project->statusLabel());
        // Unscheduled is what takes the project off the calendar and hands the
        // crew's time back. It is not a statement that the project has no
        // dates - it has both halves of the one below.
        $this->assertSame('unscheduled', $project->status);

        // The booking ran from today to four days out. Today was worked and is
        // the record; the rest is preserved as the proposed schedule.
        $this->assertSame([
            $this->day(0)->toDateString().'|'.$this->day(0)->toDateString(),
            $this->day(1)->toDateString().'|'.$this->day(4)->toDateString(),
        ], $this->ranges($project));

        // Both crew on both halves: the preserved days are booked to exactly
        // who the original row was booked to.
        $this->assertSame(4, ScheduleTechnician::query()->count());

        // And neither half holds anybody, because the project is not active
        // work while it is paused.
        $this->assertTrue(
            app(TechnicianAvailabilityService::class)->findConflicts(
                [$lead->technician_id, $ana->technician_id],
                [['start' => $this->day(0), 'end' => $this->day(4)]]
            )->isEmpty(),
            'A held project must not hold its technicians, preserved dates or not.'
        );

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
     * A task whose dates survive the cutoff keeps them, along with its owner.
     *
     * The hold draws its line at today and keeps everything on the near side.
     * This task starts and finishes inside those kept days, so nothing about
     * it has stopped being true - blanking it, as a hold once did to every
     * open task, threw away a perfectly good date.
     */
    public function test_a_hold_keeps_a_task_date_that_still_falls_on_a_booked_day(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        // Booked up to today, so the cutoff releases nothing.
        $this->book($project, $this->day(-4), $this->day(0));

        $task = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $ana->technician_id,
            'task_title' => 'First fix',
            'task_description' => 'Description',
            'start_date' => $this->day(-3)->toDateString(),
            'due_date' => $this->day(-1)->toDateString(),
            'status' => 'ongoing',
        ]);

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $task->refresh();

        $this->assertSame($ana->technician_id, $task->technician_id);
        $this->assertSame('ongoing', $task->status);
        $this->assertSame($this->day(-3)->toDateString(), (string) $task->start_date);
        $this->assertSame($this->day(-1)->toDateString(), (string) $task->due_date);
    }

    /**
     * A task dated on a day still to come keeps its dates too, now that those
     * days are preserved rather than deleted.
     *
     * A hold used to strand exactly this task, because the days it pointed at
     * stopped existing on the project. They no longer stop existing - they are
     * the proposed schedule - so there is nothing about the task that has
     * stopped being true, and blanking it would throw away a perfectly good
     * date for the second time.
     */
    public function test_a_hold_keeps_a_task_date_that_falls_on_a_preserved_day(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        $this->book($project, $this->day(0), $this->day(6));

        $task = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $ana->technician_id,
            'task_title' => 'Second fix',
            'task_description' => 'Description',
            'start_date' => $this->day(5)->toDateString(),
            'due_date' => $this->day(6)->toDateString(),
            'status' => 'pending',
        ]);

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $task->refresh();

        $this->assertSame($this->day(5)->toDateString(), (string) $task->start_date);
        $this->assertSame($this->day(6)->toDateString(), (string) $task->due_date);
        $this->assertSame($ana->technician_id, $task->technician_id);
        $this->assertSame('pending', $task->status);
    }

    /**
     * The stranded-date rule itself is untouched: a task pointing at a day the
     * project does not hold is still unassigned by a hold.
     *
     * It is simply no longer a hold that creates such a task, because the hold
     * no longer takes days off the project. One already pointing outside every
     * range - left behind by an earlier reschedule, say - is still cleared, and
     * the tasks sitting inside the schedule are still left alone.
     */
    public function test_a_hold_only_unassigns_the_tasks_that_point_outside_the_schedule(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        $this->book($project, $this->day(-3), $this->day(6));

        $kept = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $ana->technician_id,
            'task_title' => 'Worked already',
            'task_description' => 'Description',
            'start_date' => $this->day(-3)->toDateString(),
            'due_date' => $this->day(-1)->toDateString(),
            'status' => 'ongoing',
        ]);

        // Never covered by any range this project holds.
        $stranded = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $ana->technician_id,
            'task_title' => 'Pointing at nothing',
            'task_description' => 'Description',
            'start_date' => $this->day(20)->toDateString(),
            'due_date' => $this->day(22)->toDateString(),
            'status' => 'pending',
        ]);

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->day(-3)->toDateString(), (string) $kept->refresh()->start_date);
        $this->assertSame($this->day(-1)->toDateString(), (string) $kept->due_date);

        $this->assertNull($stranded->refresh()->start_date);
        $this->assertNull($stranded->due_date);
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
        // The booking the hold split at today is one booking again: the two
        // halves are back in force together, and a break between them would be
        // a break that never happened.
        $this->assertSame([$this->day(0)->toDateString().'|'.$this->day(4)->toDateString()], $this->ranges($project));
    }

    // ------------------------------------------------------------------
    // Where the hold draws its line
    // ------------------------------------------------------------------

    /**
     * The whole rule in one project: days already worked stay as the record,
     * the day of the hold stays with them, and everything after it is
     * preserved as the proposal - split off into a row of its own where a
     * booking straddles the line. Each booking is judged on its own, so the
     * gaps between them survive.
     */
    public function test_a_hold_divides_worked_days_from_proposed_ones(): void
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
            // Shortened to the day of the hold, which is kept...
            $this->day(-1)->toDateString().'|'.$this->day(0)->toDateString(),
            // ...and the rest of it preserved as a range of its own.
            $this->day(1)->toDateString().'|'.$this->day(2)->toDateString(),
            $this->day(4)->toDateString().'|'.$this->day(6)->toDateString(),
            $this->day(20)->toDateString().'|'.$this->day(22)->toDateString(),
        ], $this->ranges($project));
    }

    /**
     * Each of the five cases on its own, including the two edges the rule
     * turns on: a booking that ends on the hold date is untouched, and one
     * that starts on it keeps that single day and proposes the rest.
     */
    public function test_each_booking_is_divided_by_where_it_falls(): void
    {
        $ana = $this->technician('Ana Mendoza');

        foreach ([
            'ended before' => [[-6, -4], [[-6, -4]]],
            'ends on the day' => [[-2, 0], [[-2, 0]]],
            'crosses the day' => [[-1, 2], [[-1, 0], [1, 2]]],
            'starts on the day' => [[0, 4], [[0, 0], [1, 4]]],
            'starts after' => [[1, 3], [[1, 3]]],
        ] as $case => [$booked, $expected]) {
            $project = $this->projectWithTeam([$ana]);
            $this->book($project, $this->day($booked[0]), $this->day($booked[1]));

            $this->put(route('super-admin.projects.hold', $project->project_id))
                ->assertSessionHasNoErrors();

            $this->assertSame(
                array_map(
                    fn (array $range): string => $this->day($range[0])->toDateString()
                        .'|'.$this->day($range[1])->toDateString(),
                    $expected
                ),
                $this->ranges($project),
                sprintf('A booking that %s the hold date was divided wrongly.', $case)
            );

            // Each case gets its own project, and REF-0001 is not unique.
            $project->schedules()->delete();
            $project->delete();
        }
    }

    /**
     * Every row keeps its crew, the tail split off a crossing booking
     * included: the preserved days are a proposal about the same people doing
     * the same work, and the technician's time is handed back by the status
     * change rather than by unpicking the rows.
     */
    public function test_every_row_keeps_its_crew_including_the_split_tail(): void
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
        $this->assertSame(1, ScheduleTechnician::where('schedule_id', $future->schedule_id)->count());

        // Four rows now - the crossing one became two - and every one of them
        // carries Ana.
        $this->assertSame(4, Schedule::where('project_id', $project->project_id)->count());
        $this->assertSame(4, ScheduleTechnician::query()->count());

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
     * A partial day covers one date, so it falls wholly on one side of the
     * line and is never split - and it keeps the hours it was booked for
     * whichever side that is.
     */
    public function test_a_partial_day_is_never_split_and_keeps_its_hours(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        $today = $this->book($project, $this->day(0), $this->day(0), '08:00', '12:00');
        $ahead = $this->book($project, $this->day(3), $this->day(3), '13:00', '17:00');

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $remaining = Schedule::where('project_id', $project->project_id)
            ->orderBy('start_datetime')
            ->get();

        $this->assertCount(2, $remaining);
        $this->assertSame(
            [$today->schedule_id, $ahead->schedule_id],
            $remaining->pluck('schedule_id')->all()
        );
        $this->assertTrue($remaining->every(fn (Schedule $schedule): bool => $schedule->isPartialDay()));
        $this->assertSame('8:00 AM - 12:00 PM', $remaining->first()->timeRange());
        $this->assertSame('1:00 PM - 5:00 PM', $remaining->last()->timeRange());
    }

    /**
     * A shortened booking is the row that was already there - the tail is what
     * is new - and the gap between two separate bookings stays a gap.
     */
    public function test_the_cutoff_keeps_the_original_rows_and_merges_nothing(): void
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

        // Three: the untouched first, the shortened second, and the tail split
        // off it. The gap between the first and the second is left alone.
        $this->assertCount(3, $remaining);
        $this->assertSame(
            [$first->schedule_id, $second->schedule_id],
            $remaining->take(2)->pluck('schedule_id')->all(),
            'The rows that were already there are the rows that were kept.'
        );
        $this->assertSame([
            $this->day(-8)->toDateString().'|'.$this->day(-6)->toDateString(),
            $this->day(-2)->toDateString().'|'.$this->day(0)->toDateString(),
            $this->day(1)->toDateString().'|'.$this->day(5)->toDateString(),
        ], $this->ranges($project));
        $this->assertSame(3, ScheduleTechnician::query()->count());
    }

    /**
     * A project whose every booking is still to come keeps all of them, whole
     * and untouched: there is no line running through any of them, and every
     * one is part of the proposal.
     *
     * A hold used to leave such a project with nothing at all, which is what
     * made resuming it a rebuild.
     */
    public function test_a_project_booked_only_in_the_future_keeps_every_range(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        $this->book($project, $this->day(2), $this->day(4));
        $this->book($project, $this->day(8), $this->day(9));

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $this->assertSame([
            $this->day(2)->toDateString().'|'.$this->day(4)->toDateString(),
            $this->day(8)->toDateString().'|'.$this->day(9)->toDateString(),
        ], $this->ranges($project));
        $this->assertSame(2, ScheduleTechnician::query()->count());
        $this->assertSame(1, ProjectTechnician::where('project_id', $project->project_id)->count());

        // Preserved, and still holding nobody.
        $this->assertTrue(
            app(TechnicianAvailabilityService::class)->findConflicts(
                [$ana->technician_id],
                [['start' => $this->day(2), 'end' => $this->day(9)]]
            )->isEmpty()
        );
    }

    /**
     * A hold survives the status sweep that runs on every Projects page load.
     *
     * The sweep promotes anything Unscheduled that holds a schedule row, and
     * a hold leaves exactly that behind: the days already worked, kept as the
     * project's record. Reading those as work in progress put the project
     * straight back to Ongoing - which is one of the two statuses that books a
     * technician, so the crew the hold had just released was booked again for
     * days nobody was working, and Resume then landed on Ongoing while telling
     * the administrator the project was Unscheduled.
     */
    public function test_opening_the_projects_page_does_not_undo_a_hold(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);

        // Started before today and running past it, so the cutoff keeps a
        // range - which is what the sweep used to seize on.
        $this->book($project, $this->day(-3), $this->day(3));

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        $this->assertSame('unscheduled', $project->fresh()->status);
        $this->assertNotSame([], $this->ranges($project), 'The worked days are kept.');

        // Nothing but a page load.
        $this->get(route('super-admin.projects'))->assertOk();

        $held = $project->fresh();

        $this->assertSame('unscheduled', $held->status);
        $this->assertTrue((bool) $held->on_hold);
        $this->assertSame('On Hold', $held->statusLabel());

        // And the crew is still free on the days the hold kept, because a
        // paused project is not one of the statuses that books anybody.
        $conflicts = app(TechnicianAvailabilityService::class)->findConflicts(
            [$ana->technician_id],
            [['start' => $this->day(0), 'end' => $this->day(0)]]
        );

        $this->assertTrue($conflicts->isEmpty(), 'A held project must not hold its technicians.');
    }

    /**
     * Resuming recalculates the status from the dates the project actually
     * holds, rather than assuming one.
     *
     * There are three cases and the status has to tell them apart: work still
     * ahead, work whose every day has passed, and a project left holding no
     * dates at all.
     */
    public function test_resuming_recalculates_the_status_from_the_remaining_dates(): void
    {
        // Booked entirely ahead of the hold, and preserved: resuming puts that
        // proposal back into force, so the project is booked but not started.
        $ana = $this->technician('Ana Mendoza');
        $stillAhead = $this->projectWithTeam([$ana]);
        $this->book($stillAhead, $this->day(2), $this->day(4));

        $this->put(route('super-admin.projects.hold', $stillAhead->project_id));
        $this->get(route('super-admin.projects'));
        $this->put(route('super-admin.projects.resume', $stillAhead->project_id))
            ->assertSessionHasNoErrors();

        $this->assertSame('pending', $stillAhead->fresh()->status);
        $this->assertSame('Pending', $stillAhead->fresh()->statusLabel());

        // Nothing at all: a project put on hold with no dates on it.
        $dana = $this->technician('Dana Reyes');
        $nothingLeft = $this->projectWithTeam([$dana], 'ongoing', 'PRJ-EMPTY-1');
        $this->book($nothingLeft, $this->day(-4), $this->day(-2));
        Schedule::where('project_id', $nothingLeft->project_id)->delete();

        $this->put(route('super-admin.projects.hold', $nothingLeft->project_id));
        $this->put(route('super-admin.projects.resume', $nothingLeft->project_id))
            ->assertSessionHasNoErrors();

        $this->assertSame('unscheduled', $nothingLeft->fresh()->status);
        $this->assertSame('Unscheduled', $nothingLeft->fresh()->statusLabel());

        // Every remaining day has passed: the work is late, not under way.
        $ben = $this->technician('Ben Cruz');
        $allPast = $this->projectWithTeam([$ben]);
        $this->book($allPast, $this->day(-6), $this->day(-2));

        $this->put(route('super-admin.projects.hold', $allPast->project_id));
        $this->put(route('super-admin.projects.resume', $allPast->project_id))
            ->assertSessionHasNoErrors();

        $this->assertTrue($allPast->fresh()->isOverdue());
        $this->assertSame('Overdue', $allPast->fresh()->statusLabel());

        // The remaining dates reach today, so the project really is under way.
        $cara = $this->technician('Cara Lim');
        $reachesToday = $this->projectWithTeam([$cara]);
        $this->book($reachesToday, $this->day(-3), $this->day(3));

        $this->put(route('super-admin.projects.hold', $reachesToday->project_id));
        $this->put(route('super-admin.projects.resume', $reachesToday->project_id))
            ->assertSessionHasNoErrors();

        $this->assertSame('ongoing', $reachesToday->fresh()->status);
        $this->assertSame('Ongoing', $reachesToday->fresh()->statusLabel());
    }

    /**
     * A resumed project whose days have all passed must not go on holding its
     * crew: those dates are a record of work done, and the technician is free
     * for anything booked from today onwards.
     */
    public function test_a_resumed_projects_past_dates_do_not_hold_the_crew(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);
        $this->book($project, $this->day(-6), $this->day(-2));

        $this->put(route('super-admin.projects.hold', $project->project_id));
        $this->put(route('super-admin.projects.resume', $project->project_id));
        $this->get(route('super-admin.projects'));

        $conflicts = app(TechnicianAvailabilityService::class)->findConflicts(
            [$ana->technician_id],
            [['start' => $this->day(0), 'end' => $this->day(5)]]
        );

        $this->assertTrue($conflicts->isEmpty(), 'Days already worked are not a booking.');
    }

    /**
     * Resuming something that was never paused is refused: it would otherwise
     * email the client that their project had resumed and tell the whole crew.
     */
    public function test_a_project_that_is_not_on_hold_cannot_be_resumed(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana]);
        $this->book($project, $this->day(0), $this->day(3));

        $this->put(route('super-admin.projects.resume', $project->project_id))
            ->assertSessionHas('error');

        $this->assertSame('ongoing', $project->fresh()->status);
    }

    /**
     * The guard is narrow: a project that is merely Unscheduled, with dates
     * that have arrived, is still promoted exactly as it always was.
     */
    public function test_the_sweep_still_promotes_a_project_that_is_not_on_hold(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->projectWithTeam([$ana], 'unscheduled');

        $this->book($project, $this->day(-1), $this->day(3));

        $this->get(route('super-admin.projects'))->assertOk();

        $this->assertSame('ongoing', $project->fresh()->status);
    }

    /**
     * A hold hands the crew's remaining day back, so somebody else can book
     * them onto it. Resuming must not quietly take it again.
     *
     * The hold keeps the day it was placed on, because work was done on it.
     * That kept day stops blocking anybody the moment the project goes on
     * hold - a held project is not active work - which is what lets a second
     * project be booked over it. Lifting the hold puts the day back into
     * force, and if the crew has since been promised elsewhere on it the
     * project would come back double-booked with nobody told.
     */
    public function test_resuming_is_refused_when_the_crew_was_booked_over_a_kept_day(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $held = $this->projectWithTeam([$ana], 'ongoing', 'PRJ-000001');
        $this->book($held, $this->day(-2), $this->day(0));

        $this->put(route('super-admin.projects.hold', $held->project_id))
            ->assertSessionHasNoErrors();

        // The kept day is free as far as everyone else is concerned, so this
        // booking is allowed - and it is what the resume then collides with.
        $other = $this->projectWithTeam([$ana], 'ongoing', 'PRJ-000002');
        $this->book($other, $this->day(0), $this->day(3));

        $this->put(route('super-admin.projects.resume', $held->project_id))
            ->assertSessionHas('error');

        $held->refresh();

        $this->assertTrue($held->on_hold, 'A refused resume leaves the hold in place.');
    }

    /**
     * The refusal names who is double-booked and what they are booked on, so
     * the person can go and fix the actual clash.
     */
    public function test_a_refused_resume_says_who_is_double_booked_and_where(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $held = $this->projectWithTeam([$ana], 'ongoing', 'PRJ-000001');
        $this->book($held, $this->day(-2), $this->day(0));

        $this->put(route('super-admin.projects.hold', $held->project_id));

        $other = $this->projectWithTeam([$ana], 'ongoing', 'PRJ-000002');
        $this->book($other, $this->day(0), $this->day(3));

        $response = $this->put(route('super-admin.projects.resume', $held->project_id));

        $error = session('error');

        $this->assertStringContainsString('Ana Mendoza', $error);
        $this->assertStringContainsString('PRJ-000002', $error);
    }

    /**
     * The check is about the days the project actually still holds. A hold
     * that kept nothing has nothing to clash with, so the crew being busy
     * elsewhere is none of the resume's business.
     */
    public function test_a_resume_is_allowed_when_the_clash_is_outside_the_kept_days(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $held = $this->projectWithTeam([$ana], 'ongoing', 'PRJ-000001');
        $this->book($held, $this->day(-4), $this->day(-2));

        $this->put(route('super-admin.projects.hold', $held->project_id));

        $other = $this->projectWithTeam([$ana], 'ongoing', 'PRJ-000002');
        $this->book($other, $this->day(0), $this->day(3));

        $this->put(route('super-admin.projects.resume', $held->project_id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertFalse($held->fresh()->on_hold);
    }

    /**
     * Archiving frees the crew without breaking the project up.
     *
     * This used to delete the schedule rows and the assignments outright, and
     * asserted that it had. It no longer does: an archived project keeps its
     * dates and its team as the record of what it was, and stops occupying
     * anybody by virtue of its status - the same way a cancelled project
     * always has. The freeing is what matters here and is what is asserted
     * below; the preserving is covered in ProjectArchiveRestoreTest.
     */
    public function test_archiving_frees_the_crew_without_deleting_the_project(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->scheduledProject([$ana]);

        $this->post(route('super-admin.projects.archive', $project->project_id))
            ->assertSessionHasNoErrors();

        // Nothing was taken apart.
        $this->assertSame(1, ProjectTechnician::where('project_id', $project->project_id)->count());
        $this->assertSame(1, Schedule::where('project_id', $project->project_id)->count());

        // And Ana is free for those very days on other work, which is the
        // thing deleting the rows was there to achieve.
        $other = $this->projectWithTeam([$ana], 'ongoing', 'PRJ-FREE-1');

        $this->assertTrue(
            app(TechnicianAvailabilityService::class)->findConflicts(
                [$ana->technician_id],
                [['start' => $this->day(0), 'end' => $this->day(4)]],
                (int) $other->project_id
            )->isEmpty()
        );
    }
}
