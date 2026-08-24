<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One project, two portals, one design.
 *
 * Project Details is the page an administrator and the lead running the job
 * both spend their time on, and it drifted: the same files were grouped cards
 * on one and a row of buttons labelled "Assessment 2" on the other, the same
 * status wore a hand-written badge on one and the shared component on the
 * other, and the task list carried a search box on one and not the other.
 *
 * These pin the shape rather than the wording, so the two pages cannot quietly
 * diverge again. What they deliberately do NOT pin is what each role may DO -
 * that is ProjectPolicy's, and it is tested where it belongs.
 */
class ProjectDetailsConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $leadAccount;

    private Technician $lead;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leadAccount = User::create([
            'user_code' => 'EMP-0100',
            'name' => 'Rita Lead',
            'first_name' => 'Rita',
            'last_name' => 'Lead',
            'email' => 'rita.lead@example.test',
            'role' => 'lead_technician',
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);

        $this->lead = Technician::create([
            'account_id' => $this->leadAccount->id,
            'role' => 'lead_technician',
        ]);

        $this->project = Project::create([
            'name' => 'Aircon Retrofit',
            'reference_no' => 'REF-DESIGN-1',
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        Client::create([
            'project_id' => $this->project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Some Holdings',
            'firstname' => 'Client',
            'surname' => 'One',
            'fullname' => 'Client One',
            'email_address' => 'client@example.test',
            'contact_number' => '09123456789',
        ]);

        $assignment = ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->lead->technician_id,
        ]);

        $schedule = Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => CarbonImmutable::today()->addDay()->toDateString().' 00:00:00',
            'end_datetime' => CarbonImmutable::today()->addDays(5)->toDateString().' 23:59:59',
            'status' => 'scheduled',
        ]);

        ScheduleTechnician::create([
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $assignment->project_technician_id,
        ]);

        // Two files of one type and one of another: the shape only shows up
        // once a type holds more than a single file.
        foreach ([
            ['assessment', 'site-survey-page-one.pdf'],
            ['assessment', 'site-survey-page-two.pdf'],
            ['contract', 'signed-contract.pdf'],
            ['quotation', 'the-money.pdf'],
        ] as [$type, $name]) {
            Document::create([
                'project_id' => $this->project->project_id,
                'document_type' => $type,
                'document_name' => $name,
                'document_path' => 'uploads/'.$type.'/'.$name,
                'uploaded_at' => now(),
            ]);
        }

        Task::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->lead->technician_id,
            'task_title' => 'Pull the wiring',
            'task_description' => 'Run the trunking along the east wall.',
            'start_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'due_date' => CarbonImmutable::today()->addDays(2)->toDateString(),
            'status' => 'pending',
        ]);

        TechnicianReport::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->lead->technician_id,
            'submitted_by' => $this->leadAccount->id,
            'report_type' => 'progress',
            'report_title' => 'First visit',
            'report_description' => 'Trunking done.',
            'report_date' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    private function administrativePage()
    {
        $this->actingAsSuperAdmin();

        return $this->get(route('super-admin.projects.show', $this->project->project_id));
    }

    private function portalPage()
    {
        $this->actingAs($this->leadAccount);

        return $this->get(route('technician.projects.show', $this->project->project_id));
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    /**
     * Both pages draw the same grouped cards, and every file is reachable by
     * its own name - not as "Assessment 1" and "Assessment 2", which tells a
     * crew nothing about which page of the survey they are opening.
     */
    public function test_both_portals_group_documents_into_the_same_cards(): void
    {
        foreach ([$this->administrativePage(), $this->portalPage()] as $page) {
            $page->assertOk();

            $page->assertSee('project-document-groups', false);
            $page->assertSee('project-document-group-head', false);
            $page->assertSee('project-document-count', false);
            $page->assertSee('project-document-link', false);

            $page->assertSee('site-survey-page-one.pdf');
            $page->assertSee('site-survey-page-two.pdf');
            $page->assertSee('signed-contract.pdf');
        }
    }

    /**
     * The old design is gone rather than merely joined by the new one.
     */
    public function test_the_portal_no_longer_labels_files_by_number(): void
    {
        $this->portalPage()
            ->assertOk()
            ->assertDontSee('>Assessment 1<', false)
            ->assertDontSee('>Assessment 2<', false);
    }

    /**
     * What each role may SEE is not a design question, and stays as it was:
     * the quotation is commercial information the crew has no use for, so
     * neither the files nor the figure appear on their copy.
     */
    public function test_the_crew_still_does_not_see_the_quotation(): void
    {
        $this->administrativePage()
            ->assertOk()
            ->assertSee('the-money.pdf');

        $this->portalPage()
            ->assertOk()
            ->assertDontSee('the-money.pdf')
            ->assertDontSee('Quotation:');
    }

    /**
     * The figure reads as money.
     *
     * Green, and the same green the Quotation column on the projects table
     * already uses - one figure printed two ways in two places is one of them
     * looking like an oversight.
     */
    public function test_the_quotation_figure_is_printed_in_the_money_colour(): void
    {
        $this->administrativePage()
            ->assertOk()
            ->assertSee('<span class="text-success fw-semibold">', false);
    }

    /**
     * Removing a file is an administrator's, so the crew's cards carry no
     * remove control - the same cards, minus a button they may not press.
     */
    public function test_only_the_administrative_page_offers_to_remove_a_file(): void
    {
        $this->administrativePage()
            ->assertOk()
            ->assertSee('data-document-remove', false);

        $this->portalPage()
            ->assertOk()
            ->assertDontSee('data-document-remove', false);
    }

    // ------------------------------------------------------------------
    // Status badge
    // ------------------------------------------------------------------

    /**
     * Both pages print the status through the shared component, at the same
     * size, so one project cannot wear two different badges depending on who
     * opened it.
     */
    public function test_both_portals_print_the_status_the_same_way(): void
    {
        foreach ([$this->administrativePage(), $this->portalPage()] as $page) {
            $page->assertOk();
            $page->assertSee('badge '.$this->project->statusBadgeClass().' rounded-pill fs-6 px-4 py-3', false);
        }
    }

    // ------------------------------------------------------------------
    // The task list
    // ------------------------------------------------------------------

    /**
     * The assignee is shown with their own picture, as they are in the Assign
     * To cards and the Assigned Team panel.
     */
    public function test_both_task_tables_show_the_technicians_picture(): void
    {
        foreach ([$this->administrativePage(), $this->portalPage()] as $page) {
            $page->assertOk();
            $page->assertSee('user-avatar user-avatar-sm', false);
        }
    }

    /**
     * Both task lists are searchable, paged tables rather than one of each.
     */
    public function test_both_task_tables_are_data_tables(): void
    {
        $this->administrativePage()
            ->assertOk()
            ->assertSee('id="tasksTable"', false);

        $this->portalPage()
            ->assertOk()
            ->assertSee('id="portalTasksTable"', false)
            ->assertSee("portal.dataTable('#portalTasksTable'", false);
    }

    /**
     * A DataTable cannot parse a fallback row with fewer cells than the
     * header, so the table must not render one - its own empty message covers
     * it. This is the bug that made My Projects need the same note.
     */
    public function test_the_portal_task_table_has_no_colspan_fallback_row(): void
    {
        $this->portalPage()
            ->assertOk()
            ->assertDontSee('colspan="6"', false);
    }

    /**
     * Nothing calls into the DataTables Responsive extension, because nothing
     * loads it.
     *
     * The administrative page used to: it built its task table twice - once on
     * tab-show and once on ready - so the first click on the Tasks tab took the
     * "already built" branch, which called .responsive.recalc() and threw a
     * TypeError every time. A guard rather than a comment, because the call
     * looks perfectly ordinary and reads as if the plugin were there.
     *
     * If the extension is ever added to the layouts, this is the test to delete.
     */
    public function test_no_script_calls_the_responsive_extension(): void
    {
        $layouts = ['layouts/superadminNav.blade.php', 'layouts/portalNav.blade.php'];

        foreach ($layouts as $layout) {
            $this->assertStringNotContainsString(
                'dataTables.responsive',
                (string) file_get_contents(resource_path('views/'.$layout)),
                $layout.' now loads the Responsive extension, so this guard is stale.'
            );
        }

        foreach (['js/super-admin/projectDetails.js', 'js/technician/portal.js'] as $script) {
            $this->assertStringNotContainsString(
                '.responsive.',
                (string) file_get_contents(public_path($script)),
                $script.' calls an extension the layouts do not load.'
            );
        }
    }
}
