<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The one way this application builds a PDF.
 *
 * Every export used to call the dompdf facade directly and take whatever the
 * environment happened to give it. That is fine on a developer's machine,
 * where PHP is a full build and every directory is writable, and it is not
 * fine on a deployed container - which is why the exports worked locally and
 * returned "Unable to build the PDF." once deployed.
 *
 * Three things were environment-dependent, and all three are settled here
 * rather than hoped for:
 *
 *   1. THE IMAGE FORMAT. dompdf 3.x refuses *any* PNG without ext-gd -
 *      Cpdf::addPngFromFile() throws before it so much as looks at the file,
 *      and nothing in the render catches it. The letterhead was a PNG, so a
 *      PHP build without GD - which nothing else in this application needs -
 *      turned every export into a 500 while the on-screen preview, drawn by
 *      the browser, carried on working. The letterhead is a JPEG now (see
 *      CompanyBranding::logoDataUri) and JPEG is dompdf's one GD-free image
 *      path.
 *
 *   2. THE WRITABLE DIRECTORIES. dompdf writes every embedded image out to a
 *      temporary file before it can measure it, and caches font metrics in a
 *      directory it expects to already exist. Both are prepared below, and an
 *      unwritable system temp directory falls back to storage rather than
 *      failing the export.
 *
 *   3. THE MEMORY CEILING. Laying out a long landscape table costs far more
 *      than a typical php.ini allows a web request. The limit is raised for
 *      the render alone and put back afterwards.
 *
 * Anything that still goes wrong is logged with the cause intact. The caller
 * shows a short message; the log says what actually happened.
 */
class ReportPdf
{
    /**
     * What a render is allowed, in megabytes, if php.ini allows less.
     *
     * Chosen from measurement rather than taste: a full year of projects in
     * landscape peaks a little over 60 MB, and the activity log export is
     * capped at its own row limit. An existing higher limit is left alone.
     */
    private const MEMORY_LIMIT_MB = 512;

    /**
     * Seconds a render may take if the request is currently allowed less.
     * Zero - no limit, as under `artisan` - is left alone.
     */
    private const TIME_LIMIT = 120;

    /**
     * Build a document, with the environment prepared first.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException when the document cannot be rendered
     */
    public static function render(
        string $view,
        array $data,
        string $paper = 'a4',
        string $orientation = 'landscape'
    ): PdfWrapper {
        self::prepareStorage();

        $memoryLimit = ini_get('memory_limit');

        self::raiseMemoryLimit();
        self::raiseTimeLimit();

        try {
            $pdf = Pdf::loadView($view, $data)->setPaper($paper, $orientation);

            // Rendered here rather than left to the response, so a failure is
            // caught while there is still a log entry's worth of context and
            // not halfway through streaming a download to the browser.
            $pdf->output();

            return $pdf;
        } catch (Throwable $exception) {
            Log::error('PDF export failed.', [
                'view' => $view,
                'exception' => $exception,
            ] + self::diagnostics());

            throw new RuntimeException('The PDF could not be built.', 0, $exception);
        } finally {
            if (is_string($memoryLimit) && $memoryLimit !== '') {
                ini_set('memory_limit', $memoryLimit);
            }
        }
    }

    /**
     * Create the directories dompdf writes to, and point it somewhere else if
     * the configured temporary directory cannot be written to.
     *
     * Called before every render rather than once at deploy time: a container
     * is rebuilt from an image on each release, and a directory that has to be
     * created by hand is a directory somebody will forget.
     */
    public static function prepareStorage(): void
    {
        $fontDir = (string) config('dompdf.options.font_dir');

        if ($fontDir !== '' && ! is_dir($fontDir)) {
            @mkdir($fontDir, 0775, true);
        }

        $tempDir = (string) config('dompdf.options.temp_dir');

        if (self::isUsableDirectory($tempDir)) {
            return;
        }

        // A read-only /tmp is a real container configuration. storage/ is
        // already writable - the framework could not boot otherwise - so the
        // export uses it rather than failing.
        $fallback = storage_path('app/dompdf/tmp');

        if (! is_dir($fallback)) {
            @mkdir($fallback, 0775, true);
        }

        config(['dompdf.options.temp_dir' => $fallback]);
    }

    /**
     * What the environment can and cannot do, for `php artisan pdf:check` and
     * for the log entry written when a render fails.
     *
     * @return array<string, mixed>
     */
    public static function diagnostics(): array
    {
        $fontDir = (string) config('dompdf.options.font_dir');
        $tempDir = (string) config('dompdf.options.temp_dir');

        return [
            'php_version' => PHP_VERSION,
            'ext_dom' => extension_loaded('dom'),
            'ext_mbstring' => extension_loaded('mbstring'),
            'ext_gd' => extension_loaded('gd'),
            'memory_limit' => ini_get('memory_limit'),
            'font_dir' => $fontDir,
            'font_dir_writable' => self::isUsableDirectory($fontDir),
            'temp_dir' => $tempDir,
            'temp_dir_writable' => self::isUsableDirectory($tempDir),
            'public_path' => (string) config('dompdf.public_path'),
            'letterhead' => CompanyBranding::letterheadLogoPath(),
        ];
    }

    /**
     * The extensions dompdf cannot render a document without at all, as
     * opposed to GD - which this application deliberately no longer needs.
     *
     * @return array<int, string>
     */
    public static function missingExtensions(): array
    {
        return array_values(array_filter(
            ['dom', 'mbstring'],
            fn (string $extension): bool => ! extension_loaded($extension)
        ));
    }

    private static function isUsableDirectory(string $path): bool
    {
        return $path !== '' && is_dir($path) && is_writable($path);
    }

    private static function raiseMemoryLimit(): void
    {
        $current = self::bytes((string) ini_get('memory_limit'));

        // -1 is "no limit", which is already more than we would ask for.
        if ($current < 0) {
            return;
        }

        if ($current < self::MEMORY_LIMIT_MB * 1024 * 1024) {
            @ini_set('memory_limit', self::MEMORY_LIMIT_MB.'M');
        }
    }

    private static function raiseTimeLimit(): void
    {
        $current = (int) ini_get('max_execution_time');

        if ($current > 0 && $current < self::TIME_LIMIT) {
            @set_time_limit(self::TIME_LIMIT);
        }
    }

    /**
     * A php.ini size - "512M", "1G", "-1" - as a byte count.
     */
    private static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
