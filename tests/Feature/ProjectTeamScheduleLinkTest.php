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
        $this->assertDatabaseMissing('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $leaving->technician_id,
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
}
