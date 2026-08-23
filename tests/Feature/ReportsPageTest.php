<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\TechnicianReportImage;
use App\Models\User;
use App\Services\SystemReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Reports module: the technician report repository, the analytics
 * dashboard, and the PDF export.
 */
class ReportsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every administrative route is behind `auth` and a role check.
        $this->actingAsSuperAdmin();
    }

    private function technician(
        string $name,
        string $role = 'technician',
        string $status = User::STATUS_ACTIVE
    ): Technician {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role, 'status' => $status])->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }

    private function lead(string $name, string $status = User::STATUS_ACTIVE): Technician
    {
        return $this->technician($name, User::ROLE_LEAD_TECHNICIAN, $status);
    }

    /**
     * @param  array<int, Technician>  $technicians
     */
    private function project(
        string $name,
        string $status = 'ongoing',
        array $technicians = [],
        ?float $quotation = 250000,
        bool $archived = false
    ): Project {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => $quotation,
            'is_archived' => $archived,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => $name.' Holdings',
            'firstname' => 'Client',
            'surname' => 'Of '.$project->project_id,
            'fullname' => 'Client Of '.$project->project_id,
            'email_address' => 'client'.$project->project_id.'@example.test',
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

    private function report(
        Project $project,
        Technician $technician,
        string $title,
        string $type = 'progress',
        ?string $date = null,
        int $images = 0
    ): TechnicianReport {
        $report = TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
            'report_type' => $type,
            'report_title' => $title,
            'report_description' => "First line\nSecond line",
            'report_date' => $date ?? CarbonImmutable::today()->toDateString(),
        ]);

        for ($index = 0; $index < $images; $index++) {
            TechnicianReportImage::create([
                'technician_report_id' => $report->id,
                'image_path' => 'report-images/sample-'.$report->id.'-'.$index.'.jpg',
            ]);
        }

        return $report;
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    /**
     * A fixed month and day in the current year, which is the window the
     * monthly charts draw.
     */
    private function dateThisYear(string $monthDay): string
    {
        return CarbonImmutable::today()->format('Y').'-'.$monthDay;
    }

    /**
     * @return array<string, mixed>
     */
    private function chartData(string $chart, array $params = []): array
    {
        $response = $this->getJson(route(
            'super-admin.reports.system.chart',
            array_merge(['chart' => $chart], $params)
        ));

        $response->assertOk();

        return $response->json('data');
    }

    // ------------------------------------------------------------------
    // Page
    // ------------------------------------------------------------------

    public function test_the_page_renders_both_tabs(): void
    {
        $this->project('Some Project');

        $response = $this->get(route('super-admin.reports.index'));

        $response->assertOk();
        $response->assertSee('Technician Reports');
        $response->assertSee('System Reports');
        $response->assertSee('Create Report');
        $response->assertSee('Export Report');
    }

    /**
     * Only open projects may receive a report; finished work is a closed
     * record.
     */
    public function test_the_create_form_only_offers_reportable_projects(): void
    {
        $ongoing = $this->project('Ongoing Project', 'ongoing');
        $pending = $this->project('Pending Project', 'pending');
        $fresh = $this->project('Unscheduled Project', 'unscheduled');
        $completed = $this->project('Completed Project', 'completed');
        $cancelled = $this->project('Cancelled Project', 'cancelled');
        $archived = $this->project('Archived Project', 'archived', [], 100, true);

        $response = $this->get(route('super-admin.reports.index'));
        $response->assertOk();

        $offered = $response->viewData('reportableProjects')->pluck('name')->all();

        $this->assertContains('Ongoing Project', $offered);
        $this->assertContains('Pending Project', $offered);
        $this->assertContains('Unscheduled Project', $offered);
        $this->assertNotContains('Completed Project', $offered);
        $this->assertNotContains('Cancelled Project', $offered);
        $this->assertNotContains('Archived Project', $offered);
    }

    // ------------------------------------------------------------------
    // Technician report list
    // ------------------------------------------------------------------

    public function test_it_lists_reports_from_every_project(): void
    {
        $tech = $this->technician('Ana Mendoza');
        $first = $this->project('First Project', 'ongoing', [$tech]);
        $second = $this->project('Second Project', 'completed', [$tech]);

        $this->report($first, $tech, 'Report On First');
        $this->report($second, $tech, 'Report On Second');

        $response = $this->getJson(route('super-admin.reports.technician'));

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);

        $titles = collect($response->json('reports'))->pluck('report_title')->all();

        $this->assertContains('Report On First', $titles);
        // Reports outlive their project's status; the repository shows them all.
        $this->assertContains('Report On Second', $titles);
    }

    /**
     * The Report ID column prints a code, and the search box has to find a
     * report by the very thing the column showed.
     */
    public function test_reports_carry_a_code_that_search_matches(): void
    {
        $tech = $this->technician('Ana Mendoza');
        $project = $this->project('Roofing Works', 'ongoing', [$tech]);

        $report = $this->report($project, $tech, 'Weekly update');
        $code = sprintf('RPT-%04d', $report->id);

        $this->getJson(route('super-admin.reports.technician'))
            ->assertOk()
            ->assertJsonPath('reports.0.display_code', $code);

        foreach ([$code, strtolower($code), (string) $report->id] as $term) {
            $this->getJson(route('super-admin.reports.technician', ['search' => $term]))
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('reports.0.report_title', 'Weekly update');
        }
    }

    /**
     * The list pages in SQL, so the browser is handed one page of rows at a
     * time however many reports exist.
     */
    public function test_it_returns_one_page_of_reports_at_a_time(): void
    {
        $tech = $this->technician('Ana Mendoza');
        $project = $this->project('Busy Project', 'ongoing', [$tech]);

        // Dated apart so the newest-first order is unambiguous, and the
        // pages can be checked against a known sequence.
        for ($index = 0; $index < 25; $index++) {
            $this->report($project, $tech, 'Report '.$index, 'progress', $this->day(-$index));
        }

        $first = $this->getJson(route('super-admin.reports.technician'));

        $first->assertOk();
        $first->assertJsonCount(10, 'reports');
        $first->assertJsonPath('meta.total', 25);
        $first->assertJsonPath('meta.current_page', 1);
        $first->assertJsonPath('meta.last_page', 3);
        $first->assertJsonPath('meta.from', 1);
        $first->assertJsonPath('meta.to', 10);
        $first->assertJsonPath('reports.0.report_title', 'Report 0');

        $second = $this->getJson(route('super-admin.reports.technician', ['page' => 2]));

        $second->assertOk();
        $second->assertJsonCount(10, 'reports');
        $second->assertJsonPath('meta.current_page', 2);
        $second->assertJsonPath('meta.from', 11);
        $second->assertJsonPath('reports.0.report_title', 'Report 10');

        // The last page carries the remainder, not a full page.
        $last = $this->getJson(route('super-admin.reports.technician', ['page' => 3]));

        $last->assertOk();
        $last->assertJsonCount(5, 'reports');
        $last->assertJsonPath('meta.to', 25);
        $last->assertJsonPath('reports.4.report_title', 'Report 24');

        // No page repeats a row and none is missed.
        $seen = collect([1, 2, 3])
            ->flatMap(fn (int $page) => collect(
                $this->getJson(route('super-admin.reports.technician', ['page' => $page]))->json('reports')
            )->pluck('id'))
            ->all();

        $this->assertCount(25, $seen);
        $this->assertCount(25, array_unique($seen));
    }

    /**
     * Paging narrows to whatever the filters left behind, not to the whole
     * table - otherwise page 2 of a search would show rows the search
     * excluded.
     */
    public function test_paging_applies_to_the_filtered_results(): void
    {
        $tech = $this->technician('Ana Mendoza');
        $alpha = $this->project('Alpha Project', 'ongoing', [$tech]);
        $beta = $this->project('Beta Project', 'ongoing', [$tech]);

        for ($index = 0; $index < 12; $index++) {
            $this->report($alpha, $tech, 'Alpha '.$index, 'progress', $this->day(-$index));
        }

        for ($index = 0; $index < 12; $index++) {
            $this->report($beta, $tech, 'Beta '.$index, 'progress', $this->day(-$index));
        }

        $filtered = $this->getJson(route('super-admin.reports.technician', [
            'project_id' => $alpha->project_id,
        ]));

        $filtered->assertOk();
        $filtered->assertJsonPath('meta.total', 12);
        $filtered->assertJsonPath('meta.last_page', 2);

        $secondPage = $this->getJson(route('super-admin.reports.technician', [
            'project_id' => $alpha->project_id,
            'page' => 2,
        ]));

        $secondPage->assertOk();
        $secondPage->assertJsonCount(2, 'reports');

        // Every row on the filtered page 2 still belongs to Alpha.
        $projectIds = collect($secondPage->json('reports'))->pluck('project_id')->unique()->all();

        $this->assertSame([$alpha->project_id], array_values($projectIds));
    }

    public function test_it_filters_by_project_and_report_type(): void
    {
        $tech = $this->technician('Ana Mendoza');
        $alpha = $this->project('Alpha Project', 'ongoing', [$tech]);
        $beta = $this->project('Beta Project', 'ongoing', [$tech]);

        $this->report($alpha, $tech, 'Alpha Progress', 'progress');
        $this->report($alpha, $tech, 'Alpha Incident', 'incident');
        $this->report($beta, $tech, 'Beta Progress', 'progress');

        $byProject = $this->getJson(route('super-admin.reports.technician', [
            'project_id' => $alpha->project_id,
        ]));
        $byProject->assertOk();
        $byProject->assertJsonPath('meta.total', 2);

        $byType = $this->getJson(route('super-admin.reports.technician', [
            'report_type' => 'incident',
        ]));
        $byType->assertOk();
        $byType->assertJsonPath('meta.total', 1);
        $byType->assertJsonPath('reports.0.report_title', 'Alpha Incident');

        $both = $this->getJson(route('super-admin.reports.technician', [
            'project_id' => $beta->project_id,
            'report_type' => 'incident',
        ]));
        $both->assertOk();
        $both->assertJsonPath('meta.total', 0);
    }

    /**
     * Search has to reach the project, the technician and the report text.
     */
    public function test_search_covers_project_title_technician_and_description(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $jose = $this->technician('Jose Garcia', 'lead_technician');

        $roofing = $this->project('Roofing Works', 'ongoing', [$ana]);
        $ducting = $this->project('Ducting Works', 'ongoing', [$jose]);

        $this->report($roofing, $ana, 'Weekly update');
        $this->report($ducting, $jose, 'Ducting milestone');

        foreach ([
            'Roofing' => 'Weekly update',      // project name
            'milestone' => 'Ducting milestone', // report title
            'Jose' => 'Ducting milestone',      // technician name
        ] as $term => $expected) {
            $response = $this->getJson(route('super-admin.reports.technician', ['search' => $term]));

            $response->assertOk();
            $response->assertJsonPath('meta.total', 1);
            $response->assertJsonPath('reports.0.report_title', $expected);
        }

        // Description text is searchable too - both reports share it.
        $this->getJson(route('super-admin.reports.technician', ['search' => 'Second line']))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_it_filters_by_date_window(): void
    {
        $tech = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', 'ongoing', [$tech]);

        $this->report($project, $tech, 'Today Report', 'progress', $this->day(0));
        $this->report($project, $tech, 'Old Report', 'progress', $this->day(-60));

        $today = $this->getJson(route('super-admin.reports.technician', ['date_filter' => 'today']));
        $today->assertOk();
        $today->assertJsonPath('meta.total', 1);
        $today->assertJsonPath('reports.0.report_title', 'Today Report');

        $custom = $this->getJson(route('super-admin.reports.technician', [
            'date_filter' => 'custom',
            'start_date' => $this->day(-70),
            'end_date' => $this->day(-50),
        ]));
        $custom->assertOk();
        $custom->assertJsonPath('meta.total', 1);
        $custom->assertJsonPath('reports.0.report_title', 'Old Report');

        $all = $this->getJson(route('super-admin.reports.technician', ['date_filter' => 'all']));
        $all->assertJsonPath('meta.total', 2);
    }

    // ------------------------------------------------------------------
    // Report viewer
    // ------------------------------------------------------------------

    public function test_the_viewer_returns_details_and_its_image_gallery(): void
    {
        $tech = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', 'ongoing', [$tech]);
        $report = $this->report($project, $tech, 'With Images', 'incident', null, 3);

        $response = $this->getJson(route('super-admin.reports.technician.show', $report->id));

        $response->assertOk();
        $response->assertJsonPath('report_title', 'With Images');
        $response->assertJsonPath('type_label', 'Incident Report');
        $response->assertJsonPath('submitted_by', 'Ana Mendoza');
        $response->assertJsonPath('client', 'Some Project Holdings');
        $response->assertJsonCount(3, 'images');

        // The raw description is returned so the client can preserve breaks.
        $this->assertStringContainsString("\n", $response->json('description'));
    }

    public function test_a_report_without_images_returns_an_empty_gallery(): void
    {
        $tech = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', 'ongoing', [$tech]);
        $report = $this->report($project, $tech, 'No Images');

        $response = $this->getJson(route('super-admin.reports.technician.show', $report->id));

        $response->assertOk();
        $response->assertJsonCount(0, 'images');
    }

    // ------------------------------------------------------------------
    // Report creation
    // ------------------------------------------------------------------

    /**
     * The endpoint still honours an explicit technician, which is what a
     * logged-in user will supply once authentication exists. Neither form
     * sends one today.
     */
    public function test_creating_a_report_uses_the_existing_endpoint(): void
    {
        $lead = $this->technician('Jose Garcia', 'lead_technician');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', 'ongoing', [$lead, $ana]);

        $response = $this->post(route('super-admin.technician.reports.store', $project->project_id), [
            'report_type' => 'progress',
            'report_title' => 'Filed From Reports',
            'report_description' => 'Work done today.',
            'technician_id' => $ana->technician_id,
        ]);

        $response->assertSessionHasNoErrors();

        $report = TechnicianReport::where('report_title', 'Filed From Reports')->first();

        $this->assertNotNull($report);
        $this->assertSame($ana->technician_id, $report->technician_id);
    }

    /**
     * technician_id answers "which technician is this report about", and still
     * falls back to the project's lead when the form does not say.
     */
    public function test_omitting_the_technician_falls_back_to_the_project_lead(): void
    {
        $lead = $this->technician('Jose Garcia', 'lead_technician');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', 'ongoing', [$ana, $lead]);

        $this->post(route('super-admin.technician.reports.store', $project->project_id), [
            'report_type' => 'progress',
            'report_title' => 'Legacy Path',
            'report_description' => 'Work done today.',
        ])->assertSessionHasNoErrors();

        $report = TechnicianReport::where('report_title', 'Legacy Path')->first();

        $this->assertSame($lead->technician_id, $report->technician_id);
    }

    /**
     * An administrator has no technician record, so a report they file used to
     * be credited to the project's lead - somebody who had nothing to do with
     * it. It is now attributed to whoever actually submitted it.
     */
    public function test_a_report_filed_by_an_administrator_is_credited_to_them(): void
    {
        $lead = $this->technician('Jose Garcia', 'lead_technician');
        $project = $this->project('Some Project', 'ongoing', [$lead]);

        $this->post(route('super-admin.technician.reports.store', $project->project_id), [
            'report_type' => 'progress',
            'report_title' => 'Filed By The Owner',
            'report_description' => 'Work done today.',
        ])->assertSessionHasNoErrors();

        $report = TechnicianReport::where('report_title', 'Filed By The Owner')->first();
        $actor = auth()->user();

        $this->assertSame($actor->id, (int) $report->submitted_by);
        $this->assertSame($actor->fullName(), $report->submitterName());
        $this->assertNotSame('Jose Garcia', $report->submitterName());
    }

    /**
     * Reports written before that column existed have nobody recorded, and
     * fall back to the technician - which is who filed them.
     */
    public function test_an_older_report_still_credits_its_technician(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', 'ongoing', [$ana]);

        $report = TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $ana->technician_id,
            'report_type' => 'progress',
            'report_title' => 'Before The Column',
            'report_description' => 'Work done today.',
            'report_date' => now()->toDateString(),
        ]);

        $this->assertNull($report->submitted_by);
        $this->assertSame('Ana Mendoza', $report->fresh()->submitterName());
    }

    /**
     * The table reads Report ID, Reference No., Client, Report Type,
     * Submitted By, Date Submitted - so the row payload has to carry the
     * client and the accent the viewer is tinted with.
     */
    public function test_each_row_carries_the_columns_the_table_shows(): void
    {
        $lead = $this->technician('Jose Garcia', 'lead_technician');
        $project = $this->project('Some Project', 'ongoing', [$lead]);

        $this->post(route('super-admin.technician.reports.store', $project->project_id), [
            'report_type' => 'incident',
            'report_title' => 'Cable damaged',
            'report_description' => 'Found a damaged run.',
        ])->assertSessionHasNoErrors();

        $row = collect($this->getJson(route('super-admin.reports.technician'))->json('reports'))
            ->firstWhere('report_title', 'Cable damaged');

        $this->assertSame($project->reference_no, $row['reference_no']);
        $this->assertNotSame('', $row['client']);
        $this->assertSame('Incident Report', $row['type_label']);
        $this->assertSame(auth()->user()->fullName(), $row['submitted_by']);
        $this->assertNotNull($row['report_date_label']);
        // Incidents tint the viewer green, progress reports blue.
        $this->assertSame('report-accent-incident', $row['type_accent_class']);
    }

    public function test_a_progress_report_carries_the_other_accent(): void
    {
        $lead = $this->technician('Jose Garcia', 'lead_technician');
        $project = $this->project('Some Project', 'ongoing', [$lead]);

        $this->post(route('super-admin.technician.reports.store', $project->project_id), [
            'report_type' => 'progress',
            'report_title' => 'Wiring pulled',
            'report_description' => 'First floor complete.',
        ]);

        $row = collect($this->getJson(route('super-admin.reports.technician'))->json('reports'))
            ->firstWhere('report_title', 'Wiring pulled');

        $this->assertSame('report-accent-progress', $row['type_accent_class']);
    }

    public function test_the_list_shows_who_submitted_each_report(): void
    {
        $lead = $this->technician('Jose Garcia', 'lead_technician');
        $project = $this->project('Some Project', 'ongoing', [$lead]);

        $this->post(route('super-admin.technician.reports.store', $project->project_id), [
            'report_type' => 'progress',
            'report_title' => 'Filed By The Owner',
            'report_description' => 'Work done today.',
        ]);

        $response = $this->getJson(route('super-admin.reports.technician'));

        $row = collect($response->json('reports'))->firstWhere('report_title', 'Filed By The Owner');

        $this->assertSame(auth()->user()->fullName(), $row['submitted_by']);
    }

    public function test_a_closed_project_cannot_receive_a_report(): void
    {
        $tech = $this->technician('Ana Mendoza');

        foreach (['completed', 'cancelled'] as $status) {
            $project = $this->project(ucfirst($status).' Project', $status, [$tech]);

            $response = $this->post(route('super-admin.technician.reports.store', $project->project_id), [
                'report_type' => 'progress',
                'report_title' => 'Should Not Exist '.$status,
                'report_description' => 'Nope.',
            ]);

            $response->assertSessionHas('error');
        }

        $this->assertSame(0, TechnicianReport::count());
    }

    // ------------------------------------------------------------------
    // System reports
    // ------------------------------------------------------------------

    /**
     * The tab draws graphs only - the figures live in the PDF export now, so
     * this endpoint carries nothing but chart data.
     */
    public function test_the_dashboard_returns_every_chart(): void
    {
        $ongoing = $this->project('Ongoing Project', 'ongoing', [], 500000);
        $this->schedule($ongoing, $this->day(-2), $this->day(4));

        $response = $this->getJson(route('super-admin.reports.system'));

        $response->assertOk();

        foreach (SystemReportService::CHART_KEYS as $chart) {
            $this->assertIsArray($response->json('charts.'.$chart), "Missing {$chart} chart.");
            $this->assertIsArray($response->json('charts.'.$chart.'.labels'));
        }

        // The summary cards are gone from the page.
        $this->assertNull($response->json('summary'));
    }

    /**
     * Each chart's own toggle picks its granularity: monthly draws the current
     * year month by month, yearly the last five whole years.
     */
    public function test_each_chart_granularity_sets_its_own_buckets(): void
    {
        $service = app(SystemReportService::class);

        $this->assertSame('month', $service->resolveGranularity('monthly')['bucket']);
        $this->assertSame('year', $service->resolveGranularity('yearly')['bucket']);

        $monthly = $this->getJson(route('super-admin.reports.system.chart', [
            'chart' => 'completedProjects',
        ]));
        $monthly->assertOk();
        $monthly->assertJsonCount(12, 'data.labels');

        $yearly = $this->getJson(route('super-admin.reports.system.chart', [
            'chart' => 'completedProjects',
            'granularity' => 'yearly',
        ]));
        $yearly->assertOk();
        $yearly->assertJsonCount(5, 'data.labels');
    }

    /**
     * The pie splits On Hold and Overdue out of the stored statuses they are
     * hidden inside, so the slices read the way the rest of the app does.
     */
    public function test_the_active_breakdown_counts_derived_statuses(): void
    {
        $ongoing = $this->project('Ongoing Project', 'ongoing');
        $this->schedule($ongoing, $this->day(-1), $this->day(5));

        $late = $this->project('Late Project', 'ongoing');
        $this->schedule($late, $this->day(-20), $this->day(-10));

        $paused = $this->project('Paused Project', 'pending');
        $paused->forceFill(['on_hold' => true])->save();

        $this->project('Fresh Project', 'unscheduled');

        $data = $this->chartData('activeProjectBreakdown');
        $slices = array_combine($data['labels'], $data['values']);

        $this->assertSame(1, $slices['Unscheduled']);
        $this->assertSame(1, $slices['Ongoing']);
        $this->assertSame(1, $slices['Overdue']);
        $this->assertSame(1, $slices['On Hold']);
        // The overdue and paused projects were moved out of their stored
        // status rather than counted twice.
        $this->assertArrayNotHasKey('Pending', $slices);
    }

    /**
     * The chart is about work that is still somebody's problem. Finished,
     * abandoned and filed-away projects are not drawn, and - the part that
     * matters - they cannot reach the total either.
     */
    public function test_the_active_breakdown_leaves_out_finished_and_closed_work(): void
    {
        $ongoing = $this->project('Ongoing Project', 'ongoing');
        $this->schedule($ongoing, $this->day(-1), $this->day(5));

        $this->project('Done Project', 'completed');
        $this->project('Waiting Project', Project::STATUS_AWAITING_CLIENT_CONFIRMATION);
        $this->project('Dead Project', 'cancelled');
        $this->project('Filed Project', 'archived', [], 100, true);

        $data = $this->chartData('activeProjectBreakdown');

        $this->assertSame(['Ongoing'], $data['labels']);
        $this->assertSame([1], $data['values']);
        // The excluded statuses carry no weight in the headline figure.
        $this->assertSame(1, array_sum($data['values']));
        $this->assertSame('Active projects: 1', $data['summary']);
    }

    /**
     * Residential and commercial work are two series against one timeline, so
     * a month with neither still has a column under both.
     */
    public function test_residential_and_commercial_are_drawn_as_two_series(): void
    {
        $residential = $this->project('House Job', 'ongoing');
        $residential->clients()->update(['client_type' => 'Residential']);

        $this->project('Office Job', 'ongoing');
        $this->project('Warehouse Job', 'ongoing');

        $monthly = $this->chartData('residentialVsCommercial');

        $this->assertCount(12, $monthly['labels']);
        $this->assertCount(2, $monthly['datasets']);
        $this->assertSame('Residential', $monthly['datasets'][0]['label']);
        $this->assertSame('Commercial', $monthly['datasets'][1]['label']);
        $this->assertCount(12, $monthly['datasets'][0]['values']);
        $this->assertSame(1, array_sum($monthly['datasets'][0]['values']));
        $this->assertSame(2, array_sum($monthly['datasets'][1]['values']));

        $yearly = $this->chartData('residentialVsCommercial', ['granularity' => 'yearly']);

        $this->assertCount(5, $yearly['labels']);
        $this->assertCount(5, $yearly['datasets'][1]['values']);
    }

    /**
     * The figure printed under the graph is the graph's own total, so the two
     * move together whichever filter is changed.
     */
    public function test_the_quotation_chart_reports_the_total_it_draws(): void
    {
        $ongoing = $this->project('Ongoing Project', 'ongoing', [], 100000);
        $this->schedule($ongoing, $this->day(-1), $this->day(5));

        $this->project('Done Project', 'completed', [], 250000);

        $all = $this->chartData('totalQuotation');

        $this->assertEquals(350000, array_sum($all['values']));
        $this->assertSame('Total Quotation: ₱350,000.00', $all['summary']);

        $completed = $this->chartData('totalQuotation', ['quotation_status' => 'completed']);

        $this->assertSame('Total Quotation: ₱250,000.00', $completed['summary']);
    }

    // ------------------------------------------------------------------
    // Lead technician charts
    // ------------------------------------------------------------------

    /**
     * The dashboard follows the people answerable for the work. A regular
     * technician on the same project is not one of them, and a deactivated
     * lead is nobody's current capacity.
     */
    public function test_the_distribution_chart_shows_active_lead_technicians_only(): void
    {
        $lead = $this->lead('Jose Garcia');
        $quiet = $this->lead('Rita Cruz');
        $retired = $this->lead('Ex Lead', User::STATUS_DEACTIVATED);
        $crew = $this->technician('Ana Mendoza');

        $first = $this->project('First Job', 'ongoing', [$lead, $crew]);
        $this->schedule($first, $this->day(-1), $this->day(3));
        $second = $this->project('Second Job', 'pending', [$lead]);
        $this->schedule($second, $this->day(2), $this->day(6));

        $old = $this->project('Old Job', 'ongoing', [$retired]);
        $this->schedule($old, $this->day(-1), $this->day(3));

        $data = $this->chartData('leadTechnicianProjects');

        $this->assertSame(['Jose Garcia'], $data['labels']);
        $this->assertSame([2], $data['values']);

        // The lead with nothing on, and the deactivated one, are both absent.
        $this->assertNotContains('Rita Cruz', $data['labels']);
        $this->assertNotContains('Ex Lead', $data['labels']);
        $this->assertNotContains('Ana Mendoza', $data['labels']);

        // But the idle lead is still counted as capacity.
        $availability = $this->chartData('leadTechnicianAvailability');

        $this->assertSame(['Assigned to Active Project', 'Available'], $availability['labels']);
        $this->assertSame([1, 1], $availability['values']);
        $this->assertSame('Active lead technicians: 2', $availability['summary']);
    }

    /**
     * A project handed to a lead before its dates are set is already theirs,
     * so it counts towards their load and towards the breakdown alike - the
     * two charts answer to one definition of an active project.
     */
    public function test_unscheduled_work_counts_towards_a_lead_s_load(): void
    {
        $lead = $this->lead('Jose Garcia');

        $booked = $this->project('Booked Job', 'ongoing', [$lead]);
        $this->schedule($booked, $this->day(-1), $this->day(4));

        $this->project('Not Yet Booked', 'unscheduled', [$lead]);

        $breakdown = $this->chartData('activeProjectBreakdown');
        $slices = array_combine($breakdown['labels'], $breakdown['values']);

        $this->assertSame(1, $slices['Unscheduled']);
        $this->assertSame(2, array_sum($breakdown['values']));

        // The distribution counts both, exactly as the breakdown does.
        $distribution = $this->chartData('leadTechnicianProjects');

        $this->assertSame(['Jose Garcia'], $distribution['labels']);
        $this->assertSame([2], $distribution['values']);

        // And a lead holding only unscheduled work is not free.
        $spare = $this->lead('Rita Cruz');
        $this->project('Rita Unscheduled', 'unscheduled', [$spare]);

        $this->assertSame([2, 0], $this->chartData('leadTechnicianAvailability')['values']);
    }

    /**
     * A project booked in several blocks, with several people on it, is still
     * one project for the lead carrying it.
     */
    public function test_a_lead_is_not_counted_twice_for_one_project(): void
    {
        $lead = $this->lead('Jose Garcia');
        $crew = $this->technician('Ana Mendoza');

        $project = $this->project('Long Job', 'ongoing', [$lead, $crew]);
        $this->schedule($project, $this->dateThisYear('03-03'), $this->dateThisYear('03-07'));
        $this->schedule($project, $this->dateThisYear('03-20'), $this->dateThisYear('03-24'));

        $this->assertSame([1], $this->chartData('leadTechnicianProjects')['values']);

        $workload = $this->chartData('leadTechnicianWorkload');

        $this->assertCount(12, $workload['labels']);
        $this->assertSame(1, array_sum($workload['datasets'][0]['values']));
    }

    /**
     * Work that has ended releases the lead who ran it, however long the
     * project sat on their name.
     */
    public function test_finished_work_does_not_keep_a_lead_busy(): void
    {
        $lead = $this->lead('Jose Garcia');

        foreach (['completed', 'cancelled', Project::STATUS_AWAITING_CLIENT_CONFIRMATION] as $status) {
            $project = $this->project('Closed '.$status, $status, [$lead]);
            $this->schedule($project, $this->day(-10), $this->day(-5));
        }

        $archived = $this->project('Filed Job', 'ongoing', [$lead], 100, true);
        $this->schedule($archived, $this->day(-2), $this->day(2));

        $this->assertSame([], $this->chartData('leadTechnicianProjects')['labels']);
        $this->assertSame([0, 1], $this->chartData('leadTechnicianAvailability')['values']);
    }

    /**
     * Deactivating an account removes it from today's capacity without
     * touching what it did - the assignment rows stay exactly where they are.
     */
    public function test_deactivating_a_lead_keeps_their_history(): void
    {
        $lead = $this->lead('Jose Garcia');
        $project = $this->project('Live Job', 'ongoing', [$lead]);
        $this->schedule($project, $this->day(-1), $this->day(4));

        $this->assertSame([1], $this->chartData('leadTechnicianProjects')['values']);

        $lead->account->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        $this->assertSame([], $this->chartData('leadTechnicianProjects')['values']);
        $this->assertSame('Active lead technicians: 0', $this->chartData('leadTechnicianAvailability')['summary']);
        $this->assertSame(
            1,
            ProjectTechnician::where('project_id', $project->project_id)->count(),
            'The historical assignment must survive the deactivation.'
        );
    }

    // ------------------------------------------------------------------
    // Schedule charts
    // ------------------------------------------------------------------

    /**
     * Booked days are counted range by range, and the days between two
     * separate bookings were never booked: Aug 10-15 and Aug 25-30 is twelve
     * days, not the twenty-one the two ends span.
     */
    public function test_booked_days_skip_the_gaps_between_ranges(): void
    {
        $project = $this->project('Split Job', 'ongoing');
        $this->schedule($project, $this->dateThisYear('08-10'), $this->dateThisYear('08-15'));
        $this->schedule($project, $this->dateThisYear('08-25'), $this->dateThisYear('08-30'));

        // One project, so its average duration is its own booked days.
        $data = $this->chartData('averageProjectDuration');
        $august = array_combine($data['labels'], $data['values'])[
            CarbonImmutable::parse($this->dateThisYear('08-01'))->format('M Y')
        ];

        $this->assertEquals(12, $august);
        $this->assertSame('Average: 12.0 days', $data['summary']);

        // And the project itself is one project, however it was booked.
        $trend = $this->chartData('scheduledProjectsTrend');

        $this->assertSame(1, array_sum($trend['values']));
        $this->assertCount(12, $trend['labels']);
    }

    /**
     * A partial day books hours on one date, so it is one scheduled day - not
     * a fraction, and not four days because it ran four hours.
     */
    public function test_a_partial_day_counts_as_a_single_scheduled_day(): void
    {
        $project = $this->project('Morning Call', 'ongoing');

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->dateThisYear('08-20').' 08:00:00',
            'end_datetime' => $this->dateThisYear('08-20').' 12:00:00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
        ]);

        $this->assertSame('Average: 1.0 days', $this->chartData('averageProjectDuration')['summary']);

        $types = $this->chartData('scheduleTypeDistribution');

        $this->assertSame(['Date Based', 'Partial Day'], $types['labels']);
        $this->assertSame([0, 1], $types['values']);
    }

    /**
     * The mode is read from the column, never inferred from the times.
     */
    public function test_the_schedule_type_distribution_reads_the_stored_mode(): void
    {
        $project = $this->project('Mixed Job', 'ongoing');
        $this->schedule($project, $this->dateThisYear('08-10'), $this->dateThisYear('08-12'));

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->dateThisYear('08-20').' 08:00:00',
            'end_datetime' => $this->dateThisYear('08-20').' 12:00:00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
        ]);

        $data = $this->chartData('scheduleTypeDistribution');

        $this->assertSame([1, 1], $data['values']);
        $this->assertSame('Bookings: 2', $data['summary']);
    }

    /**
     * Each project's duration is the sum of its own ranges, and the chart
     * averages those across the projects starting in the bucket.
     */
    public function test_average_duration_averages_the_booked_days_per_project(): void
    {
        $split = $this->project('Split Job', 'ongoing');
        $this->schedule($split, $this->dateThisYear('08-10'), $this->dateThisYear('08-15'));
        $this->schedule($split, $this->dateThisYear('08-25'), $this->dateThisYear('08-30'));

        $short = $this->project('Short Job', 'ongoing');
        $this->schedule($short, $this->dateThisYear('08-04'), $this->dateThisYear('08-07'));

        $data = $this->chartData('averageProjectDuration');
        $august = array_combine($data['labels'], $data['values'])[
            CarbonImmutable::parse($this->dateThisYear('08-01'))->format('M Y')
        ];

        // Twelve booked days and four, over two projects.
        $this->assertEquals(8, $august);
        $this->assertSame('Average: 8.0 days', $data['summary']);
        $this->assertCount(12, $data['labels']);
    }

    /**
     * Every time-based chart draws the whole timeline, so an empty month is a
     * zero rather than a missing column.
     */
    public function test_every_time_based_chart_covers_the_whole_timeline(): void
    {
        $timeBased = [
            'completedProjects',
            'residentialVsCommercial',
            'totalQuotation',
            'leadTechnicianWorkload',
            'scheduledProjectsTrend',
            'averageProjectDuration',
        ];

        foreach ($timeBased as $chart) {
            $this->assertCount(12, $this->chartData($chart)['labels'], "{$chart} monthly");
            $this->assertCount(
                5,
                $this->chartData($chart, ['granularity' => 'yearly'])['labels'],
                "{$chart} yearly"
            );
        }
    }

    /**
     * "All" is the union of the statuses the filter offers, and each option
     * narrows to just its own share of the money.
     */
    public function test_the_quotation_chart_filters_by_status(): void
    {
        $ongoing = $this->project('Ongoing Project', 'ongoing', [], 100000);
        $this->schedule($ongoing, $this->day(-1), $this->day(5));

        $late = $this->project('Late Project', 'ongoing', [], 200000);
        $this->schedule($late, $this->day(-20), $this->day(-10));

        $paused = $this->project('Paused Project', 'pending', [], 400000);
        $paused->forceFill(['on_hold' => true])->save();

        $this->project('Done Project', 'completed', [], 800000);

        // Neither of these carries committed money, whatever their quotation.
        $this->project('Dead Project', 'cancelled', [], 1000000);
        $this->project('Filed Project', 'archived', [], 2000000, true);

        $totalFor = function (string $status): float {
            $response = $this->getJson(route('super-admin.reports.system.chart', [
                'chart' => 'totalQuotation',
                'quotation_status' => $status,
            ]));

            $response->assertOk();

            return array_sum($response->json('data.values'));
        };

        $this->assertSame(100000.0, $totalFor('ongoing'));
        $this->assertSame(200000.0, $totalFor('overdue'));
        $this->assertSame(400000.0, $totalFor('on_hold'));
        $this->assertSame(800000.0, $totalFor('completed'));
        $this->assertSame(0.0, $totalFor('pending'));
        $this->assertSame(1500000.0, $totalFor('all'));
    }

    /**
     * A client holding several projects is ranked on their combined value.
     */
    public function test_top_clients_sum_each_client_s_projects(): void
    {
        foreach ([['Acme Holdings', 300000], ['Acme Holdings', 500000], ['Solo Corp', 600000]] as [$company, $quotation]) {
            $project = $this->project($company.' Job '.$quotation, 'ongoing', [], $quotation);

            $project->clients()->update(['company_name' => $company]);
        }

        $response = $this->getJson(route('super-admin.reports.system.chart', [
            'chart' => 'topClients',
        ]));

        $response->assertOk();
        // Acme's two projects outrank Solo Corp's single larger one.
        $response->assertJsonPath('data.labels.0', 'Acme Holdings');
        $response->assertJsonPath('data.values.0', 800000);
        $response->assertJsonPath('data.labels.1', 'Solo Corp');
    }

    public function test_the_chart_endpoint_rejects_an_unknown_chart(): void
    {
        $this->getJson(route('super-admin.reports.system.chart', ['chart' => 'everything']))
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Export - period and filters
    // ------------------------------------------------------------------

    /**
     * A report covers one named month or one named year, both ends inclusive.
     */
    public function test_the_export_period_is_the_month_or_year_that_was_chosen(): void
    {
        $service = app(SystemReportService::class);

        $monthly = $service->resolveExportPeriod('monthly', 8, 2026);

        $this->assertSame('August 2026', $monthly['label']);
        $this->assertSame('2026-08-01', $monthly['start']->toDateString());
        $this->assertSame('2026-08-31', $monthly['end']->toDateString());

        $yearly = $service->resolveExportPeriod('yearly', null, 2026);

        $this->assertSame('2026', $yearly['label']);
        $this->assertSame('2026-01-01', $yearly['start']->toDateString());
        $this->assertSame('2026-12-31', $yearly['end']->toDateString());
    }

    public function test_it_exports_a_pdf_for_every_report_type(): void
    {
        $tech = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', 'ongoing', [$tech], 400000);
        $this->schedule($project, $this->dateThisYear('08-02'), $this->dateThisYear('08-06'));

        foreach (array_keys(ReportController::EXPORT_TYPES) as $type) {
            $response = $this->post(route('super-admin.reports.export'), $this->exportPayload([
                'report_type' => $type,
            ]));

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent(), "{$type} did not produce a PDF.");
        }
    }

    /**
     * A filter that belongs to another report is refused rather than quietly
     * ignored: the caller believes it will be applied, and it will not be.
     */
    public function test_filters_from_another_report_are_rejected(): void
    {
        $technician = $this->technician('Ana Mendoza');

        $combinations = [
            ['report_type' => 'schedule', 'project_status' => 'completed'],
            ['report_type' => 'technician', 'project_status' => 'completed'],
            ['report_type' => 'project', 'technician_scope' => 'all'],
            ['report_type' => 'project', 'technician_kind' => 'tasks'],
            ['report_type' => 'schedule', 'technician_id' => $technician->technician_id],
        ];

        foreach ($combinations as $payload) {
            $response = $this->postJson(
                route('super-admin.reports.export'),
                $this->exportPayload($payload)
            );

            $response->assertStatus(422);
            $this->assertStringContainsString('does not apply', $response->json('error'));
        }
    }

    /**
     * Archived work never appears, so it is not offered as something to
     * report on either.
     */
    public function test_archived_cannot_be_asked_for_as_a_project_status(): void
    {
        $this->assertArrayNotHasKey('archived', SystemReportService::REPORT_STATUSES);

        $response = $this->postJson(route('super-admin.reports.export'), $this->exportPayload([
            'report_type' => 'project',
            'project_status' => 'archived',
        ]));

        $response->assertStatus(422);
    }

    public function test_a_monthly_export_requires_a_month_and_a_valid_year(): void
    {
        $noMonth = $this->postJson(route('super-admin.reports.export'), [
            'report_type' => 'project',
            'period' => 'monthly',
            'year' => CarbonImmutable::today()->format('Y'),
        ]);

        $noMonth->assertStatus(422);
        $this->assertStringContainsString('month', $noMonth->json('error'));

        $badYear = $this->postJson(route('super-admin.reports.export'), $this->exportPayload([
            'report_type' => 'project',
            'year' => 1990,
        ]));

        $badYear->assertStatus(422);

        // A yearly report needs no month.
        $this->postJson(route('super-admin.reports.export'), [
            'report_type' => 'project',
            'period' => 'yearly',
            'year' => CarbonImmutable::today()->format('Y'),
        ])->assertOk();
    }

    public function test_an_unknown_report_type_is_rejected(): void
    {
        $this->postJson(route('super-admin.reports.export'), $this->exportPayload([
            'report_type' => 'everything',
        ]))->assertStatus(422);
    }

    /**
     * Nothing to report is still a report: the header, the filters and a
     * summary of zeros, rather than a failed download.
     */
    public function test_an_empty_period_still_produces_a_report(): void
    {
        $report = $this->exportReport('project');

        $this->assertTrue($report['is_empty']);
        $this->assertCount(0, $report['sections'][0]['rows']);
        $this->assertSame(
            'Total Quotation: ₱0.00',
            $this->summaryLine($report['sections'][0], 'Total Quotation')
        );

        $this->post(route('super-admin.reports.export'), $this->exportPayload([
            'report_type' => 'project',
        ]))->assertOk();
    }

    // ------------------------------------------------------------------
    // Export - Project Report
    // ------------------------------------------------------------------

    /**
     * One row per project, however many types or bookings it carries, with
     * both stacked inside their own cell.
     */
    public function test_the_project_report_keeps_one_row_per_project(): void
    {
        $project = $this->project('Busy Project', 'ongoing');
        $this->schedule($project, $this->dateThisYear('08-05'), $this->dateThisYear('08-07'));
        $this->schedule($project, $this->dateThisYear('08-12'), $this->dateThisYear('08-14'));

        foreach (['Aircon Installation', 'Preventive Maintenance'] as $name) {
            $project->projectTypes()->attach(ProjectType::create(['type_name' => $name])->type_id);
        }

        $rows = $this->exportReport('project')['sections'][0]['rows'];

        $this->assertCount(1, $rows);
        $this->assertCount(2, $rows[0]['project_types']);
        $this->assertCount(2, $rows[0]['schedules']);
        $this->assertSame('Commercial', $rows[0]['client_type']);
        $this->assertSame($project->reference_no, $rows[0]['reference_no']);
    }

    /**
     * The two reports answer different questions, and the Schedules column is
     * where that shows.
     *
     * The Project Report is about the project: the period decides whether it
     * is listed, and once it is, its whole schedule is shown. The Schedule
     * Report is about the period: only the bookings that touch it are listed.
     */
    public function test_the_project_report_shows_every_schedule_the_schedule_report_filters(): void
    {
        $project = $this->project('Long Job', 'ongoing');

        foreach ([
            ['07-20', '07-22'],
            ['07-28', '08-01'],
            ['08-10', '08-12'],
            ['08-20', '08-23'],
            ['09-05', '09-07'],
        ] as [$start, $end]) {
            $this->schedule($project, $this->dateThisYear($start), $this->dateThisYear($end));
        }

        $august = $this->monthOf($this->dateThisYear('08-01'));

        // Project Report: all five, July and September included.
        $projectRow = $this->exportReport('project', [], $august)['sections'][0]['rows'][0];

        $this->assertCount(5, $projectRow['schedules']);
        $this->assertStringContainsString('Jul 20', implode(' ', $projectRow['schedules']));
        $this->assertStringContainsString('Sep 5', implode(' ', $projectRow['schedules']));

        // Schedule Report: only the three that touch August.
        $scheduleRow = $this->exportReport('schedule', [], $august)['sections'][0]['rows'][0];

        $this->assertCount(3, $scheduleRow['schedules']);
        $this->assertStringNotContainsString('Jul 20', implode(' ', $scheduleRow['schedules']));
        $this->assertStringNotContainsString('Sep 5', implode(' ', $scheduleRow['schedules']));
    }

    public function test_a_project_with_no_schedule_says_so(): void
    {
        $this->project('Fresh Project', 'unscheduled');

        $rows = $this->exportReport('project')['sections'][0]['rows'];

        $this->assertSame([], $rows[0]['schedules']);
        $this->assertSame([], $rows[0]['project_types']);
    }

    /**
     * Unscheduled first, Completed last, and never alphabetical.
     */
    public function test_the_project_report_orders_statuses_by_the_reporting_order(): void
    {
        $this->project('Fresh', 'unscheduled');
        $this->project('Waiting', Project::STATUS_AWAITING_CLIENT_CONFIRMATION);
        $this->project('Done', 'completed');
        $this->project('Dead', 'cancelled');

        $ongoing = $this->project('Live', 'ongoing');
        $this->schedule($ongoing, $this->dateThisYear('08-10'), $this->dateThisYear('08-14'));

        $late = $this->project('Late', 'ongoing');
        $this->schedule($late, $this->dateThisYear('08-01'), $this->dateThisYear('08-03'));
        $late->forceFill(['status' => 'ongoing'])->save();

        $paused = $this->project('Paused', 'pending');
        $paused->forceFill(['on_hold' => true])->save();

        $rows = $this->exportReport('project')['sections'][0]['rows'];
        $order = $rows->pluck('status_key')->all();

        $this->assertSame('unscheduled', $order[0]);
        $this->assertSame('completed', end($order));
        $this->assertNotContains('archived', $order);

        // Each status appears where the reporting order puts it.
        $expected = array_values(array_filter(
            Project::REPORT_STATUS_ORDER,
            fn (string $status): bool => in_array($status, $order, true)
        ));

        $this->assertSame($expected, array_values(array_unique($order)));
    }

    /**
     * The status filter reads the status the row will actually print, which
     * for a paused or late project is not the status stored on it.
     */
    public function test_the_project_status_filter_matches_the_printed_status(): void
    {
        $paused = $this->project('Paused Project', 'pending');
        $paused->forceFill(['on_hold' => true])->save();

        $late = $this->project('Late Project', 'ongoing');
        $this->schedule($late, $this->dateThisYear('08-01'), $this->dateThisYear('08-04'));
        $late->forceFill(['status' => 'ongoing'])->save();
        // Push the booking into the past so the project reads as Overdue.
        $late->schedules()->update([
            'start_datetime' => $this->day(-20).' 00:00:00',
            'end_datetime' => $this->day(-10).' 23:59:59',
        ]);

        $onHold = $this->exportReport('project', ['project_status' => 'on_hold'])['sections'][0]['rows'];
        $this->assertCount(1, $onHold);
        $this->assertSame('Paused Project Holdings', $onHold[0]['client']);

        $overdue = $this->exportReport(
            'project',
            ['project_status' => 'overdue'],
            $this->monthOf($this->day(-20))
        )['sections'][0]['rows'];

        $this->assertCount(1, $overdue);
        $this->assertSame('overdue', $overdue[0]['status_key']);

        // And the stored status no longer matches either of them.
        $pending = $this->exportReport('project', ['project_status' => 'pending'])['sections'][0]['rows'];
        $this->assertCount(0, $pending);
    }

    /**
     * A cancelled project is part of the record and is shown, but its money
     * was never committed and must not reach the total.
     */
    public function test_cancelled_work_is_listed_but_never_billed(): void
    {
        $this->project('Pending Job', 'pending', [], 100000);
        $this->project('Ongoing Job', 'ongoing', [], 150000);
        $this->project('Done Job', 'completed', [], 200000);
        $this->project('Dead Job', 'cancelled', [], 100000);

        $section = $this->exportReport('project')['sections'][0];

        $this->assertCount(4, $section['rows']);
        $this->assertSame('Total Quotation: ₱450,000.00', $this->summaryLine($section, 'Total Quotation'));

        // It still counts as a project, because it is shown as one.
        $this->assertSame('Cancelled: 1', $this->summaryLine($section, 'Cancelled'));
    }

    /**
     * Archived work is gone from the rows, the counts and the money.
     */
    public function test_archived_projects_are_absent_from_the_project_report(): void
    {
        $this->project('Live Job', 'ongoing', [], 100000);

        $archived = $this->project('Filed Job', 'ongoing', [], 900000, true);
        $this->schedule($archived, $this->dateThisYear('08-01'), $this->dateThisYear('08-09'));

        $section = $this->exportReport('project')['sections'][0];

        $this->assertCount(1, $section['rows']);
        $this->assertSame('Live Job Holdings', $section['rows'][0]['client']);
        $this->assertSame('Total Quotation: ₱100,000.00', $this->summaryLine($section, 'Total Quotation'));
    }

    /**
     * The summary is counted from the rows themselves, so it cannot describe
     * a different set of projects than the table above it.
     */
    public function test_the_project_summary_counts_the_rows_it_sits_under(): void
    {
        $this->project('One', 'pending', [], 50000);
        $this->project('Two', 'pending', [], 50000);
        $this->project('Three', 'completed', [], 75000);

        $section = $this->exportReport('project')['sections'][0];

        $this->assertSame('Pending: 2', $this->summaryLine($section, 'Pending'));
        $this->assertSame('Completed: 1', $this->summaryLine($section, 'Completed'));
        $this->assertSame('Total Quotation: ₱175,000.00', $this->summaryLine($section, 'Total Quotation'));
    }

    // ------------------------------------------------------------------
    // Export - Schedule Report
    // ------------------------------------------------------------------

    /**
     * A booking is printed whole even where it runs outside the period - that
     * is the booking, and trimming the printed dates would describe a visit
     * that never happened. The duration is the part that was August.
     */
    public function test_a_crossing_booking_is_printed_whole_but_counted_in_period(): void
    {
        $project = $this->project('Crossing Job', 'ongoing');
        $this->schedule($project, $this->dateThisYear('07-28'), $this->dateThisYear('08-01'));
        $this->schedule($project, $this->dateThisYear('08-30'), $this->dateThisYear('09-03'));

        $section = $this->exportReport('schedule', [], $this->monthOf($this->dateThisYear('08-01')))['sections'][0];
        $row = $section['rows'][0];

        // Both ends of each range survive into the printed cell.
        $this->assertStringContainsString('Jul 28', $row['schedules'][0]);
        $this->assertStringContainsString('Aug 1', $row['schedules'][0]);
        $this->assertStringContainsString('Aug 30', $row['schedules'][1]);
        $this->assertStringContainsString('Sep 3', $row['schedules'][1]);

        // Aug 1 is one day; Aug 30-31 is two.
        $this->assertSame(3, $row['duration']);
        $this->assertSame('Total Scheduled Days: 3', $this->summaryLine($section, 'Total Scheduled Days'));
    }

    /**
     * Only bookings with a date inside the period are listed, and every one
     * of them is - never just the first.
     */
    public function test_the_schedule_report_lists_every_overlapping_range(): void
    {
        $project = $this->project('Busy Job', 'ongoing');

        foreach ([
            ['07-20', '07-25'],   // wholly before August
            ['07-28', '08-01'],   // crosses in
            ['08-05', '08-07'],
            ['08-15', '08-18'],
            ['08-25', '08-27'],
            ['09-05', '09-07'],   // wholly after August
        ] as [$start, $end]) {
            $this->schedule($project, $this->dateThisYear($start), $this->dateThisYear($end));
        }

        $section = $this->exportReport('schedule', [], $this->monthOf($this->dateThisYear('08-01')))['sections'][0];

        // One row for the project, carrying every August booking.
        $this->assertCount(1, $section['rows']);
        $this->assertCount(4, $section['rows'][0]['schedules']);

        $printed = implode(' | ', $section['rows'][0]['schedules']);

        $this->assertStringContainsString('Jul 28', $printed);
        $this->assertStringNotContainsString('Jul 20', $printed);
        $this->assertStringNotContainsString('Sep 5', $printed);

        // 1 + 3 + 4 + 3, never the 31 the four ends span.
        $this->assertSame(11, $section['rows'][0]['duration']);
        $this->assertSame('Total Schedule Entries: 4', $this->summaryLine($section, 'Total Schedule Entries'));
        $this->assertSame('Total Scheduled Projects: 1', $this->summaryLine($section, 'Total Scheduled Projects'));
        $this->assertSame('Total Scheduled Days: 11', $this->summaryLine($section, 'Total Scheduled Days'));
    }

    /**
     * Ranges inside one cell run in date order.
     */
    public function test_a_project_s_ranges_are_listed_chronologically(): void
    {
        $project = $this->project('Split Job', 'ongoing');
        $this->schedule($project, $this->dateThisYear('08-15'), $this->dateThisYear('08-18'));
        $this->schedule($project, $this->dateThisYear('08-05'), $this->dateThisYear('08-07'));

        $schedules = $this->exportReport(
            'schedule',
            [],
            $this->monthOf($this->dateThisYear('08-01'))
        )['sections'][0]['rows'][0]['schedules'];

        $this->assertStringContainsString('Aug 5', $schedules[0]);
        $this->assertStringContainsString('Aug 15', $schedules[1]);
    }

    /**
     * A yearly report follows the same rule against a year-wide window.
     */
    public function test_a_yearly_report_counts_only_that_year_s_days(): void
    {
        $year = (int) CarbonImmutable::today()->format('Y');
        $project = $this->project('New Year Job', 'ongoing');

        $this->schedule(
            $project,
            CarbonImmutable::create($year - 1, 12, 28)->toDateString(),
            CarbonImmutable::create($year, 1, 5)->toDateString()
        );

        $period = app(SystemReportService::class)->resolveExportPeriod('yearly', null, $year);
        $row = $this->exportReport('schedule', [], $period)['sections'][0]['rows'][0];

        // The booking is shown whole, across the new year.
        $this->assertStringContainsString('Dec 28', $row['schedules'][0]);
        $this->assertStringContainsString('Jan 5', $row['schedules'][0]);
        // Only Jan 1 to Jan 5 belongs to this year.
        $this->assertSame(5, $row['duration']);
    }

    /**
     * Hours booked on a date are one scheduled day, whatever the stored times
     * say - not a fraction, and not several days.
     */
    public function test_a_partial_day_is_one_scheduled_day_in_the_schedule_report(): void
    {
        $project = $this->project('Morning Call', 'ongoing');

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->dateThisYear('08-20').' 08:00:00',
            'end_datetime' => $this->dateThisYear('08-20').' 12:00:00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
        ]);

        $section = $this->exportReport('schedule', [], $this->monthOf($this->dateThisYear('08-01')))['sections'][0];

        $this->assertSame(1, $section['rows'][0]['duration']);
        $this->assertStringContainsString('8:00 AM', $section['rows'][0]['schedules'][0]);
        $this->assertSame('Total Scheduled Days: 1', $this->summaryLine($section, 'Total Scheduled Days'));
    }

    /**
     * Projects run in the order their work starts, so the last row is the
     * latest the period saw - never by client or reference.
     */
    public function test_the_schedule_report_runs_in_date_order(): void
    {
        $late = $this->project('Zulu Job', 'ongoing');
        $this->schedule($late, $this->dateThisYear('08-20'), $this->dateThisYear('08-22'));

        $early = $this->project('Alpha Job', 'ongoing');
        $this->schedule($early, $this->dateThisYear('08-02'), $this->dateThisYear('08-04'));

        $rows = $this->exportReport('schedule', [], $this->monthOf($this->dateThisYear('08-01')))['sections'][0]['rows'];

        $this->assertStringContainsString('Aug 2', $rows[0]['schedules'][0]);
        $this->assertStringContainsString('Aug 20', $rows->last()['schedules'][0]);
    }

    public function test_the_schedule_report_excludes_archived_projects(): void
    {
        $archived = $this->project('Filed Job', 'ongoing', [], 100, true);
        $this->schedule($archived, $this->dateThisYear('08-05'), $this->dateThisYear('08-07'));

        $report = $this->exportReport('schedule', [], $this->monthOf($this->dateThisYear('08-01')));

        $this->assertTrue($report['is_empty']);
        $this->assertSame('Total Scheduled Days: 0', $this->summaryLine($report['sections'][0], 'Total Scheduled Days'));
    }

    // ------------------------------------------------------------------
    // Export - Technician Report
    // ------------------------------------------------------------------

    /**
     * A project counts once for a technician however many times it is booked,
     * and archived work is not theirs to answer for.
     */
    public function test_assigned_projects_count_each_project_once(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $busy = $this->project('Busy Job', 'ongoing', [$ana]);
        $this->schedule($busy, $this->dateThisYear('08-01'), $this->dateThisYear('08-03'));
        $this->schedule($busy, $this->dateThisYear('08-10'), $this->dateThisYear('08-12'));

        $second = $this->project('Second Job', 'ongoing', [$ana]);
        $this->schedule($second, $this->dateThisYear('08-05'), $this->dateThisYear('08-06'));

        $archived = $this->project('Filed Job', 'ongoing', [$ana], 100, true);
        $this->schedule($archived, $this->dateThisYear('08-08'), $this->dateThisYear('08-09'));

        $section = $this->technicianSection('assigned', 'assigned');

        $this->assertCount(1, $section['rows']);
        // Two projects, not three bookings and not three projects.
        $this->assertCount(2, $section['rows'][0]['projects']);
        $this->assertSame('Technician', $section['rows'][0]['position']);
        $this->assertSame('Total Assigned Projects: 2', $this->summaryLine($section, 'Total Assigned Projects'));

        foreach ($section['rows'][0]['projects'] as $entry) {
            $this->assertStringNotContainsString('Filed Job', $entry);
            $this->assertStringContainsString(' - ', $entry);
        }
    }

    public function test_a_lead_technician_reports_their_position(): void
    {
        $lead = $this->lead('Jose Garcia');
        $project = $this->project('Led Job', 'ongoing', [$lead]);
        $this->schedule($project, $this->dateThisYear('08-01'), $this->dateThisYear('08-03'));

        $section = $this->technicianSection('assigned', 'assigned');

        $this->assertSame('Lead Technician', $section['rows'][0]['position']);
    }

    /**
     * Asking for one technician returns that technician and nobody else.
     */
    public function test_a_specific_technician_report_covers_only_them(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Santos');

        foreach ([$ana, $ben] as $technician) {
            $project = $this->project($technician->name.' Job', 'ongoing', [$technician]);
            $this->schedule($project, $this->dateThisYear('08-01'), $this->dateThisYear('08-03'));
        }

        $section = $this->technicianSection('assigned', 'assigned', [
            'technician_scope' => 'specific',
            'technician_id' => $ana->technician_id,
        ]);

        $this->assertCount(1, $section['rows']);
        $this->assertSame('Ana Mendoza', $section['rows'][0]['technician']);
    }

    /**
     * The technician schedule section obeys the same clipping and duration
     * rules as the Schedule Report.
     */
    public function test_the_technician_schedule_uses_the_schedule_report_rules(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Crossing Job', 'ongoing', [$ana]);

        foreach ([
            ['07-28', '08-01'],   // crosses in: one August day
            ['08-05', '08-07'],
            ['08-15', '08-18'],
            ['09-05', '09-07'],   // outside August
        ] as [$start, $end]) {
            $this->schedule($project, $this->dateThisYear($start), $this->dateThisYear($end));
        }

        $section = $this->technicianSection('schedule', 'technician_schedule');
        $row = $section['groups'][0]['rows'][0];

        $this->assertCount(1, $section['groups']);
        $this->assertSame('Ana Mendoza', $section['groups'][0]['technician']);

        // Every overlapping range, never just the first, and not September.
        $this->assertCount(3, $row['schedules']);
        $this->assertStringNotContainsString('Sep', implode(' ', $row['schedules']));

        // 1 + 3 + 4, not the 22 days from July 28 to August 18.
        $this->assertSame(8, $row['duration']);
        $this->assertSame('Total Scheduled Days: 8', $this->summaryLine($section, 'Total Scheduled Days'));
        $this->assertSame('Total Schedule Entries: 3', $this->summaryLine($section, 'Total Schedule Entries'));
    }

    /**
     * Tasks are grouped under the technician holding them, and a task on an
     * archived project is not reported at all.
     */
    public function test_the_task_section_groups_by_technician_and_skips_archived_work(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $live = $this->project('Live Job', 'ongoing', [$ana]);
        $archived = $this->project('Filed Job', 'ongoing', [$ana], 100, true);

        $this->task($live, $ana, 'Pull the wiring', $this->dateThisYear('08-04'), 'ongoing');
        $this->task($live, $ana, 'Close the ceiling', $this->dateThisYear('08-02'), 'completed');
        $this->task($archived, $ana, 'Should not appear', $this->dateThisYear('08-06'), 'ongoing');

        $section = $this->technicianSection('tasks', 'technician_tasks');

        $this->assertCount(1, $section['groups']);
        $this->assertCount(2, $section['groups'][0]['rows']);
        // Oldest deadline first.
        $this->assertSame('Close the ceiling', $section['groups'][0]['rows'][0]['task']);
        $this->assertSame('Total Tasks: 2', $this->summaryLine($section, 'Total Tasks'));
        $this->assertSame('Completed: 1', $this->summaryLine($section, 'Completed'));

        $titles = collect($section['groups'][0]['rows'])->pluck('task')->all();
        $this->assertNotContains('Should not appear', $titles);
    }

    /**
     * "All" carries the three sections, each with its own table and its own
     * summary - and no utilization anywhere.
     */
    public function test_the_all_technician_report_carries_every_section(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Live Job', 'ongoing', [$ana]);
        $this->schedule($project, $this->dateThisYear('08-01'), $this->dateThisYear('08-03'));
        $this->task($project, $ana, 'Pull the wiring', $this->dateThisYear('08-02'), 'ongoing');

        $report = $this->exportReport('technician', ['technician_kind' => 'all']);

        $this->assertSame(
            ['assigned', 'technician_schedule', 'technician_tasks'],
            collect($report['sections'])->pluck('key')->all()
        );

        // Every section carries its own summary rather than one at the top.
        foreach ($report['sections'] as $section) {
            $this->assertNotEmpty($section['summary']);
        }

        $labels = collect($report['sections'])
            ->flatMap(fn (array $section) => collect($section['summary'])->pluck('label'))
            ->implode(' ');

        foreach (['Utilization', 'Capacity', 'Available Hours'] as $banned) {
            $this->assertStringNotContainsString($banned, $labels);
        }
    }

    /**
     * The technician sections are the only place assignments are read from,
     * and they read the project relationship - not the schedule rows.
     */
    public function test_a_technician_with_only_archived_work_is_not_reported(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $archived = $this->project('Filed Job', 'ongoing', [$ana], 100, true);
        $this->schedule($archived, $this->dateThisYear('08-01'), $this->dateThisYear('08-03'));

        $report = $this->exportReport('technician', ['technician_kind' => 'all']);

        $this->assertTrue($report['is_empty']);
    }

    // ------------------------------------------------------------------
    // Export helpers
    // ------------------------------------------------------------------

    /**
     * A valid export request, with only the fields the report needs.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function exportPayload(array $overrides = []): array
    {
        return array_merge([
            'report_type' => 'project',
            'period' => 'monthly',
            'month' => CarbonImmutable::today()->format('n'),
            'year' => CarbonImmutable::today()->format('Y'),
        ], $overrides);
    }

    /**
     * The month a date falls in, as a resolved reporting period.
     *
     * @return array<string, mixed>
     */
    private function monthOf(string $date): array
    {
        $day = CarbonImmutable::parse($date);

        return app(SystemReportService::class)->resolveExportPeriod(
            'monthly',
            (int) $day->format('n'),
            (int) $day->format('Y')
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>|null  $period
     * @return array<string, mixed>
     */
    private function exportReport(string $type, array $filters = [], ?array $period = null): array
    {
        return app(SystemReportService::class)->exportReport(
            $type,
            $period ?? $this->monthOf(CarbonImmutable::today()->toDateString()),
            $filters
        );
    }

    /**
     * One section of a Technician Report, by the kind that produces it.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function technicianSection(string $kind, string $key, array $filters = []): array
    {
        $report = $this->exportReport(
            'technician',
            array_merge(['technician_kind' => $kind], $filters),
            $this->monthOf($this->dateThisYear('08-01'))
        );

        return collect($report['sections'])->firstWhere('key', $key);
    }

    /**
     * "Total Quotation: ₱450,000.00" - a summary line as it will be printed,
     * so the assertion reads the way the PDF does.
     *
     * @param  array<string, mixed>  $section
     */
    private function summaryLine(array $section, string $label): ?string
    {
        $line = collect($section['summary'])->firstWhere('label', $label);

        return $line ? $line['label'].': '.$line['value'] : null;
    }

    private function task(
        Project $project,
        Technician $technician,
        string $title,
        string $dueDate,
        string $status = 'ongoing'
    ): Task {
        return Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
            'task_title' => $title,
            'task_description' => 'Description',
            'start_date' => CarbonImmutable::parse($dueDate)->subDay()->toDateString(),
            'due_date' => $dueDate,
            'status' => $status,
        ]);
    }
}
