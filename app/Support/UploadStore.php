<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The one way a file a person uploaded reaches disk, and the one way it comes
 * back off it.
 *
 * Two things used to be true of uploads here and are no longer:
 *
 *   - They were written into the container's own filesystem, which every
 *     deploy empties. Rows kept pointing at contracts and photographs that had
 *     stopped existing.
 *   - They were served by the web server as static files, so a URL was a
 *     permanent unauthenticated key to whatever it named. A client's contract
 *     was one forwarded link away from anybody.
 *
 * Both follow from where the bytes go, so both are answered in one place. The
 * disk is private and configurable - a local directory outside public/ for
 * development and the test suite, object storage in a deployment - and nothing
 * reads a file except a route that has checked who is asking.
 */
class UploadStore
{
    public static function disk(): Filesystem
    {
        return Storage::disk(config('uploads.disk'));
    }

    /**
     * Put a file in the folder for its kind, and return the path to store.
     *
     * Named by uuid rather than by what it arrived as: two people uploading
     * "quotation.pdf" must not land on one file, and a name a person chose is
     * not a name that belongs in a path.
     */
    public static function put(UploadedFile $file, string $kind): string
    {
        $folder = self::folder($kind);
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $name = Str::uuid()->toString().'.'.$extension;

        self::disk()->putFileAs($folder, $file, $name);

        return $folder.'/'.$name;
    }

    /**
     * Remove a file, tolerating one that has already gone.
     *
     * A path that is empty, or names something no longer there, is not an
     * error worth propagating: the caller's intent - that the file should not
     * exist - is satisfied either way.
     */
    public static function remove(?string $path): void
    {
        if (! filled($path)) {
            return;
        }

        self::disk()->delete($path);
    }

    public static function exists(?string $path): bool
    {
        return filled($path) && self::disk()->exists($path);
    }

    /**
     * The configured folder for a kind of upload.
     */
    public static function folder(string $kind): string
    {
        $folder = config('uploads.folders.'.$kind);

        if (! is_string($folder) || $folder === '') {
            throw new \InvalidArgumentException(sprintf('No upload folder is configured for "%s".', $kind));
        }

        return $folder;
    }
}
