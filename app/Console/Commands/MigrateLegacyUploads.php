<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\ProjectCompletionPhoto;
use App\Models\SystemContent;
use App\Models\TaskImage;
use App\Models\TechnicianReportImage;
use App\Models\User;
use App\Support\UploadStore;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Move uploads made before they lived on the uploads disk.
 *
 * Files used to be written in two places, neither of which survives a deploy
 * and neither of which asks who is reading:
 *
 *   - the public disk, at storage/app/public, reached through the
 *     public/storage symlink - profile pictures, task and report images,
 *     website imagery;
 *   - straight into public/uploads - project documents and completion
 *     photographs.
 *
 * Both are now one private disk. The rows still name the old locations, so
 * every page that shows one of these files shows a broken image until this has
 * run. It moves the file and rewrites the row, and does the two in that order
 * so that a failure leaves a row pointing at a file that is still there.
 *
 * Only files a row actually references are moved. public/uploads accumulated
 * thousands of 0-byte fixtures from a period when the test suite wrote into
 * it, and none of those are worth carrying over.
 *
 * Safe to run twice: a row already naming its new home is left alone.
 */
class MigrateLegacyUploads extends Command
{
    protected $signature = 'uploads:migrate-legacy
                            {--dry-run : Report what would move and change nothing}';

    protected $description = 'Move pre-existing uploads onto the uploads disk and repoint their rows';

    private bool $dryRun = false;

    private int $moved = 0;

    private int $missing = 0;

    private int $skipped = 0;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('Dry run: nothing will be moved and no row will be changed.');
            $this->line('');
        }

        // Group one: the public disk, where the path in the row is already the
        // path within the disk. Only the two renamed folders change.
        $this->fromPublicDisk(
            User::query()->whereNotNull('profile_photo_path')->get(),
            'profile_photo_path',
            'profile-photos',
            'profile_photos',
            'Profile pictures'
        );

        $this->fromPublicDisk(
            TaskImage::query()->get(),
            'image_path',
            'task-completions',
            'task_images',
            'Task completion images'
        );

        $this->fromPublicDisk(
            TechnicianReportImage::query()->get(),
            'image_path',
            'technician-reports',
            'report_images',
            'Technician report images'
        );

        $this->fromPublicDisk(
            SystemContent::query()->where('content_type', 'image')->get(),
            'content_value',
            'system-contents',
            'system_contents',
            'Website imagery'
        );

        // Group two: written straight into public/, where the row holds a path
        // relative to public/ rather than to any disk.
        $this->fromPublicDirectory(
            Document::query()->get(),
            'document_path',
            'documents',
            'Project documents'
        );

        $this->fromPublicDirectory(
            ProjectCompletionPhoto::query()->get(),
            'photo_path',
            'completion_photos',
            'Completion photographs'
        );

        $this->line('');
        $this->info(sprintf(
            '%s %d file(s). %d already in place, %d with nothing on disk to move.',
            $this->dryRun ? 'Would move' : 'Moved',
            $this->moved,
            $this->skipped,
            $this->missing
        ));

        if ($this->missing > 0) {
            $this->line('');
            $this->warn(
                'A row with nothing to move is one whose file was already gone - deleted by hand, '
                .'or wiped by a deploy before uploads became durable. Those files cannot be recovered; '
                .'the pages that show them render a broken image and the file needs uploading again.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * Files that were on the `public` disk, whose row already holds a
     * disk-relative path.
     *
     * @param  \Illuminate\Support\Collection<int, Model>  $rows
     */
    private function fromPublicDisk(
        $rows,
        string $column,
        string $oldFolder,
        string $kind,
        string $label
    ): void {
        $newFolder = UploadStore::folder($kind);
        $source = Storage::disk('public');
        $count = 0;

        foreach ($rows as $row) {
            $stored = (string) $row->{$column};

            if ($stored === '' || ! str_starts_with($stored, $oldFolder.'/')) {
                continue;
            }

            $name = basename($stored);
            $target = $newFolder.'/'.$name;

            if (UploadStore::exists($target)) {
                // Already carried over by an earlier run; only the row is
                // behind, and it may not even be.
                $this->repoint($row, $column, $target);
                $this->skipped++;

                continue;
            }

            if (! $source->exists($stored)) {
                $this->missing++;
                $this->line(sprintf('  <fg=yellow>gone</> %s', $stored));

                continue;
            }

            if (! $this->dryRun) {
                UploadStore::disk()->put($target, $source->get($stored));
                $this->repoint($row, $column, $target);
                $source->delete($stored);
            }

            $this->moved++;
            $count++;
        }

        $this->reportGroup($label, $count);
    }

    /**
     * Files that were moved straight into public/, whose row holds a path
     * relative to public/ rather than to a disk.
     *
     * @param  \Illuminate\Support\Collection<int, Model>  $rows
     */
    private function fromPublicDirectory(
        $rows,
        string $column,
        string $kind,
        string $label
    ): void {
        $newFolder = UploadStore::folder($kind);
        $count = 0;

        foreach ($rows as $row) {
            $stored = (string) $row->{$column};

            if ($stored === '' || ! str_starts_with($stored, 'uploads/')) {
                continue;
            }

            $name = basename($stored);
            $target = $newFolder.'/'.$name;

            if (UploadStore::exists($target)) {
                $this->repoint($row, $column, $target);
                $this->skipped++;

                continue;
            }

            $absolute = public_path($stored);

            if (! File::exists($absolute)) {
                $this->missing++;
                $this->line(sprintf('  <fg=yellow>gone</> %s', $stored));

                continue;
            }

            if (! $this->dryRun) {
                // Read as a stream rather than into memory: a contract scan
                // can be several megabytes and there may be hundreds.
                $handle = fopen($absolute, 'rb');
                UploadStore::disk()->writeStream($target, $handle);

                if (is_resource($handle)) {
                    fclose($handle);
                }

                $this->repoint($row, $column, $target);
                File::delete($absolute);
            }

            $this->moved++;
            $count++;
        }

        $this->reportGroup($label, $count);
    }

    private function repoint(Model $row, string $column, string $target): void
    {
        if ($this->dryRun || (string) $row->{$column} === $target) {
            return;
        }

        $row->forceFill([$column => $target])->save();
    }

    private function reportGroup(string $label, int $count): void
    {
        $this->line(sprintf(
            '  %-28s %s',
            $label,
            $count > 0 ? '<fg=green>'.$count.'</>' : '<fg=gray>none</>'
        ));
    }
}
