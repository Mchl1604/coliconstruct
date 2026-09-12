<?php

namespace App\Console\Commands;

use App\Services\SystemReportService;
use App\Support\CompanyBranding;
use App\Support\ReportPdf;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Proves PDF export works on the machine it is run on.
 *
 * The exports are built by dompdf, which depends on things a deployment does
 * not necessarily have: PHP extensions, writable scratch directories, and
 * enough memory to lay out a landscape table. When one of those is missing the
 * browser can only say the PDF could not be built - this says which one, and
 * then renders a real document to prove the answer.
 *
 * The same shape as `mail:test`: run it after a deploy, read one screen, know.
 */
class CheckPdfEnvironment extends Command
{
    protected $signature = 'pdf:check
        {--keep= : Write the rendered documents to this directory}
        {--report= : Also render one real report - project, created_projects, schedule or technician}';

    protected $description = 'Check that this environment can build PDF exports, and render one to prove it.';

    public function handle(): int
    {
        ReportPdf::prepareStorage();

        $diagnostics = ReportPdf::diagnostics();

        $this->line('');
        $this->line('  PHP ................. '.$diagnostics['php_version']);
        $this->line('  Memory limit ........ '.$diagnostics['memory_limit']);
        $this->line('  ext-dom ............. '.$this->yesNo($diagnostics['ext_dom']));
        $this->line('  ext-mbstring ........ '.$this->yesNo($diagnostics['ext_mbstring']));
        // Not required, and deliberately so - the letterhead is a JPEG because
        // dompdf refuses every PNG without it.
        $this->line('  ext-gd (optional) ... '.$this->yesNo($diagnostics['ext_gd']));
        $this->line('  Font directory ...... '.$diagnostics['font_dir'].' '.$this->writable($diagnostics['font_dir_writable']));
        $this->line('  Temp directory ...... '.$diagnostics['temp_dir'].' '.$this->writable($diagnostics['temp_dir_writable']));
        $this->line('  Public path ......... '.$diagnostics['public_path']);
        $this->line('  Letterhead .......... '.($diagnostics['letterhead'] ?? 'none - documents print without a logo'));
        $this->line('  Report font ......... '.($diagnostics['missing_font_files']
            ? count($diagnostics['missing_font_files']).' FILE(S) MISSING'
            : ReportPdf::FONT_FAMILY.' (readable)'));
        $this->line('');

        if ($missing = ReportPdf::missingExtensions()) {
            $this->error('Missing PHP extension(s): '.implode(', ', $missing).'. Install them and run this again.');

            return self::FAILURE;
        }

        if (! $diagnostics['temp_dir_writable']) {
            $this->error('No writable temporary directory. Set DOMPDF_TEMP_DIR to one dompdf may write to.');

            return self::FAILURE;
        }

        // The quiet one. dompdf renders a perfectly laid-out document with
        // every word invisible when it cannot read its font, so this is
        // checked before the render and again on the bytes that come out.
        if ($missing = $diagnostics['missing_font_files']) {
            $this->error('Font files are missing - exports would render as unreadable glyphs:');

            foreach ($missing as $file) {
                $this->line('    '.$file);
            }

            $this->line('');
            $this->line('  These ship inside the dompdf package. Reinstall it on this machine:');
            $this->line('    composer install --no-dev --optimize-autoloader');

            return self::FAILURE;
        }

        if ($broken = ReportPdf::unreadableFontFiles()) {
            $this->error('Font files are present but unusable - a truncated or corrupt install:');

            foreach ($broken as $file) {
                $this->line('    '.$file);
            }

            $this->line('');
            $this->line('  Reinstall the package on this machine:');
            $this->line('    rm -rf vendor && composer install --no-dev --optimize-autoloader');

            return self::FAILURE;
        }

        if (($status = $this->renderSmokeTest()) !== self::SUCCESS) {
            return $status;
        }

        return $this->renderRealReport();
    }

    /**
     * Render an actual report through the actual template.
     *
     * The smoke test proves the environment; this proves the document. They
     * are different templates - the report has a repeating fixed header, page
     * counters and a wider table - so one rendering correctly says nothing
     * about the other, which is exactly the gap that let a broken export hide
     * behind a passing check.
     */
    private function renderRealReport(): int
    {
        $type = (string) ($this->option('report') ?: '');

        if ($type === '') {
            return self::SUCCESS;
        }

        if (! array_key_exists($type, SystemReportService::EXPORT_TYPES)) {
            $this->error('Unknown report. Choose one of: '.implode(', ', array_keys(SystemReportService::EXPORT_TYPES)));

            return self::FAILURE;
        }

        $reports = app(SystemReportService::class);
        $today = CarbonImmutable::today();
        $period = $reports->resolveExportPeriod('monthly', (int) $today->format('n'), (int) $today->format('Y'));
        $report = $reports->exportReport($type, $period, []);

        try {
            $pdf = ReportPdf::render('super-admin.reports-pdf', [
                'report' => $report,
                'reportType' => $type,
                'reportTitle' => $report['title'],
                'period' => $period,
                'appliedFilters' => [],
                'generatedBy' => 'pdf:check',
                'generatedAt' => CarbonImmutable::now(),
                'logoData' => CompanyBranding::logoDataUri(),
                'company' => CompanyBranding::letterhead(),
            ]);

            $output = $pdf->output();
        } catch (Throwable $exception) {
            $this->error('The report failed to render: '.$exception->getMessage());

            if ($previous = $exception->getPrevious()) {
                $this->line('  Cause: '.$previous->getMessage());
            }

            return self::FAILURE;
        }

        $this->describe($report['title'], $output);

        if ($keep = $this->option('keep')) {
            $path = rtrim($keep, '/\\').'/'.$type.'.pdf';

            if (! is_dir(dirname($path))) {
                @mkdir(dirname($path), 0775, true);
            }

            file_put_contents($path, $output);
            $this->line('  Written to '.$path);
        }

        return self::SUCCESS;
    }

    /**
     * What a rendered document is actually carrying, so a good render and a
     * bad one can be told apart by more than their file size.
     */
    private function describe(string $label, string $pdf): void
    {
        preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9+,\-]+)/', $pdf, $matches);

        $fonts = array_keys(array_count_values($matches[1]));

        $this->line('');
        $this->line('  '.$label);
        $this->line('    Size ............. '.number_format(strlen($pdf) / 1024, 1).' KB');
        $this->line('    Embedded fonts ... '.substr_count($pdf, '/FontFile2'));
        $this->line('    Font names ....... '.($fonts ? implode(', ', $fonts) : 'none'));
        $this->line('    Subsetted ........ '.(preg_match('/\/BaseFont\s*\/[A-Z]{6}\+/', $pdf) ? 'yes' : 'no'));
    }

    /**
     * Render a document that exercises the parts a report exercises: the
     * bundled font, a ruled table, and the letterhead image.
     */
    private function renderSmokeTest(): int
    {
        try {
            $pdf = ReportPdf::render('super-admin.pdf-check', [
                'generatedAt' => now(),
                'diagnostics' => ReportPdf::diagnostics(),
            ], 'a4', 'landscape');

            $output = $pdf->output();
            $bytes = strlen($output);

            if (! ReportPdf::embedsFontProgram($output)) {
                $this->error('The rendered document carries no embedded font; its text would be unreadable.');

                return self::FAILURE;
            }
        } catch (Throwable $exception) {
            $this->error('Rendering failed: '.$exception->getMessage());

            if ($previous = $exception->getPrevious()) {
                $this->line('  Cause: '.$previous->getMessage());
            }

            return self::FAILURE;
        }

        $this->describe('Environment check document', $output);

        if ($keep = $this->option('keep')) {
            $path = rtrim($keep, '/\\').'/pdf-check.pdf';
            file_put_contents($path, $output);
            $this->line('  Written to '.$path);
        }

        $this->line('');
        $this->info(sprintf('PDF export works here - rendered a %s KB document.', number_format($bytes / 1024, 1)));

        return self::SUCCESS;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function writable(bool $value): string
    {
        return $value ? '(writable)' : '(NOT WRITABLE)';
    }
}
