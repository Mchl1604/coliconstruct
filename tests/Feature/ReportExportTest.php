<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\Client;
use App\Models\Project;
use App\Models\Schedule;
use App\Services\SystemReportService;
use App\Support\CompanyBranding;
use App\Support\ReportPdf;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Exporting a report: that the PDF builds on a machine that is not a
 * developer's, that every table reads from the same left edge in all three
 * renderings, and that the Created Projects Report contains what its name
 * says it does.
 *
 * The PDF half exists because the exports worked locally and failed once
 * deployed. Anything the renderer needs from its environment - an image
 * format, a writable directory, a PHP extension - is asserted here rather
 * than assumed, so the next environment difference is a red test instead of
 * "Unable to build the PDF." in somebody's browser.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    // ==================================================================
    // PDF generation
    // ==================================================================

    /**
     * The one that was actually broken in deployment.
     *
     * dompdf 3.x throws out of Cpdf::addPngFromFile() the moment it is handed
     * a PNG on a PHP build without ext-gd, and nothing in the render catches
     * it. Nothing else in this application needs GD, so a PHP build without
     * it is a perfectly ordinary thing to deploy onto - and the letterhead
     * being a PNG turned that into a 500 on every export, while the on-screen
     * preview, drawn by the browser, carried on working.
     */
    public function test_the_letterhead_is_a_jpeg_so_no_php_extension_is_required_to_embed_it(): void
    {
        $uri = CompanyBranding::logoDataUri();

        $this->assertNotNull($uri, 'The letterhead should be embeddable.');
        $this->assertStringStartsWith('data:image/jpeg;base64,', $uri);
        $this->assertStringEndsWith('.jpg', (string) CompanyBranding::letterheadLogoPath());
    }

    /**
     * And the JPEG is the one a deployment gets, whether or not GD happens to
     * be installed on the machine running this test.
     */
    public function test_the_png_letterhead_is_never_offered_without_gd(): void
    {
        $this->assertFileExists(public_path('img/coliconstruct-letterhead.jpg'));

        // The JPEG is listed first, so it wins on every build of PHP. The PNG
        // is a fallback for where GD is present, never a requirement.
        $this->assertStringContainsString(
            'image/jpeg',
            (string) CompanyBranding::logoDataUri()
        );
    }

    public function test_every_report_type_exports_a_pdf_that_opens(): void
    {
        $this->projectCreatedOn(CarbonImmutable::today()->toDateString());

        foreach (array_keys(ReportController::EXPORT_TYPES) as $type) {
            $response = $this->post(route('super-admin.reports.export'), $this->payload([
                'report_type' => $type,
            ]));

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');

            $pdf = $response->getContent();

            $this->assertStringStartsWith('%PDF-', $pdf, "{$type} did not produce a PDF.");
            $this->assertStringContainsString('%%EOF', $pdf, "{$type} produced a truncated PDF.");
        }
    }

    /**
     * A deployment whose image is built without the public assets - or with
     * them somewhere else - still gets its report. The letterhead is worth
     * printing and is not worth failing an export over.
     */
    public function test_a_report_still_renders_when_the_letterhead_is_missing(): void
    {
        $this->projectCreatedOn(CarbonImmutable::today()->toDateString());

        $pdf = ReportPdf::render('super-admin.reports-pdf', $this->documentFor('project', null));

        $this->assertStringStartsWith('%PDF-', $pdf->output());
    }

    /**
     * The two directories dompdf writes to are created before a render rather
     * than prepared by hand after a deploy.
     */
    public function test_it_creates_the_font_directory_dompdf_expects_to_exist(): void
    {
        $fontDir = storage_path('framework/testing/dompdf-fonts-'.uniqid());

        config(['dompdf.options.font_dir' => $fontDir]);

        $this->assertDirectoryDoesNotExist($fontDir);

        ReportPdf::prepareStorage();

        $this->assertDirectoryExists($fontDir);

        rmdir($fontDir);
    }

    /**
     * A container with a read-only temporary directory is a real deployment,
     * and dompdf writes every embedded image out to one before it can measure
     * it. storage/ is writable by definition - the framework could not boot
     * otherwise - so that is where the export goes instead of failing.
     */
    public function test_an_unusable_temp_directory_falls_back_to_storage(): void
    {
        config(['dompdf.options.temp_dir' => '/nonexistent-'.uniqid()]);

        ReportPdf::prepareStorage();

        $tempDir = (string) config('dompdf.options.temp_dir');

        $this->assertSame(storage_path('app/dompdf/tmp'), $tempDir);
        $this->assertDirectoryIsWritable($tempDir);
    }

    /**
     * The command an administrator runs after a deploy to find out whether
     * this environment can export at all.
     */
    public function test_the_pdf_environment_check_renders_a_document(): void
    {
        $this->artisan('pdf:check')
            ->expectsOutputToContain('PDF export works here')
            ->assertSuccessful();
    }

    /**
     * A render that fails says one sentence to the page and everything it
     * knows to the log. The cause is never swallowed and never dressed up as
     * a success.
     */
    public function test_a_failed_render_is_logged_with_its_cause(): void
    {
        Log::spy();

        try {
            ReportPdf::render('super-admin.no-such-report', []);
            $this->fail('Rendering a missing view should not succeed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The PDF could not be built.', $exception->getMessage());
            $this->assertNotNull($exception->getPrevious());
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'PDF export failed.'
                && isset($context['exception'])
                && array_key_exists('ext_gd', $context)
                && array_key_exists('temp_dir_writable', $context))
            ->once();
    }

    /**
     * The extensions dompdf genuinely cannot render without - as opposed to
     * GD, which this application deliberately no longer needs.
     */
    public function test_the_required_extensions_are_present(): void
    {
        $this->assertSame([], ReportPdf::missingExtensions());
    }

    /**
     * The quiet failure: dompdf that cannot read its font does not complain.
     * It writes the text as glyph indices, embeds no font program, and returns
     * a document whose rules, tables and images are all correct and whose
     * every word is invisible - the reader's viewer substitutes a font and
     * reads those glyph indices as character codes, which is why the result
     * comes out as Arabic and Greek.
     *
     * So the bytes are checked, not just the exit status.
     */
    public function test_every_export_embeds_its_font_program(): void
    {
        $this->projectCreatedOn(CarbonImmutable::today()->toDateString());

        foreach (array_keys(ReportController::EXPORT_TYPES) as $type) {
            $pdf = $this->post(route('super-admin.reports.export'), $this->payload([
                'report_type' => $type,
            ]))->getContent();

            $this->assertTrue(
                ReportPdf::embedsFontProgram($pdf),
                "{$type} exported a document with no embedded font - its text would be unreadable."
            );
        }
    }

    /**
     * The failure that looked like a working export.
     *
     * dompdf writes Unicode code points into an Identity-H content stream
     * unless font subsetting is on, so the reader draws the wrong glyph for
     * every character and the report comes out as Arabic and Greek. The
     * /ToUnicode map it writes alongside is a flat identity, which means the
     * text still extracts correctly - so this has to be asserted on the font
     * tags, not by reading the document back.
     */
    public function test_every_export_subsets_its_fonts(): void
    {
        $this->projectCreatedOn(CarbonImmutable::today()->toDateString());

        foreach (array_keys(ReportController::EXPORT_TYPES) as $type) {
            $pdf = $this->post(route('super-admin.reports.export'), $this->payload([
                'report_type' => $type,
            ]))->getContent();

            $this->assertTrue(
                ReportPdf::subsetsFonts($pdf),
                "{$type} embedded an unsubsetted font - every character would draw the wrong glyph."
            );
        }
    }

    /** Subsetting is what makes the text legible, so it is not optional. */
    public function test_font_subsetting_is_enabled(): void
    {
        $this->assertTrue(config('dompdf.options.enable_font_subsetting'));
    }

    /**
     * A subsetted report is a fraction of the size of an unsubsetted one.
     * Asserted loosely: the point is that the whole font is not being carried,
     * not that it lands on a particular number of kilobytes.
     */
    public function test_a_report_is_not_carrying_whole_font_files(): void
    {
        $this->projectCreatedOn(CarbonImmutable::today()->toDateString());

        $pdf = $this->post(route('super-admin.reports.export'), $this->payload())->getContent();

        // One full DejaVu face alone is about 700 KB; three of them were what
        // an unsubsetted export used to ship.
        $this->assertLessThan(700 * 1024, strlen($pdf));
    }

    public function test_the_report_font_is_present_and_parseable_on_this_installation(): void
    {
        $this->assertSame([], ReportPdf::missingFontFiles(), 'A font file is missing.');
        $this->assertSame([], ReportPdf::unreadableFontFiles(), 'A font file is present but corrupt.');
    }

    /**
     * An installation missing its font files is refused outright. A report
     * that cannot be read is worse than a report that did not arrive, because
     * only one of the two is obvious to whoever asked for it.
     */
    public function test_a_missing_font_file_is_refused_rather_than_rendered_unreadably(): void
    {
        $font = ReportPdf::missingFontFiles() === []
            ? $this->fontPath()
            : null;

        $this->assertNotNull($font, 'Expected a readable font to hide.');

        $hidden = $font.'.hidden';
        rename($font, $hidden);

        try {
            $this->assertNotSame([], ReportPdf::missingFontFiles());

            $this->expectException(RuntimeException::class);

            ReportPdf::render('super-admin.reports-pdf', $this->documentFor('project'));
        } finally {
            rename($hidden, $font);
        }
    }

    public function test_the_check_command_fails_when_a_font_file_is_missing(): void
    {
        $font = $this->fontPath();
        $hidden = $font.'.hidden';

        rename($font, $hidden);

        try {
            $this->artisan('pdf:check')
                ->expectsOutputToContain('Font files are missing')
                ->assertFailed();
        } finally {
            rename($hidden, $font);
        }
    }

    /** The regular face of the report font, as dompdf resolves it. */
    private function fontPath(): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
    }

    // ==================================================================
    // Table alignment
    // ==================================================================

    /**
     * Every column of every report reads from the same left edge, in the
     * browser preview and in the PDF alike. A count used to be pushed right,
     * which made two columns of one table disagree about where a value starts.
     */
    public function test_no_report_table_cell_is_centred_or_right_aligned(): void
    {
        $this->fixture();

        foreach (array_keys(ReportController::EXPORT_TYPES) as $type) {
            $preview = $this->postJson(route('super-admin.reports.preview'), $this->payload([
                'report_type' => $type,
            ]));

            $preview->assertOk();

            $html = (string) $preview->json('html');

            $this->assertStringNotContainsString('class="num"', $html, "{$type} preview right-aligns a column.");
            $this->assertStringNotContainsString('text-align:right', $this->squash($html), "{$type} preview right-aligns a cell.");
            $this->assertStringNotContainsString('text-align:center', $this->squash($html), "{$type} preview centres a cell.");
        }
    }

    /**
     * The same, asserted on the PDF template. Its stylesheet is written
     * separately from the preview's - dompdf and a browser do not read the
     * same CSS - which is exactly why the two can drift and must be checked
     * apart.
     */
    public function test_the_pdf_template_left_aligns_every_table_cell(): void
    {
        $this->fixture();

        foreach (array_keys(ReportController::EXPORT_TYPES) as $type) {
            $html = $this->squash(
                view('super-admin.reports-pdf', $this->documentFor($type))->render()
            );

            $this->assertStringContainsString('table.datath{', $html, "{$type} lost its header rule.");
            $this->assertStringNotContainsString('class="num"', $html, "{$type} PDF right-aligns a column.");
        }

        $sheet = $this->squash(file_get_contents(
            resource_path('views/super-admin/reports-pdf.blade.php')
        ));

        // Both halves of the table say so outright rather than relying on a
        // default that a parent element can change.
        $this->assertStringContainsString('table.datath{background:#f1f5f9;color:#0f172a;font-size:8px;font-weight:bold;text-align:left;', $sheet);
        $this->assertStringContainsString('text-align:left;/*Longclientnameswrap;', $sheet);
    }

    /**
     * The browser preview and the print sheet are one stylesheet, so print
     * cannot align differently from what was reviewed on screen.
     */
    public function test_the_preview_and_print_stylesheet_left_aligns_every_table_cell(): void
    {
        $css = $this->squash(file_get_contents(public_path('css/super-admin/report-print.css')));

        $this->assertStringContainsString('.report-doctable.datath{', $css);
        $this->assertStringContainsString('text-align:left;', $css);

        // The one rule that used to push a column to the right is gone, not
        // merely overridden somewhere further down.
        $this->assertStringNotContainsString('.report-doc.num{text-align:right;}', $css);
    }

    /**
     * The audit trail exports through the same renderer and prints the same
     * kind of table, so it is held to the same rule.
     */
    public function test_the_activity_log_export_left_aligns_every_table_cell(): void
    {
        $sheet = $this->squash(file_get_contents(
            resource_path('views/super-admin/activity-logs-pdf.blade.php')
        ));

        $this->assertStringContainsString('table.datath{background:#1e293b;color:#ffffff;font-size:8px;text-align:left;', $sheet);
        $this->assertStringContainsString('vertical-align:top;/*Everycolumnreadsfromthesameleftedge,idsincluded.*/text-align:left;', $sheet);
    }

    // ==================================================================
    // Created Projects Report
    // ==================================================================

    public function test_the_report_is_named_for_what_it_contains(): void
    {
        $this->assertSame(
            'Created Projects Report',
            SystemReportService::EXPORT_TYPES['created_projects']
        );

        $this->get(route('super-admin.reports.index'))
            ->assertOk()
            ->assertSee('Created Projects Report')
            ->assertDontSee('New Projects Report');

        $preview = $this->postJson(route('super-admin.reports.preview'), $this->payload([
            'report_type' => 'created_projects',
        ]));

        $preview->assertOk()->assertJsonPath('title', 'Created Projects Report');
        $this->assertStringContainsString('Created Projects', (string) $preview->json('html'));
    }

    /** The download is named after the report, not after what it used to be. */
    public function test_the_exported_file_is_named_after_the_report(): void
    {
        $this->post(route('super-admin.reports.export'), $this->payload([
            'report_type' => 'created_projects',
        ]))->assertDownload();

        $disposition = $this->post(route('super-admin.reports.export'), $this->payload([
            'report_type' => 'created_projects',
        ]))->headers->get('content-disposition');

        $this->assertStringContainsString('created-projects-', (string) $disposition);
        $this->assertStringNotContainsString('new-projects', (string) $disposition);
    }

    public function test_a_project_created_inside_the_range_is_included(): void
    {
        $project = $this->projectCreatedOn('2026-08-14');

        $rows = $this->createdProjects('2026-08');

        $this->assertCount(1, $rows);
        $this->assertSame($project->reference_no, $rows[0]['reference_no']);
        $this->assertSame('Aug 14, 2026', $rows[0]['created_on']);
    }

    public function test_a_project_created_outside_the_range_is_excluded(): void
    {
        $this->projectCreatedOn('2026-07-31');
        $this->projectCreatedOn('2026-09-01');

        $this->assertCount(0, $this->createdProjects('2026-08'));
    }

    /** Both ends of the range count as inside it. */
    public function test_the_first_and_last_day_of_the_range_are_inside_it(): void
    {
        $this->projectCreatedOn('2026-08-01');
        $this->projectCreatedOn('2026-08-31 23:30:00');

        $this->assertCount(2, $this->createdProjects('2026-08'));
    }

    /**
     * Inclusion is the creation date and nothing else.
     *
     * This project was created in August, booked to start in September,
     * finished in October and edited in November. Only August's report
     * carries it - a report named for a creation date that quietly filtered on
     * a schedule would be a different report wearing this one's name.
     */
    public function test_inclusion_ignores_the_start_completion_and_update_dates(): void
    {
        $project = $this->projectCreatedOn('2026-08-20');

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => '2026-09-07 00:00:00',
            'end_datetime' => '2026-09-11 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        $project->forceFill([
            'completed_at' => CarbonImmutable::parse('2026-10-02'),
            'updated_at' => CarbonImmutable::parse('2026-11-19'),
        ])->save();

        $this->assertCount(1, $this->createdProjects('2026-08'), 'The creation month should carry it.');
        $this->assertCount(0, $this->createdProjects('2026-09'), 'The schedule month should not.');
        $this->assertCount(0, $this->createdProjects('2026-10'), 'The completion month should not.');
        $this->assertCount(0, $this->createdProjects('2026-11'), 'The month it was last edited should not.');
    }

    public function test_archived_projects_are_excluded(): void
    {
        $this->projectCreatedOn('2026-08-14')->forceFill(['is_archived' => true])->save();

        $this->assertCount(0, $this->createdProjects('2026-08'));
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * The rows of the Created Projects Report for one month, given as
     * "YYYY-MM".
     *
     * @return array<int, array<string, mixed>>
     */
    private function createdProjects(string $month): array
    {
        $day = CarbonImmutable::parse($month.'-01');

        $period = app(SystemReportService::class)->resolveExportPeriod(
            'monthly',
            (int) $day->format('n'),
            (int) $day->format('Y')
        );

        return app(SystemReportService::class)
            ->exportReport('created_projects', $period)['sections'][0]['rows']
            ->all();
    }

    private function projectCreatedOn(string $createdAt): Project
    {
        $project = Project::create([
            'name' => 'Project '.uniqid(),
            'reference_no' => 'REF-'.strtoupper(substr(md5(uniqid()), 0, 8)),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 250000,
            'is_archived' => false,
        ]);

        $this->finalizePhases($project);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Client Holdings '.$project->project_id,
            'firstname' => 'Client',
            'surname' => 'Of '.$project->project_id,
            'fullname' => 'Client Of '.$project->project_id,
            'email_address' => 'client'.$project->project_id.'@example.test',
            'contact_number' => '09123456789',
        ]);

        // Written after creation, because `created_at` is filled by the model.
        $project->forceFill(['created_at' => CarbonImmutable::parse($createdAt)])->save();

        return $project->refresh();
    }

    /** One project, so a report has something to print. */
    private function fixture(): Project
    {
        return $this->projectCreatedOn(CarbonImmutable::today()->toDateString());
    }

    /**
     * Everything the PDF template needs, built the way the controller builds
     * it.
     *
     * @return array<string, mixed>
     */
    private function documentFor(string $type, ?string $logoData = null): array
    {
        $reports = app(SystemReportService::class);

        $today = CarbonImmutable::today();
        $period = $reports->resolveExportPeriod('monthly', (int) $today->format('n'), (int) $today->format('Y'));
        $report = $reports->exportReport($type, $period, []);

        return [
            'report' => $report,
            'reportType' => $type,
            'reportTitle' => $report['title'],
            'period' => $period,
            'appliedFilters' => [],
            'generatedBy' => 'Test Super Admin',
            'generatedAt' => CarbonImmutable::now(),
            'logoData' => $logoData,
            'company' => CompanyBranding::letterhead(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'report_type' => 'project',
            'period' => 'monthly',
            'month' => CarbonImmutable::today()->format('n'),
            'year' => CarbonImmutable::today()->format('Y'),
        ], $overrides);
    }

    /**
     * Markup with its whitespace taken out, so an assertion about a CSS rule
     * is not also an assertion about how the file happens to be indented.
     */
    private function squash(string $html): string
    {
        return (string) preg_replace('/\s+/', '', $html);
    }
}
