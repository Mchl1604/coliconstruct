<?php

namespace App\Console\Commands;

use App\Support\ReportPdf;
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
    protected $signature = 'pdf:check {--keep= : Write the rendered test document to this path}';

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
        $this->line('');

        if ($missing = ReportPdf::missingExtensions()) {
            $this->error('Missing PHP extension(s): '.implode(', ', $missing).'. Install them and run this again.');

            return self::FAILURE;
        }

        if (! $diagnostics['temp_dir_writable']) {
            $this->error('No writable temporary directory. Set DOMPDF_TEMP_DIR to one dompdf may write to.');

            return self::FAILURE;
        }

        return $this->renderSmokeTest();
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

            $bytes = strlen($pdf->output());
        } catch (Throwable $exception) {
            $this->error('Rendering failed: '.$exception->getMessage());

            if ($previous = $exception->getPrevious()) {
                $this->line('  Cause: '.$previous->getMessage());
            }

            return self::FAILURE;
        }

        if ($keep = $this->option('keep')) {
            file_put_contents($keep, $pdf->output());
            $this->line('  Written to '.$keep);
        }

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
