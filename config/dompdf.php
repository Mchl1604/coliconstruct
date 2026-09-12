<?php

/*
|--------------------------------------------------------------------------
| dompdf
|--------------------------------------------------------------------------
|
| Published rather than left to the package default, because the default is
| written for a developer's machine and a deployment is not one: it guesses
| the public path from the running script, writes fonts wherever storage
| happens to be, and takes its temporary directory from whatever the
| container decided sys_get_temp_dir() should mean.
|
| Everything here is derived from the application's own paths and may be
| overridden by environment, so nothing is hardcoded to a local checkout.
| The writable directories are created before a render - see
| App\Support\ReportPdf - so a fresh container does not have to be prepared
| by hand.
|
| One deliberate omission: remote loading stays off. Every image a document
| carries is embedded as a data URI, so the renderer never needs to reach the
| network, and a deployment behind a firewall renders the same document as a
| developer's laptop.
|
*/

$tempDir = env('DOMPDF_TEMP_DIR') ?: sys_get_temp_dir();

return [

    /*
     * Warnings are not exceptions. A missing glyph or an image dompdf would
     * rather not have been given must not cost the whole document - the
     * renderer draws what it can and App\Support\ReportPdf logs the rest.
     */
    'show_warnings' => false,

    /*
     * Where a relative URL in a document resolves to. The package otherwise
     * infers this from $_SERVER['SCRIPT_FILENAME'], which is the public
     * directory under a normal web request, the project root under `artisan`,
     * and something else again under a queue worker - so the same document
     * resolves differently depending on who asked for it.
     */
    'public_path' => public_path(),

    'convert_entities' => true,

    'options' => [

        /*
         * Font metrics are cached here, and the directory has to exist and be
         * writable before dompdf is asked to register a font. Under storage/
         * rather than in the package, so a read-only vendor directory - which
         * is what an optimised deployment builds - is not a problem.
         */
        'font_dir' => env('DOMPDF_FONT_DIR') ?: storage_path('app/dompdf/fonts'),
        'font_cache' => env('DOMPDF_FONT_DIR') ?: storage_path('app/dompdf/fonts'),

        /*
         * Scratch space for the images a document embeds. dompdf writes every
         * data URI out to a file here before it can measure it, so an
         * unwritable temporary directory is a failed export rather than a
         * slower one. DOMPDF_TEMP_DIR covers a container whose /tmp is
         * read-only; ReportPdf falls back to storage/ if this is unusable.
         */
        'temp_dir' => $tempDir,

        /*
         * The only directories dompdf may read local files from. public/ and
         * storage/ are named alongside the project root because a deployment
         * may symlink either of them somewhere else entirely, and a symlink
         * that resolves outside the root would otherwise be refused.
         */
        'chroot' => array_values(array_filter(array_unique([
            realpath(base_path()),
            realpath(public_path()),
            realpath(storage_path()),
        ]))),

        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
        ],

        'artifactPathValidation' => null,
        'log_output_file' => null,

        /*
         * ON, and this is not an optimisation - it is what makes the text
         * legible.
         *
         * dompdf embeds DejaVu as a CID font with Identity-H encoding, where
         * every code in the content stream is a GLYPH index and /CIDToGIDMap
         * is what translates them. With subsetting off, dompdf writes Unicode
         * code points into that stream instead and never builds a map that
         * bridges the two, so a reader draws glyph 80 for "P", glyph 1593 for
         * "s", and the page comes out as Arabic, Greek and schwas. The
         * /ToUnicode CMap it writes alongside is a flat <0000><FFFF><0000>
         * identity, so the text still *extracts* correctly - which is how a
         * document this broken passes every check that reads it rather than
         * looking at it.
         *
         * Subsetting builds a real reduced font with a correct map. It also
         * takes a report from 2.3 MB to about 60 KB, which is the part you
         * notice second.
         *
         * It writes the reduced font through a temporary file, so temp_dir
         * above has to be writable - ReportPdf guarantees that.
         */
        'enable_font_subsetting' => true,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'print',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',

        /*
         * DejaVu Sans ships inside dompdf itself, so this resolves without a
         * system font directory, a fontconfig cache, or anything else a
         * container image might not have. Every report asks for it by name;
         * naming it here as well means a document that forgets to still gets
         * a font that exists.
         */
        'default_font' => 'dejavu sans',

        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => false,

        /*
         * Off, and the protocol list above enforces it: nothing a document
         * references is fetched over the network.
         */
        'enable_remote' => false,
        'allowed_remote_hosts' => null,

        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],

];
