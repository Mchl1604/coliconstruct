<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Services\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Activity Logs section at the foot of Project Information.
 *
 * It is a view onto the existing audit trail narrowed to one job, not a second
 * record of one: the rows come from tbl_activity_logs through
 * ActivityLog::scopeForProject() and nothing on this page writes an entry.
 *
 * What these pin is the narrowing. A project's own entries appear, the work
 * done on it appears, and another project's entries never do - which is the
 * failure that would matter, because an id on its own is not unique across the
 * things an entry can be filed against.
 */
class ProjectActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private Technician $technician;

    private User $technicianAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();

        $this->technicianAccount = User::create([
            'user_code' => 'EMP-0200',
            'name' => 'Tess Technician',
            'first_name' => 'Tess',
            'last_name' => 'Technician',
            'email' => 'tess.technician@example.test',
            'role' => 'technician',
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);

        $this->technician = Technician::create([
            'account_id' => $this->technicianAccount->id,
            'role' => 'technician',
        ]);
    }

    private function createProject(string $reference): Project
    {
        $project = Project::create([
            'name' => 'Project '.$reference,
            'reference_no' => $reference,
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Some Holdings',
            'firstname' => 'Client',
            'surname' => strtoupper($reference),
            'fullname' => 'Client '.$reference,
            'email_address' => strtolower($reference).'@example.test',
            'contact_number' => '09123456789',
        ]);

        return $project;
    }

    /**
     * One entry, filed against whatever record it was about - the same shape
     * ActivityLogger writes.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function log(string $recordType, int $recordId, string $description, array $overrides = []): ActivityLog
    {
        return ActivityLog::create(array_merge([
            'actor_id' => null,
            'actor_name' => 'Andy Admin',
            'actor_role' => 'admin',
            'action' => ActivityLog::PROJECT_UPDATED,
            'module' => ActivityLog::MODULE_PROJECTS,
            'description' => $description,
            'record_type' => $recordType,
            'record_id' => $recordId,
        ], $overrides));
    }

    private function page(Project $project)
    {
        return $this->get(route('super-admin.projects.show', $project->project_id));
    }

    // ------------------------------------------------------------------
    // What the section shows
    // ------------------------------------------------------------------

    public function test_it_shows_the_activities_recorded_against_this_project(): void
    {
        $project = $this->createProject('REF-ACT-1');

        $this->log('Project', $project->project_id, 'Updated the details of this very project.');

        $this->page($project)
            ->assertOk()
            ->assertSee('Activity Logs')
            ->assertSee('Updated the details of this very project.')
            ->assertDontSee('No activity recorded for this project.');
    }

    /**
     * The date, the person and the sentence - the three things the section
     * exists to answer.
     */
    public function test_each_entry_names_when_who_and_what(): void
    {
        $project = $this->createProject('REF-ACT-2');

        $entry = $this->log('Project', $project->project_id, 'Put this project on hold.', [
            'action' => ActivityLog::PROJECT_PUT_ON_HOLD,
        ]);

        $entry->forceFill(['created_at' => CarbonImmutable::parse('2026-03-04 09:30:00')])->save();

        $this->page($project)
            ->assertOk()
            ->assertSee('Mar 4, 2026 9:30 AM')
            ->assertSee('Andy Admin')
            ->assertSee(ActivityLog::PROJECT_PUT_ON_HOLD)
            ->assertSee('Put this project on hold.');
    }

    /**
     * An action with nobody signed in behind it is stored as "System" by
     * ActivityLogger, and reads that way rather than as a blank cell.
     */
    public function test_an_entry_with_no_user_reads_as_system(): void
    {
        $project = $this->createProject('REF-ACT-3');

        $this->log('Project', $project->project_id, 'Completed automatically after a week.', [
            'actor_name' => 'System',
            'actor_role' => null,
            'action' => ActivityLog::PROJECT_AUTO_COMPLETED,
        ]);

        $this->page($project)
            ->assertOk()
            ->assertSee('Completed automatically after a week.')
            ->assertSee('System');
    }

    /**
     * The work done ON a project is filed against the task, report or phase it
     * happened to. A reader looking at one job wants those too, and
     * scopeForProject() follows the pointer one step to find them.
     */
    public function test_it_shows_work_recorded_against_this_projects_own_records(): void
    {
        $project = $this->createProject('REF-ACT-4');
        $phase = $this->finalizePhases($project)->first();

        $task = Task::create([
            'project_id' => $project->project_id,
            'phase_id' => $phase->phase_id,
            'technician_id' => $this->technician->technician_id,
            'task_title' => 'Pull the wiring',
            'task_description' => 'Run the trunking along the east wall.',
            'start_date' => CarbonImmutable::today()->toDateString(),
            'due_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'status' => 'pending',
        ]);

        $report = TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $this->technician->technician_id,
            'submitted_by' => $this->technicianAccount->id,
            'report_type' => 'progress',
            'report_title' => 'First visit',
            'report_description' => 'Trunking done.',
            'report_date' => CarbonImmutable::today()->toDateString(),
        ]);

        $this->log('Task', $task->task_id, 'Created a task on the project being read.', [
            'action' => ActivityLog::TASK_CREATED,
            'module' => ActivityLog::MODULE_TASKS,
        ]);
        $this->log('TechnicianReport', $report->id, 'Filed a progress report on the project being read.', [
            'action' => ActivityLog::REPORT_GENERATED,
            'module' => ActivityLog::MODULE_REPORTS,
        ]);
        $this->log('ProjectPhase', $phase->phase_id, 'Completed a phase on the project being read.', [
            'action' => ActivityLog::PROJECT_PHASE_COMPLETED,
        ]);

        $this->page($project)
            ->assertOk()
            ->assertSee('Created a task on the project being read.')
            ->assertSee('Filed a progress report on the project being read.')
            ->assertSee('Completed a phase on the project being read.');
    }

    /**
     * Newest first, the order the Activity Logs page reads in.
     */
    public function test_it_lists_the_newest_entry_first(): void
    {
        $project = $this->createProject('REF-ACT-5');

        $older = $this->log('Project', $project->project_id, 'The older thing that happened.');
        $newer = $this->log('Project', $project->project_id, 'The newer thing that happened.');

        $older->forceFill(['created_at' => CarbonImmutable::parse('2026-01-01 08:00:00')])->save();
        $newer->forceFill(['created_at' => CarbonImmutable::parse('2026-02-01 08:00:00')])->save();

        $body = $this->page($project)->assertOk()->getContent();

        $this->assertLessThan(
            strpos($body, 'The older thing that happened.'),
            strpos($body, 'The newer thing that happened.'),
            'The newest entry should be listed first.'
        );
    }

    // ------------------------------------------------------------------
    // What it must never show
    // ------------------------------------------------------------------

    public function test_it_never_shows_another_projects_activities(): void
    {
        $project = $this->createProject('REF-ACT-6');
        $other = $this->createProject('REF-ACT-7');

        $this->log('Project', $project->project_id, 'Something on the project being read.');
        $this->log('Project', $other->project_id, 'Something on the OTHER project entirely.');

        $this->page($project)
            ->assertOk()
            ->assertSee('Something on the project being read.')
            ->assertDontSee('Something on the OTHER project entirely.');
    }

    /**
     * The failure worth guarding: ids are only unique within a table, so a
     * task numbered the same as this project must not be read as belonging to
     * it - nor a task that belongs to a different job.
     */
    public function test_it_never_shows_work_recorded_against_another_projects_records(): void
    {
        $project = $this->createProject('REF-ACT-8');
        $other = $this->createProject('REF-ACT-9');

        $otherPhase = $this->finalizePhases($other)->first();

        $otherTask = Task::create([
            'project_id' => $other->project_id,
            'phase_id' => $otherPhase->phase_id,
            'technician_id' => $this->technician->technician_id,
            'task_title' => 'Wiring on another job',
            'task_description' => 'On a different job.',
            'start_date' => CarbonImmutable::today()->toDateString(),
            'due_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'status' => 'pending',
        ]);

        $this->log('Task', $otherTask->task_id, 'Created a task on the OTHER project.', [
            'action' => ActivityLog::TASK_CREATED,
            'module' => ActivityLog::MODULE_TASKS,
        ]);

        // A task entry whose record_id happens to equal this project's id.
        // Matched on the number alone, this would leak.
        $this->log('Task', $project->project_id, 'A task that merely shares this number.', [
            'action' => ActivityLog::TASK_CREATED,
            'module' => ActivityLog::MODULE_TASKS,
        ]);

        $this->page($project)
            ->assertOk()
            ->assertDontSee('Created a task on the OTHER project.')
            ->assertDontSee('A task that merely shares this number.');
    }

    /**
     * Entries that belong to nobody's project - a sign-in, a configuration
     * change - are not a project's activity and are not drawn in.
     */
    public function test_it_does_not_show_entries_that_belong_to_no_project(): void
    {
        $project = $this->createProject('REF-ACT-10');

        $this->log('Project', $project->project_id, 'Something on this project.');

        ActivityLog::create([
            'actor_id' => null,
            'actor_name' => 'Andy Admin',
            'actor_role' => 'admin',
            'action' => ActivityLog::LOGIN,
            'module' => ActivityLog::MODULE_AUTHENTICATION,
            'description' => 'Signed in from somewhere.',
        ]);

        $this->page($project)
            ->assertOk()
            ->assertSee('Something on this project.')
            ->assertDontSee('Signed in from somewhere.');
    }

    // ------------------------------------------------------------------
    // Pagination
    // ------------------------------------------------------------------

    /**
     * One page at a time, ten to a page - the same size every other paginated
     * table in the system uses.
     */
    public function test_it_pages_the_trail_ten_entries_at_a_time(): void
    {
        $project = $this->createProject('REF-ACT-16');

        // Twelve, oldest first, so entry 1 is the oldest and 12 the newest.
        foreach (range(1, 12) as $number) {
            $entry = $this->log('Project', $project->project_id, 'Entry number '.$number.' happened.');

            $entry->forceFill([
                'created_at' => CarbonImmutable::parse('2026-01-01 08:00:00')->addMinutes($number),
            ])->save();
        }

        // Newest first, so the first page holds 12 down to 3.
        $first = $this->page($project)->assertOk();

        $first->assertSee('of 12 entries');
        $first->assertSee('Page 1 of 2');
        $first->assertSee('Entry number 12 happened.');
        $first->assertSee('Entry number 3 happened.');
        $first->assertDontSee('Entry number 2 happened.');
        $first->assertDontSee('Entry number 1 happened.');

        $second = $this->get(
            route('super-admin.projects.show', $project->project_id).'?activity_page=2'
        )->assertOk();

        $second->assertSee('of 12 entries');
        $second->assertSee('Page 2 of 2');
        $second->assertSee('Entry number 2 happened.');
        $second->assertSee('Entry number 1 happened.');
        $second->assertDontSee('Entry number 12 happened.');
        $second->assertDontSee('Entry number 3 happened.');
    }

    /**
     * The controls point at this section, on this project, and carry the page
     * number under a name of their own - a plain `page` would collide with
     * anything else on this page that ever paginates.
     */
    public function test_the_pagination_controls_lead_to_the_next_page_of_this_project_s_trail(): void
    {
        $project = $this->createProject('REF-ACT-17');

        foreach (range(1, 12) as $number) {
            $this->log('Project', $project->project_id, 'Entry number '.$number.' happened.');
        }

        $expected = route('super-admin.projects.show', $project->project_id)
            .'?activity_page=2#project-activity-log';

        $this->page($project)
            ->assertOk()
            ->assertSee($expected, false)
            // Nothing to go back to from the first page.
            ->assertDontSee('activity_page=0', false);
    }

    /**
     * A short trail is one page, and the controls say so rather than offering
     * a second page that is not there.
     */
    public function test_a_single_page_of_entries_offers_no_further_pages(): void
    {
        $project = $this->createProject('REF-ACT-18');

        $this->log('Project', $project->project_id, 'The only thing that happened.');

        $this->page($project)
            ->assertOk()
            ->assertSee('of 1 entry')
            ->assertSee('Page 1 of 1')
            ->assertDontSee('activity_page=2', false);
    }

    /**
     * Paging the trail must not widen it. Another project's entries are absent
     * from every page, not merely from the first.
     */
    public function test_no_page_of_the_trail_shows_another_project_s_entries(): void
    {
        $project = $this->createProject('REF-ACT-19');
        $other = $this->createProject('REF-ACT-20');

        foreach (range(1, 12) as $number) {
            $this->log('Project', $project->project_id, 'Ours, number '.$number.'.');
            $this->log('Project', $other->project_id, 'Theirs, number '.$number.'.');
        }

        foreach ([1, 2] as $page) {
            $this->get(
                route('super-admin.projects.show', $project->project_id).'?activity_page='.$page
            )
                ->assertOk()
                ->assertSee('Showing')
                ->assertDontSee('Theirs, number');
        }
    }

    /**
     * A page number past the end of the trail says so rather than claiming the
     * project has no activity, and still draws the controls that lead back.
     */
    public function test_a_page_past_the_end_does_not_claim_the_project_has_no_activity(): void
    {
        $project = $this->createProject('REF-ACT-21');

        $this->log('Project', $project->project_id, 'The only thing that happened.');

        $this->get(route('super-admin.projects.show', $project->project_id).'?activity_page=9')
            ->assertOk()
            ->assertDontSee('No activity recorded for this project.')
            ->assertSee('There is nothing on this page of the activity log.')
            // The way back, rather than a misleading "Page 9 of 1".
            ->assertSee('Back to the latest entries')
            ->assertDontSee('Page 9 of', false);
    }

    // ------------------------------------------------------------------
    // The empty state
    // ------------------------------------------------------------------

    public function test_a_project_with_no_activity_says_so(): void
    {
        $project = $this->createProject('REF-ACT-11');

        // Another project's trail is not this one's, so it stays empty.
        $other = $this->createProject('REF-ACT-12');
        $this->log('Project', $other->project_id, 'Something on the other project.');

        $this->page($project)
            ->assertOk()
            ->assertSee('Activity Logs')
            ->assertSee('No activity recorded for this project.')
            ->assertDontSee('Something on the other project.');
    }

    // ------------------------------------------------------------------
    // The existing system, unchanged
    // ------------------------------------------------------------------

    /**
     * The section reads the trail; it never writes one. Recording an action
     * through the existing logger still produces exactly one row, and that one
     * row is what the section shows.
     */
    public function test_recording_an_action_still_writes_exactly_one_entry(): void
    {
        $project = $this->createProject('REF-ACT-13');

        app(ActivityLogger::class)->record(
            ActivityLog::PROJECT_UPDATED,
            null,
            sprintf("Updated the details of project '%s'.", $project->reference_no),
            $project
        );

        $this->assertSame(1, ActivityLog::count());

        $entry = ActivityLog::first();

        $this->assertSame('Project', $entry->record_type);
        $this->assertSame($project->project_id, (int) $entry->record_id);
        $this->assertSame(ActivityLog::MODULE_PROJECTS, $entry->module);

        $this->page($project)
            ->assertOk()
            ->assertSee('Updated the details of project &#039;REF-ACT-13&#039;.', false);

        // Reading the page changed nothing.
        $this->assertSame(1, ActivityLog::count());
    }

    /**
     * The Activity Logs page is untouched: the same entry is still readable
     * there, unnarrowed.
     */
    public function test_the_activity_logs_page_still_returns_the_same_entries(): void
    {
        $project = $this->createProject('REF-ACT-14');

        $this->log('Project', $project->project_id, 'Something worth auditing.');

        $this->getJson(route('super-admin.configuration.activity-logs'))
            ->assertOk()
            ->assertJsonFragment(['description' => 'Something worth auditing.']);
    }

    /**
     * An Admin reads this section through the same visibility rule the
     * Activity Logs page applies, so another administrator's entry does not
     * become readable by being on a project page.
     */
    public function test_an_admin_does_not_see_another_administrators_entries(): void
    {
        $project = $this->createProject('REF-ACT-15');

        $this->log('Project', $project->project_id, 'What the technician did.', [
            'actor_name' => 'Tess Technician',
            'actor_role' => 'technician',
        ]);

        $this->log('Project', $project->project_id, 'What the other administrator did.', [
            'actor_name' => 'Other Admin',
            'actor_role' => 'super_admin',
        ]);

        $admin = User::create([
            'user_code' => 'EMP-0300',
            'name' => 'Ann Admin',
            'first_name' => 'Ann',
            'last_name' => 'Admin',
            'email' => 'ann.admin@example.test',
            'role' => 'admin',
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);

        $this->actingAs($admin);

        $this->page($project)
            ->assertOk()
            ->assertSee('What the technician did.')
            ->assertDontSee('What the other administrator did.');
    }
}
