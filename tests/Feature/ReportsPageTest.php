<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Skill;
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
                'image_path' => 'technician-reports/sample-'.$report->id.'-'.$index.'.jpg',
            ]);
        }

        return $report;
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
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
        $fresh = $this->project('Unscheduled Project', 'not_yet_scheduled');
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
        $response->assertJsonPath('total', 2);

        $titles = collect($response->json('reports'))->pluck('report_title')->all();

        $this->assertContains('Report On First', $titles);
        // Reports outlive their project's status; the repository shows them all.
        $this->assertContains('Report On Second', $titles);
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
        $byProject->assertJsonPath('total', 2);

        $byType = $this->getJson(route('super-admin.reports.technician', [
            'report_type' => 'incident',
        ]));
        $byType->assertOk();
        $byType->assertJsonPath('total', 1);
        $byType->assertJsonPath('reports.0.report_title', 'Alpha Incident');

        $both = $this->getJson(route('super-admin.reports.technician', [
            'project_id' => $beta->project_id,
            'report_type' => 'incident',
        ]));
        $both->assertOk();
        $both->assertJsonPath('total', 0);
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
            $response->assertJsonPath('total', 1);
            $response->assertJsonPath('reports.0.report_title', $expected);
        }

        // Description text is searchable too - both reports share it.
        $this->getJson(route('super-admin.reports.technician', ['search' => 'Second line']))
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_it_filters_by_date_window(): void
    {
        $tech = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', 'ongoing', [$tech]);

        $this->report($project, $tech, 'Today Report', 'progress', $this->day(0));
        $this->report($project, $tech, 'Old Report', 'progress', $this->day(-60));

        $today = $this->getJson(route('super-admin.reports.technician', ['date_filter' => 'today']));
        $today->assertOk();
        $today->assertJsonPath('total', 1);
        $today->assertJsonPath('reports.0.report_title', 'Today Report');

        $custom = $this->getJson(route('super-admin.reports.technician', [
            'date_filter' => 'custom',
            'start_date' => $this->day(-70),
            'end_date' => $this->day(-50),
        ]));
        $custom->assertOk();
        $custom->assertJsonPath('total', 1);
        $custom->assertJsonPath('reports.0.report_title', 'Old Report');

        $all = $this->getJson(route('super-admin.reports.technician', ['date_filter' => 'all']));
        $all->assertJsonPath('total', 2);
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
     * What both forms do today: with no technician on the request, the report
     * is attributed to the project's lead rather than a hard-coded id.
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
     * The summary figures no longer appear on screen, but the exported PDF
     * still opens with them, so they have to stay correct.
     */
    public function test_the_export_summary_still_reports_every_group(): void
    {
        $lead = $this->technician('Jose Garcia', 'lead_technician');
        $ana = $this->technician('Ana Mendoza');
        $skill = Skill::create(['skill_name' => 'Aircon Repair']);
        $ana->skills()->attach($skill->skill_id);

        $ongoing = $this->project('Ongoing Project', 'ongoing', [$lead, $ana], 500000);
        $this->schedule($ongoing, $this->day(-2), $this->day(4));

        $late = $this->project('Late Project', 'ongoing', [$ana], 250000);
        $this->schedule($late, $this->day(-20), $this->day(-10));

        Task::create([
            'project_id' => $ongoing->project_id,
            'technician_id' => $ana->technician_id,
            'task_title' => 'Some Task',
            'task_description' => 'Description',
            'start_date' => $this->day(-2),
            'due_date' => $this->day(1),
            'status' => 'completed',
        ]);

        $service = app(SystemReportService::class);
        $summary = $service->summary($service->resolvePeriod('monthly'));

        foreach (['projects', 'quotations', 'technicians', 'schedules', 'tasks'] as $group) {
            $this->assertIsArray($summary[$group] ?? null, "Missing {$group} summary.");
        }

        // The overdue project is counted, and reuses Project::overdue().
        $this->assertSame(1, $summary['projects']['overdue']);
        $this->assertSame(2, $summary['quotations']['total_approved']);
        $this->assertSame(750000.0, $summary['quotations']['total_value']);
        $this->assertSame(2, $summary['technicians']['total']);
        $this->assertSame(2, $summary['technicians']['assigned']);
        $this->assertSame(100.0, $summary['technicians']['utilization']);
        $this->assertSame(1, $summary['tasks']['completed']);
    }

    /**
     * The export period keeps its own bucket sizes, otherwise a yearly report
     * would try to plot 365 daily points.
     */
    public function test_the_export_period_controls_its_bucket(): void
    {
        $service = app(SystemReportService::class);

        $this->assertSame('day', $service->resolvePeriod('weekly')['bucket']);
        $this->assertSame('day', $service->resolvePeriod('monthly')['bucket']);
        $this->assertSame('month', $service->resolvePeriod('yearly')['bucket']);
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
    public function test_the_current_breakdown_counts_derived_statuses(): void
    {
        $ongoing = $this->project('Ongoing Project', 'ongoing');
        $this->schedule($ongoing, $this->day(-1), $this->day(5));

        $late = $this->project('Late Project', 'ongoing');
        $this->schedule($late, $this->day(-20), $this->day(-10));

        $paused = $this->project('Paused Project', 'pending');
        $paused->forceFill(['on_hold' => true])->save();

        $this->project('Done Project', 'completed');

        $response = $this->getJson(route('super-admin.reports.system.chart', [
            'chart' => 'currentProjectBreakdown',
        ]));

        $response->assertOk();

        $slices = array_combine(
            $response->json('data.labels'),
            $response->json('data.values')
        );

        $this->assertSame(1, $slices['Ongoing']);
        $this->assertSame(1, $slices['Overdue']);
        $this->assertSame(1, $slices['On Hold']);
        $this->assertSame(1, $slices['Completed']);
        // The overdue and paused projects were moved out of their stored
        // status rather than counted twice.
        $this->assertArrayNotHasKey('Pending', $slices);
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

    public function test_a_custom_period_uses_the_supplied_window(): void
    {
        $service = app(SystemReportService::class);

        $period = $service->resolvePeriod('custom', $this->day(-5), $this->day(-1));

        $this->assertSame($this->day(-5), $period['start']->toDateString());
        $this->assertSame($this->day(-1), $period['end']->toDateString());
        $this->assertSame('day', $period['bucket']);

        // Reversed dates are corrected rather than producing an empty range.
        $flipped = $service->resolvePeriod('custom', $this->day(-1), $this->day(-5));

        $this->assertSame($this->day(-5), $flipped['start']->toDateString());
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    public function test_it_exports_a_pdf_for_every_report_type(): void
    {
        $tech = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', 'ongoing', [$tech], 400000);
        $this->schedule($project, $this->day(-2), $this->day(3));

        foreach (array_keys(ReportController::EXPORT_TYPES) as $type) {
            $response = $this->post(route('super-admin.reports.export'), [
                'report_type' => $type,
                'period' => 'monthly',
            ]);

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');

            $body = $response->getContent();

            $this->assertStringStartsWith('%PDF-', $body, "{$type} did not produce a PDF.");
        }
    }

    public function test_a_custom_export_requires_both_dates(): void
    {
        $response = $this->postJson(route('super-admin.reports.export'), [
            'report_type' => 'projects',
            'period' => 'custom',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('start date is required', $response->json('error'));
    }

    public function test_an_unknown_report_type_is_rejected(): void
    {
        $response = $this->postJson(route('super-admin.reports.export'), [
            'report_type' => 'everything',
            'period' => 'monthly',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Only inline PNG data URIs are accepted as chart images, so a crafted
     * value can't reach dompdf's image loader.
     */
    public function test_only_png_data_uris_are_accepted_as_chart_images(): void
    {
        $this->project('Some Project', 'ongoing', [], 100000);

        $png = 'data:image/png;base64,'
            .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==';

        $response = $this->post(route('super-admin.reports.export'), [
            'report_type' => 'projects',
            'period' => 'monthly',
            'charts' => [
                'projectsOverTime' => $png,
                // Both of these must be discarded.
                'projectsByStatus' => 'https://example.test/evil.png',
                'projectCompletionTrend' => '/etc/passwd',
            ],
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }
}
