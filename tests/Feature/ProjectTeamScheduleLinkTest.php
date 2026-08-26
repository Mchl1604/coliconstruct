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
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A team member is only booked when they hold a row on the project's
 * schedules: that join is the only thing TechnicianAvailabilityService reads.
 *
 * The assigned-team editor used to write the team row alone, which left the
 * technician on the project and yet free for its dates - so they could be
 * booked onto a second project over the same days. Every screen that changes a
 * team now goes through ProjectTeam, and these tests hold all of them to it.
 */
class ProjectTeamScheduleLinkTest extends TestCase
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
            'reference_no' => 'PRJ-'.strtoupper(substr(md5(uniqid()), 0, 8)),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
        ]);
    }

    private function addRange(Project $project, string $startDate, string $endDate): Schedule
    {
        return Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $startDate.' 00:00:00',
            'end_datetime' => $endDate.' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    private function scheduleLinkCount(Schedule $schedule, Technician $technician): int
    {
        return ScheduleTechnician::query()
            ->where('schedule_id', $schedule->schedule_id)
            ->whereIn(
                'project_technician_id',
                ProjectTechnician::query()
                    ->where('technician_id', $technician->technician_id)
                    ->pluck('project_technician_id')
            )
            ->count();
    }

    public function test_adding_a_technician_books_them_onto_every_existing_schedule(): void
    {
        $project = $this->createProject();
        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $joiner = $this->createTechnician('Later Joiner');

        $first = $this->addRange($project, $this->day(5), $this->day(7));
        $second = $this->addRange($project, $this->day(12), $this->day(14));

        $response = $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$joiner->technician_id],
        ]);

        $response->assertSessionHas('success');

        foreach ([$first, $second] as $schedule) {
            $this->assertSame(1, $this->scheduleLinkCount($schedule, $lead));
            $this->assertSame(1, $this->scheduleLinkCount($schedule, $joiner));
        }
    }

    // ------------------------------------------------------------------
    // Ranges that have already ended
    // ------------------------------------------------------------------

    /**
     * Joining a team is a decision about the work still to come, so it books
     * the dates still to come. A row on a week the project finished last month
     * would say the newcomer was on site for it.
     */
    public function test_adding_a_technician_does_not_book_them_onto_a_finished_range(): void
    {
        $project = $this->createProject();
        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $joiner = $this->createTechnician('Later Joiner');

        $finished = $this->addRange($project, $this->day(-16), $this->day(-14));
        $upcoming = $this->addRange($project, $this->day(12), $this->day(14));

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$joiner->technician_id],
        ])->assertSessionHas('success');

        foreach ([$lead, $joiner] as $technician) {
            $this->assertSame(0, $this->scheduleLinkCount($finished, $technician));
            $this->assertSame(1, $this->scheduleLinkCount($upcoming, $technician));
        }
    }

    /**
     * A range that began before today has not ended, so the joiner is booked
     * onto it - the days it has left are a real claim on their diary.
     */
    public function test_adding_a_technician_books_them_onto_a_range_that_is_still_running(): void
    {
        $project = $this->createProject();
        $lead = $this->createTechnician('Lead Person', 'lead_technician');

        $running = $this->addRange($project, $this->day(-2), $this->day(3));

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [],
        ])->assertSessionHas('success');

        $this->assertSame(1, $this->scheduleLinkCount($running, $lead));
    }

    /**
     * The half that makes the whole change hold together. The team editor now
     * accepts somebody whose only clash was on a range that has ended; booking
     * them onto that very range afterwards would hand back the clash the
     * screening just decided did not count.
     */
    public function test_a_technician_accepted_despite_an_old_clash_is_not_booked_into_it(): void
    {
        $project = $this->createProject();
        $finished = $this->addRange($project, $this->day(-16), $this->day(-14));
        $upcoming = $this->addRange($project, $this->day(20), $this->day(21));

        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $wasBusy = $this->createTechnician('Kevin Lopez');

        // Kevin worked somebody else's job over the same old week.
        $elsewhere = $this->createProject();
        $this->addRange($elsewhere, $this->day(-16), $this->day(-14));
        $this->put(route('super-admin.projects.team.update', $elsewhere->project_id), [
            'lead_tech' => $this->createTechnician('Other Lead', 'lead_technician')->technician_id,
            'technicians' => [$wasBusy->technician_id],
        ])->assertSessionHas('success');

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$wasBusy->technician_id],
        ])->assertSessionHas('success');

        // On the team, on the range still to come, and NOT on the old week -
        // where he was somebody else's, and remains only theirs.
        $this->assertSame(1, $this->scheduleLinkCount($upcoming, $wasBusy));
        $this->assertSame(0, $this->scheduleLinkCount($finished, $wasBusy));
    }

    /**
     * attach() adds and never removes, so a member who really did work an
     * earlier range keeps the row that says so.
     */
    public function test_an_existing_link_on_a_finished_range_is_left_alone(): void
    {
        $project = $this->createProject();
        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $veteran = $this->createTechnician('Long Server');

        $upcoming = $this->addRange($project, $this->day(12), $this->day(14));
        $finished = $this->addRange($project, $this->day(-16), $this->day(-14));

        // Both are already on the team and on both ranges - the state a
        // project reaches by having been scheduled while they were on it.
        foreach ([$lead, $veteran] as $technician) {
            $assignment = ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
            ]);

            foreach ([$upcoming, $finished] as $schedule) {
                ScheduleTechnician::create([
                    'schedule_id' => $schedule->schedule_id,
                    'project_technician_id' => $assignment->project_technician_id,
                ]);
            }
        }

        // Saving the team again re-attaches everybody.
        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$veteran->technician_id],
        ])->assertSessionHas('success');

        $this->assertSame(1, $this->scheduleLinkCount($finished, $veteran));
        $this->assertSame(1, $this->scheduleLinkCount($upcoming, $veteran));
    }

    /**
     * The audit and the repair have to draw the same line attach() draws, or
     * the repair puts back every row attach() deliberately declined to write.
     */
    public function test_the_audit_does_not_report_a_missing_link_on_a_finished_range(): void
    {
        $project = $this->createProject();
        $technician = $this->createTechnician('Later Joiner');

        $finished = $this->addRange($project, $this->day(-16), $this->day(-14));

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        Artisan::call('project-team:audit', ['--project' => $project->project_id]);

        $this->assertStringContainsString('Nothing to repair', Artisan::output());

        Artisan::call('project-team:repair', ['--project' => $project->project_id, '--force' => true]);

        $this->assertSame(0, $this->scheduleLinkCount($finished, $technician));
    }

    /**
     * The point of the join rows: somebody added to a scheduled project has to
     * read as busy everywhere else, immediately.
     */
    public function test_a_technician_added_to_a_scheduled_project_is_no_longer_offered_elsewhere(): void
    {
        $booked = $this->createProject();
        $this->addRange($booked, $this->day(5), $this->day(9));

        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $shared = $this->createTechnician('Shared Tech');

        $this->put(route('super-admin.projects.team.update', $booked->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$shared->technician_id],
        ])->assertSessionHas('success');

        // A second project wanting the same technician over the same days.
        $other = $this->createProject();
        $this->addRange($other, $this->day(6), $this->day(8));

        $response = $this->put(route('super-admin.projects.team.update', $other->project_id), [
            'lead_tech' => $this->createTechnician('Other Lead', 'lead_technician')->technician_id,
            'technicians' => [$shared->technician_id],
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('tbl_project_technicians', [
            'project_id' => $other->project_id,
            'technician_id' => $shared->technician_id,
        ]);
    }

    public function test_removing_a_technician_clears_their_schedule_rows_and_releases_open_tasks(): void
    {
        $project = $this->createProject();
        $schedule = $this->addRange($project, $this->day(5), $this->day(7));

        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $leaving = $this->createTechnician('Leaving Tech');

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$leaving->technician_id],
        ])->assertSessionHas('success');

        $open = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $leaving->technician_id,
            'task_title' => 'Open work',
            'task_description' => 'Still to do',
            'status' => 'pending',
            'start_date' => $this->day(5),
            'due_date' => $this->day(6),
        ]);

        $done = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $leaving->technician_id,
            'task_title' => 'Finished work',
            'task_description' => 'Already done',
            'status' => 'completed',
            'start_date' => $this->day(5),
            'due_date' => $this->day(6),
        ]);

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [],
        ])->assertSessionHas('success');

        $this->assertSame(0, $this->scheduleLinkCount($schedule, $leaving));
        // A removal closes the membership rather than deleting it - the row
        // carries the dates that technician worked, and deleting it took them
        // with it. What must be gone is the ASSIGNMENT, so the assertion is
        // that no OPEN membership survives. See ProjectTechnician.
        $this->assertDatabaseMissing('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $leaving->technician_id,
            'removed_at' => null,
        ]);

        // Unfinished work is released; what they already completed is a record
        // of who did it and keeps its technician.
        $this->assertNull($open->fresh()->technician_id);
        $this->assertSame('unassigned', $open->fresh()->status);
        $this->assertSame($leaving->technician_id, $done->fresh()->technician_id);
        $this->assertSame('completed', $done->fresh()->status);
    }

    public function test_a_schedule_added_later_books_the_whole_current_team(): void
    {
        $project = $this->createProject();
        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $member = $this->createTechnician('Team Member');

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$member->technician_id],
        ])->assertSessionHas('success');

        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                ['start_date' => $this->day(3), 'end_date' => $this->day(4)],
            ],
        ])->assertSessionHas('success');

        $schedule = Schedule::where('project_id', $project->project_id)->firstOrFail();

        $this->assertSame(1, $this->scheduleLinkCount($schedule, $lead));
        $this->assertSame(1, $this->scheduleLinkCount($schedule, $member));
    }

    public function test_the_audit_reports_nothing_when_every_link_is_present(): void
    {
        $project = $this->createProject();
        $this->addRange($project, $this->day(5), $this->day(7));

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $this->createTechnician('Lead Person', 'lead_technician')->technician_id,
            'technicians' => [],
        ])->assertSessionHas('success');

        Artisan::call('project-team:audit');

        $this->assertStringContainsString('Nothing to repair', Artisan::output());
    }

    /**
     * The state the old editor left behind, rebuilt by hand: a team row with
     * no schedule row beside it.
     */
    public function test_the_audit_reports_a_team_row_with_no_schedule_row(): void
    {
        $project = $this->createProject();
        $schedule = $this->addRange($project, $this->day(5), $this->day(7));
        $orphan = $this->createTechnician('Orphaned Tech');

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $orphan->technician_id,
        ]);

        Artisan::call('project-team:audit');
        $output = Artisan::output();

        $this->assertStringContainsString('Orphaned Tech', $output);
        $this->assertStringContainsString('1 row would be inserted', $output);
        $this->assertSame(0, $this->scheduleLinkCount($schedule, $orphan));
    }

    /**
     * A double booking the missing rows are hiding has to be named before the
     * repair runs, not discovered afterwards.
     */
    public function test_the_audit_names_conflicts_the_repair_would_expose(): void
    {
        $shared = $this->createTechnician('Double Booked');

        $hidden = $this->createProject();
        $this->addRange($hidden, $this->day(5), $this->day(9));
        ProjectTechnician::create([
            'project_id' => $hidden->project_id,
            'technician_id' => $shared->technician_id,
        ]);

        $visible = $this->createProject();
        $visibleSchedule = $this->addRange($visible, $this->day(6), $this->day(8));
        $assignment = ProjectTechnician::create([
            'project_id' => $visible->project_id,
            'technician_id' => $shared->technician_id,
        ]);
        ScheduleTechnician::create([
            'schedule_id' => $visibleSchedule->schedule_id,
            'project_technician_id' => $assignment->project_technician_id,
        ]);

        Artisan::call('project-team:audit');
        $output = Artisan::output();

        $this->assertStringContainsString('Conflicts the repair would expose', $output);
        $this->assertStringContainsString($hidden->reference_no, $output);
        $this->assertStringContainsString($visible->reference_no, $output);
    }

    public function test_the_audit_writes_nothing(): void
    {
        $project = $this->createProject();
        $this->addRange($project, $this->day(5), $this->day(7));

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $this->createTechnician('Orphaned Tech')->technician_id,
        ]);

        Artisan::call('project-team:audit');

        $this->assertSame(0, ScheduleTechnician::count());
    }

    public function test_the_repair_books_the_orphaned_member_onto_every_schedule(): void
    {
        $project = $this->createProject();
        $first = $this->addRange($project, $this->day(5), $this->day(7));
        $second = $this->addRange($project, $this->day(12), $this->day(14));
        $orphan = $this->createTechnician('Orphaned Tech');

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $orphan->technician_id,
        ]);

        Artisan::call('project-team:repair', ['--force' => true]);

        $this->assertStringContainsString('Inserted 2 rows', Artisan::output());
        $this->assertSame(1, $this->scheduleLinkCount($first, $orphan));
        $this->assertSame(1, $this->scheduleLinkCount($second, $orphan));
    }

    /**
     * The repaired technician has to be busy afterwards - that is the whole
     * point of the rows.
     */
    public function test_a_repaired_technician_reads_as_busy_elsewhere(): void
    {
        $booked = $this->createProject();
        $this->addRange($booked, $this->day(5), $this->day(9));
        $shared = $this->createTechnician('Shared Tech');

        ProjectTechnician::create([
            'project_id' => $booked->project_id,
            'technician_id' => $shared->technician_id,
        ]);

        Artisan::call('project-team:repair', ['--force' => true]);

        $other = $this->createProject();
        $this->addRange($other, $this->day(6), $this->day(8));

        $this->put(route('super-admin.projects.team.update', $other->project_id), [
            'lead_tech' => $this->createTechnician('Other Lead', 'lead_technician')->technician_id,
            'technicians' => [$shared->technician_id],
        ])->assertSessionHas('error');
    }

    public function test_the_repair_dry_run_writes_nothing(): void
    {
        $project = $this->createProject();
        $this->addRange($project, $this->day(5), $this->day(7));

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $this->createTechnician('Orphaned Tech')->technician_id,
        ]);

        Artisan::call('project-team:repair', ['--dry-run' => true, '--force' => true]);

        $this->assertStringContainsString('this was a dry run', Artisan::output());
        $this->assertSame(0, ScheduleTechnician::count());
    }

    public function test_the_repair_is_safe_to_run_twice(): void
    {
        $project = $this->createProject();
        $this->addRange($project, $this->day(5), $this->day(7));

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $this->createTechnician('Orphaned Tech')->technician_id,
        ]);

        Artisan::call('project-team:repair', ['--force' => true]);
        Artisan::call('project-team:repair', ['--force' => true]);

        $this->assertStringContainsString('Nothing to repair', Artisan::output());
        $this->assertSame(1, ScheduleTechnician::count());
    }
}
