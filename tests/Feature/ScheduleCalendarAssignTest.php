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

/**
 * Covers the calendar-driven flow: click a date, see what's booked, then
 * schedule another project starting from it.
 */
class ScheduleCalendarAssignTest extends TestCase
{
    use RefreshDatabase;

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
     * @param  array<int, Technician>  $technicians
     */
    private function createProject(
        string $name,
        array $technicians,
        string $status = 'ongoing',
        bool $onHold = false
    ): Project {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'on_hold' => $onHold,
        ]);

        foreach ($technicians as $technician) {
            ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
            ]);
        }

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

    public function test_date_details_returns_projects_covering_that_day(): void
    {
        $tech = $this->createTechnician('Jose Garcia');
        $covering = $this->createProject('Covering Project', [$tech]);
        $this->bookProject($covering, $this->day(5), $this->day(9));

        $other = $this->createProject('Elsewhere Project', [$this->createTechnician('Ana Mendoza')]);
        $this->bookProject($other, $this->day(20), $this->day(21));

        // Day 7 sits inside the first booking but outside the second.
        $response = $this->getJson(route('super-admin.schedules.date', $this->day(7)));

        $response->assertOk();
        $response->assertJsonPath('date', $this->day(7));
        $response->assertJsonCount(1, 'projects');
        $response->assertJsonPath('projects.0.name', 'Covering Project');
        $response->assertJsonPath('projects.0.technicians.0', 'Jose Garcia');
    }

    public function test_assignable_excludes_projects_by_status_and_hold(): void
    {
        $tech = $this->createTechnician('Free Tech');

        $this->createProject('Ongoing Project', [$tech]);
        $this->createProject('Pending Project', [$this->createTechnician('T1')], 'pending');
        $this->createProject('Completed Project', [$this->createTechnician('T2')], 'completed');
        $this->createProject('Cancelled Project', [$this->createTechnician('T3')], 'cancelled');
        $this->createProject('On Hold Project', [$this->createTechnician('T5')], 'ongoing', true);

        $response = $this->getJson(route('super-admin.schedules.assignable', [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
        ]));

        $response->assertOk();

        $names = collect($response->json('projects'))->pluck('name')->all();
        $blockedNames = collect($response->json('blocked'))->pluck('name')->all();
        $allReturned = array_merge($names, $blockedNames);

        $this->assertContains('Ongoing Project', $names);
        $this->assertContains('Pending Project', $names);
        $this->assertNotContains('Completed Project', $allReturned);
        $this->assertNotContains('Cancelled Project', $allReturned);
        $this->assertNotContains('On Hold Project', $allReturned);
    }

    /**
     * A not-yet-scheduled project is the main thing you would want to book
     * from the calendar - restoring an archived project leaves it in exactly
     * that state, with no schedule at all.
     */
    public function test_not_yet_scheduled_projects_can_be_booked_from_the_calendar(): void
    {
        $tech = $this->createTechnician('Free Tech');
        $fresh = $this->createProject('Not Yet Scheduled', [$tech], 'not_yet_scheduled');

        $response = $this->getJson(route('super-admin.schedules.assignable', [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
        ]));

        $response->assertOk();
        $this->assertContains(
            'Not Yet Scheduled',
            collect($response->json('projects'))->pluck('name')->all()
        );

        $save = $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
            'project_ids' => [$fresh->project_id],
        ]);

        $save->assertOk();

        $schedule = Schedule::where('project_id', $fresh->project_id)->first();
        $this->assertNotNull($schedule);

        // Booking it promotes the project off not_yet_scheduled.
        $this->assertSame('pending', $fresh->fresh()->status);
    }

    /**
     * A restored project has no technicians either, so it must still be
     * bookable - availability has nobody to conflict with.
     */
    public function test_a_restored_project_with_no_technicians_can_be_booked(): void
    {
        $bare = Project::create([
            'name' => 'Restored Project',
            'reference_no' => 'REF-RESTORED',
            'status' => 'not_yet_scheduled',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $response = $this->getJson(route('super-admin.schedules.assignable', [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
        ]));

        $response->assertOk();
        $this->assertContains(
            'Restored Project',
            collect($response->json('projects'))->pluck('name')->all()
        );

        $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
            'project_ids' => [$bare->project_id],
        ])->assertOk();

        $this->assertSame(1, Schedule::where('project_id', $bare->project_id)->count());
    }

    /**
     * The headline rule: a project whose technician is booked on a day in the
     * MIDDLE of the requested range must not be offered, even though both
     * endpoints are free.
     */
    public function test_assignable_rejects_a_project_busy_only_mid_range(): void
    {
        $shared = $this->createTechnician('Ana Mendoza');

        $candidate = $this->createProject('Candidate Project', [$shared]);

        // Ana is booked on day 7 only, via a different project.
        $blocker = $this->createProject('Blocking Project', [$shared]);
        $this->bookProject($blocker, $this->day(7), $this->day(7));

        $response = $this->getJson(route('super-admin.schedules.assignable', [
            'start_date' => $this->day(5),
            'end_date' => $this->day(9),
        ]));

        $response->assertOk();

        $eligible = collect($response->json('projects'))->pluck('name')->all();
        $blocked = collect($response->json('blocked'));

        $this->assertNotContains('Candidate Project', $eligible);
        $this->assertTrue(
            $blocked->contains(fn (array $row): bool => $row['name'] === 'Candidate Project'
                && str_contains((string) $row['reason'], 'Ana Mendoza'))
        );
    }

    public function test_assignable_excludes_a_project_already_booked_on_those_dates(): void
    {
        $tech = $this->createTechnician('Busy Tech');
        $project = $this->createProject('Self Booked', [$tech]);
        $this->bookProject($project, $this->day(5), $this->day(6));

        $response = $this->getJson(route('super-admin.schedules.assignable', [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
        ]));

        $response->assertOk();

        $eligible = collect($response->json('projects'))->pluck('name')->all();
        $this->assertNotContains('Self Booked', $eligible);

        $this->assertTrue(
            collect($response->json('blocked'))->contains(
                fn (array $row): bool => $row['name'] === 'Self Booked'
                    && str_contains((string) $row['reason'], 'Already scheduled')
            )
        );
    }

    public function test_assign_creates_the_schedule_and_links_technicians(): void
    {
        $techA = $this->createTechnician('Leo Fernandez');
        $techB = $this->createTechnician('Angelica Cruz');
        $project = $this->createProject('Bookable Project', [$techA, $techB]);

        $response = $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
            'project_ids' => [$project->project_id],
        ]);

        $response->assertOk();

        $schedule = Schedule::where('project_id', $project->project_id)->first();

        $this->assertNotNull($schedule);
        $this->assertSame($this->day(5), CarbonImmutable::parse($schedule->start_datetime)->toDateString());
        $this->assertSame($this->day(6), CarbonImmutable::parse($schedule->end_datetime)->toDateString());
        $this->assertSame(
            2,
            ScheduleTechnician::where('schedule_id', $schedule->schedule_id)->count()
        );
    }

    /**
     * The browser disables projects that share a technician, but a stale page
     * could still post them together. The server must catch it.
     */
    public function test_assign_rejects_two_projects_sharing_a_technician(): void
    {
        $shared = $this->createTechnician('Jose Garcia');
        $alpha = $this->createProject('Project Alpha', [$shared, $this->createTechnician('Mark')]);
        $beta = $this->createProject('Project Beta', [$shared, $this->createTechnician('Sarah')]);

        $response = $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
            'project_ids' => [$alpha->project_id, $beta->project_id],
        ]);

        $response->assertStatus(422);

        // Nothing may be written when any project in the batch fails.
        $this->assertSame(0, Schedule::count());
        $this->assertSame(0, ScheduleTechnician::count());
    }

    public function test_assign_rejects_a_range_with_a_busy_middle_day(): void
    {
        $tech = $this->createTechnician('Ana Mendoza');
        $candidate = $this->createProject('Candidate Project', [$tech]);

        $blocker = $this->createProject('Blocking Project', [$tech]);
        $this->bookProject($blocker, $this->day(7), $this->day(7));

        $response = $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(5),
            'end_date' => $this->day(9),
            'project_ids' => [$candidate->project_id],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('is unavailable on', $response->json('error'));
        $this->assertSame(1, Schedule::count()); // only the blocker's own booking
    }

    public function test_assign_rejects_read_only_and_on_hold_projects(): void
    {
        $completed = $this->createProject('Completed Project', [$this->createTechnician('T1')], 'completed');
        $onHold = $this->createProject('On Hold Project', [$this->createTechnician('T2')], 'ongoing', true);

        $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
            'project_ids' => [$completed->project_id],
        ])->assertStatus(422);

        $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
            'project_ids' => [$onHold->project_id],
        ])->assertStatus(422);

        $this->assertSame(0, Schedule::count());
    }

    /**
     * These endpoints must always answer with JSON - the app only renders
     * exceptions as JSON for api/* paths, so validation is done by hand.
     */
    public function test_validation_failures_return_json_not_a_redirect(): void
    {
        $project = $this->createProject('Any Project', [$this->createTechnician('T1')]);

        $past = $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => CarbonImmutable::today()->subDays(3)->toDateString(),
            'end_date' => CarbonImmutable::today()->toDateString(),
            'project_ids' => [$project->project_id],
        ]);

        $past->assertStatus(422);
        $this->assertSame('The start date cannot be in the past.', $past->json('error'));

        $empty = $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
            'project_ids' => [],
        ]);

        $empty->assertStatus(422);
        $this->assertSame('Select at least one project to schedule.', $empty->json('error'));

        $badRange = $this->getJson(route('super-admin.schedules.assignable', [
            'start_date' => $this->day(9),
            'end_date' => $this->day(5),
        ]));

        $badRange->assertStatus(422);
        $this->assertNotNull($badRange->json('error'));
    }
}
