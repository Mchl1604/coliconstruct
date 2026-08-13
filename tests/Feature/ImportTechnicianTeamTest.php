<?php

namespace Tests\Feature;

use App\Models\Notification;
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
 * Importing a technician team from another project.
 *
 * The endpoint only ever reads: it says which projects have a team, and
 * whether the people on it are free for the dates the destination holds. What
 * happens next is the picker's business, and saving still goes through the
 * assigned-team editor and the wizard exactly as a hand-picked team does.
 */
class ImportTechnicianTeamTest extends TestCase
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
        string $name,
        array $technicians = [],
        string $status = 'ongoing'
    ): Project {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'PRJ-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $project->clients()->create([
            'client_type' => 'Commercial',
            'firstname' => 'Client',
            'fullname' => 'Client Of '.$name,
            'company_name' => $name.' Holdings',
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

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function sources(array $params)
    {
        return $this->getJson(route('super-admin.projects.importable-teams', $params));
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     * @return array<string, mixed>|null
     */
    private function find(array $projects, string $name): ?array
    {
        foreach ($projects as $project) {
            if ($project['name'] === $name) {
                return $project;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Which projects are offered
    // ------------------------------------------------------------------

    public function test_it_lists_projects_that_have_a_team(): void
    {
        $source = $this->createProject('Source Project', [$this->createTechnician('Jose Garcia')]);
        $this->createProject('Teamless Project');

        $destination = $this->createProject('Destination Project', [$this->createTechnician('Ana Mendoza')]);
        $this->book($destination, $this->day(5), $this->day(6));

        $response = $this->sources(['project_id' => $destination->project_id]);

        $response->assertOk();

        $projects = $response->json('projects');

        $this->assertNotNull($this->find($projects, 'Source Project'));
        $this->assertNull($this->find($projects, 'Teamless Project'));
        $this->assertNull($this->find($projects, 'Destination Project'), 'A project is not a source for its own team.');
        $this->assertSame($source->reference_no, $this->find($projects, 'Source Project')['reference_no']);
    }

    public function test_completed_and_cancelled_projects_are_offered_but_grouped_apart(): void
    {
        $destination = $this->createProject('Destination Project', [$this->createTechnician('Ana Mendoza')]);
        $this->book($destination, $this->day(5), $this->day(6));

        $this->createProject('Live Project', [$this->createTechnician('Jose Garcia')]);
        $this->createProject('Finished Project', [$this->createTechnician('Pedro Reyes')], 'completed');
        $this->createProject('Dropped Project', [$this->createTechnician('Maria Santos')], 'cancelled');

        $projects = $this->sources(['project_id' => $destination->project_id])->json('projects');

        $this->assertSame('active', $this->find($projects, 'Live Project')['group']);
        $this->assertSame('closed', $this->find($projects, 'Finished Project')['group']);
        $this->assertSame('closed', $this->find($projects, 'Dropped Project')['group']);
    }

    public function test_archived_projects_are_never_offered(): void
    {
        $destination = $this->createProject('Destination Project', [$this->createTechnician('Ana Mendoza')]);
        $this->book($destination, $this->day(5), $this->day(6));

        $archived = $this->createProject('Archived Project', [$this->createTechnician('Jose Garcia')]);
        $archived->update(['is_archived' => true, 'status' => 'archived']);

        $projects = $this->sources(['project_id' => $destination->project_id])->json('projects');

        $this->assertNull($this->find($projects, 'Archived Project'));
    }

    public function test_each_project_carries_what_the_dialog_shows(): void
    {
        $destination = $this->createProject('Destination Project', [$this->createTechnician('Ana Mendoza')]);
        $this->book($destination, $this->day(5), $this->day(6));

        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $source = $this->createProject('Source Project', [$lead, $this->createTechnician('Jose Garcia')]);
        $this->book($source, $this->day(20), $this->day(22));

        $payload = $this->find(
            $this->sources(['project_id' => $destination->project_id])->json('projects'),
            'Source Project'
        );

        $this->assertSame('Client Of Source Project', $payload['client']);
        $this->assertSame('Lead Person', $payload['lead']['name']);
        $this->assertCount(2, $payload['technicians']);
        $this->assertStringContainsString(
            CarbonImmutable::parse($this->day(20))->format('M j, Y'),
            $payload['schedule_label']
        );
        $this->assertSame($source->statusLabel(), $payload['status_label']);
    }

    public function test_a_source_with_no_schedule_says_so(): void
    {
        $destination = $this->createProject('Destination Project', [$this->createTechnician('Ana Mendoza')]);
        $this->book($destination, $this->day(5), $this->day(6));

        $this->createProject('Unbooked Project', [$this->createTechnician('Jose Garcia')], 'unscheduled');

        $payload = $this->find(
            $this->sources(['project_id' => $destination->project_id])->json('projects'),
            'Unbooked Project'
        );

        $this->assertSame('No schedule set', $payload['schedule_label']);
    }

    // ------------------------------------------------------------------
    // Availability against the destination's dates
    // ------------------------------------------------------------------

    public function test_a_team_free_for_the_destination_dates_is_available(): void
    {
        $destination = $this->createProject('Destination Project', [$this->createTechnician('Ana Mendoza')]);
        $this->book($destination, $this->day(5), $this->day(6));

        $source = $this->createProject('Free Team', [$this->createTechnician('Jose Garcia')]);
        $this->book($source, $this->day(20), $this->day(22));

        $payload = $this->find(
            $this->sources(['project_id' => $destination->project_id])->json('projects'),
            'Free Team'
        );

        $this->assertTrue($payload['available']);
        $this->assertSame([], $payload['unavailable']);
        $this->assertTrue($payload['technicians'][0]['available']);
    }

    public function test_a_clashing_technician_is_named_with_the_reason(): void
    {
        $destination = $this->createProject('Destination Project', [$this->createTechnician('Ana Mendoza')]);
        $this->book($destination, $this->day(5), $this->day(9));

        $busy = $this->createTechnician('Busy Tech');
        $free = $this->createTechnician('Free Tech');

        // The source team, one of whom is booked elsewhere over the
        // destination's dates.
        $source = $this->createProject('Mixed Team', [$busy, $free]);
        $elsewhere = $this->createProject('Elsewhere', [$busy]);
        $this->book($elsewhere, $this->day(6), $this->day(7));

        $payload = $this->find(
            $this->sources(['project_id' => $destination->project_id])->json('projects'),
            'Mixed Team'
        );

        $this->assertFalse($payload['available'], 'One clash makes the project unavailable as a whole.');
        $this->assertCount(1, $payload['unavailable']);
        $this->assertSame('Busy Tech', $payload['unavailable'][0]['name']);
        $this->assertStringContainsString('Booked on', $payload['unavailable'][0]['reason']);

        // The rest of the team is still importable - the user may take the
        // available subset.
        $stillFree = collect($payload['technicians'])->firstWhere('name', 'Free Tech');
        $this->assertTrue($stillFree['available']);
    }

    /**
     * The destination's own bookings are not a reason to refuse its own team.
     */
    public function test_the_destinations_own_dates_do_not_count_against_a_source(): void
    {
        $shared = $this->createTechnician('Shared Tech');

        $destination = $this->createProject('Destination Project', [$shared]);
        $this->book($destination, $this->day(5), $this->day(9));

        $source = $this->createProject('Source Project', [$shared]);

        $payload = $this->find(
            $this->sources(['project_id' => $destination->project_id])->json('projects'),
            'Source Project'
        );

        $this->assertTrue($payload['available']);
    }

    public function test_a_partial_day_destination_only_blocks_its_own_hours(): void
    {
        $destination = $this->createProject('Destination Project', [$this->createTechnician('Ana Mendoza')]);

        Schedule::create([
            'project_id' => $destination->project_id,
            'start_datetime' => $this->day(5).' 08:00:00',
            'end_datetime' => $this->day(5).' 12:00:00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        $afternoonTech = $this->createTechnician('Afternoon Tech');
        $this->createProject('Afternoon Team', [$afternoonTech]);

        $elsewhere = $this->createProject('Elsewhere', [$afternoonTech]);
        Schedule::create([
            'project_id' => $elsewhere->project_id,
            'start_datetime' => $this->day(5).' 13:00:00',
            'end_datetime' => $this->day(5).' 17:00:00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);
        $elsewhere->projectTechnicians()->get()->each(function (ProjectTechnician $assignment) use ($elsewhere): void {
            ScheduleTechnician::create([
                'schedule_id' => $elsewhere->schedules()->first()->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        });

        $payload = $this->find(
            $this->sources(['project_id' => $destination->project_id])->json('projects'),
            'Afternoon Team'
        );

        $this->assertTrue($payload['available'], 'A morning booking leaves the afternoon free.');
    }

    // ------------------------------------------------------------------
    // The wizard, which has no project yet
    // ------------------------------------------------------------------

    public function test_the_wizard_screens_against_the_schedule_it_is_about_to_save(): void
    {
        $busy = $this->createTechnician('Busy Tech');
        $this->createProject('Busy Team', [$busy]);

        $elsewhere = $this->createProject('Elsewhere', [$busy]);
        $this->book($elsewhere, $this->day(5), $this->day(9));

        $projects = $this->sources([
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(6),
            'end_date' => $this->day(7),
        ])->json('projects');

        $payload = $this->find($projects, 'Busy Team');

        $this->assertFalse($payload['available']);
        $this->assertSame('Busy Tech', $payload['unavailable'][0]['name']);
    }

    /**
     * The wizard's schedule fields stay disabled until a team exists, so a
     * project being created almost always asks this before it has any dates.
     * With none there is nothing to clash with, and every team is offered -
     * the wizard flags anyone who clashes as the dates go in, and
     * StoreProjectRequest refuses a team that does not fit them.
     */
    public function test_the_wizard_offers_every_team_before_its_dates_are_chosen(): void
    {
        $busy = $this->createTechnician('Busy Tech');
        $this->createProject('Busy Team', [$busy]);

        $elsewhere = $this->createProject('Elsewhere', [$busy]);
        $this->book($elsewhere, $this->day(5), $this->day(9));

        $projects = $this->sources([])->json('projects');

        $payload = $this->find($projects, 'Busy Team');

        $this->assertNotNull($payload);
        $this->assertTrue($payload['available']);
        $this->assertSame([], $payload['unavailable']);
    }

    /**
     * A schedule that cannot be read is a different thing from no schedule at
     * all, and is refused rather than quietly screened against nothing.
     */
    public function test_an_unreadable_schedule_is_refused(): void
    {
        $this->createProject('Some Team', [$this->createTechnician('Jose Garcia')]);

        // An end date before the start date: filled in, but meaningless.
        $this->sources([
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(9),
            'end_date' => $this->day(5),
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Who may ask
    // ------------------------------------------------------------------

    public function test_an_admin_may_list_importable_teams(): void
    {
        $admin = User::factory()->create(['email' => 'plain.admin@example.test']);
        $admin->forceFill(['role' => 'admin', 'status' => User::STATUS_ACTIVE])->save();

        $destination = $this->createProject('Destination Project', [$this->createTechnician('Ana Mendoza')]);
        $this->book($destination, $this->day(5), $this->day(6));
        $this->createProject('Source Project', [$this->createTechnician('Jose Garcia')]);

        $this->actingAs($admin);

        $this->sources(['project_id' => $destination->project_id])->assertOk();
    }

    public function test_a_technician_may_not_list_importable_teams(): void
    {
        $account = User::factory()->create(['email' => 'plain.tech@example.test']);
        $account->forceFill(['role' => 'technician', 'status' => User::STATUS_ACTIVE])->save();

        $this->actingAs($account);

        $this->sources(['project_id' => 1])->assertForbidden();
    }

    // ------------------------------------------------------------------
    // The dialog is offered where it should be
    // ------------------------------------------------------------------

    public function test_the_assigned_team_editor_offers_the_import_dialog(): void
    {
        $project = $this->createProject('Destination Project', [$this->createTechnician('Ana Mendoza')]);
        $this->book($project, $this->day(5), $this->day(6));

        $response = $this->get(route('super-admin.projects.show', $project->project_id));

        $response->assertOk();
        $response->assertSee('Import Team');
        $response->assertSee('data-import-team-modal', false);
        $response->assertSee('/js/importTeam.js', false);
    }

    public function test_the_project_wizard_offers_the_import_dialog(): void
    {
        $response = $this->get(route('super-admin.projects.create'));

        $response->assertOk();
        $response->assertSee('Import Team');
        $response->assertSee('data-import-team-modal', false);
        $response->assertSee('data-import-team-button', false);
        // Offered from the start: the schedule fields below cannot be filled
        // in until a team exists, so waiting for dates would mean picking by
        // hand the very team this is meant to save picking.
        $this->assertStringNotContainsString(
            'data-import-team-button disabled',
            $response->getContent()
        );
    }

    // ------------------------------------------------------------------
    // Saving an imported team is saving a team
    // ------------------------------------------------------------------

    public function test_an_imported_team_saves_through_the_existing_editor(): void
    {
        $destination = $this->createProject('Destination Project', [$this->createTechnician('Ana Mendoza')]);
        $this->book($destination, $this->day(5), $this->day(6));

        $importedLead = $this->createTechnician('Imported Lead', 'lead_technician');
        $importedTech = $this->createTechnician('Imported Tech');
        $this->createProject('Source Project', [$importedLead, $importedTech]);

        $this->put(route('super-admin.projects.team.update', $destination->project_id), [
            'lead_tech' => $importedLead->technician_id,
            'technicians' => [$importedTech->technician_id],
        ])->assertSessionHas('success');

        // The imported team is on the project, and booked onto its dates.
        foreach ([$importedLead, $importedTech] as $technician) {
            $this->assertDatabaseHas('tbl_project_technicians', [
                'project_id' => $destination->project_id,
                'technician_id' => $technician->technician_id,
            ]);
        }

        $schedule = $destination->schedules()->firstOrFail();
        $this->assertSame(
            2,
            ScheduleTechnician::where('schedule_id', $schedule->schedule_id)->count()
        );
    }

    /**
     * Replacing the lead takes the old one off the team, because a project's
     * lead is simply the member whose account role says so - there is no
     * demoting one in place.
     */
    public function test_using_the_imported_lead_removes_the_current_one(): void
    {
        $currentLead = $this->createTechnician('Current Lead', 'lead_technician');
        $destination = $this->createProject('Destination Project', [$currentLead]);
        $this->book($destination, $this->day(5), $this->day(6));

        $importedLead = $this->createTechnician('Imported Lead', 'lead_technician');
        $this->createProject('Source Project', [$importedLead]);

        $this->put(route('super-admin.projects.team.update', $destination->project_id), [
            'lead_tech' => $importedLead->technician_id,
            'technicians' => [],
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $destination->project_id,
            'technician_id' => $importedLead->technician_id,
        ]);

        $this->assertDatabaseMissing('tbl_project_technicians', [
            'project_id' => $destination->project_id,
            'technician_id' => $currentLead->technician_id,
        ]);
    }

    /**
     * Work does not leave with the person holding it.
     */
    public function test_removing_a_technician_reports_the_work_they_leave_behind(): void
    {
        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $leaving = $this->createTechnician('Leaving Tech');

        $project = $this->createProject('Destination Project', [$lead, $leaving]);
        $this->book($project, $this->day(5), $this->day(9));

        $open = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $leaving->technician_id,
            'task_title' => 'Fit the ducting',
            'task_description' => 'Unfinished',
            'status' => 'pending',
            'start_date' => $this->day(5),
            'due_date' => $this->day(6),
        ]);

        $done = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $leaving->technician_id,
            'task_title' => 'Test the unit',
            'task_description' => 'Finished',
            'status' => 'completed',
            'start_date' => $this->day(5),
            'due_date' => $this->day(6),
        ]);

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [],
        ])->assertSessionHas('success');

        $this->assertNull($open->fresh()->technician_id);
        $this->assertSame('unassigned', $open->fresh()->status);
        $this->assertSame($leaving->technician_id, $done->fresh()->technician_id);

        $this->assertDatabaseHas('tbl_notifications', [
            'user_id' => $lead->account_id,
            'title' => 'Tasks Left Unassigned',
        ]);

        $notification = Notification::where('title', 'Tasks Left Unassigned')->first();
        $this->assertStringContainsString('Leaving Tech', $notification->message);
        $this->assertStringContainsString('Fit the ducting', $notification->message);
    }

    public function test_removing_a_technician_holding_no_work_raises_no_notification(): void
    {
        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $leaving = $this->createTechnician('Leaving Tech');

        $project = $this->createProject('Destination Project', [$lead, $leaving]);
        $this->book($project, $this->day(5), $this->day(9));

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [],
        ])->assertSessionHas('success');

        $this->assertDatabaseMissing('tbl_notifications', ['title' => 'Tasks Left Unassigned']);
    }
}
