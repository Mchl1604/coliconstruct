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
use Illuminate\Support\Collection;

/**
 * Delete files on the uploads disk that no row points at.
 *
 * An upload is only ever reachable through the row that names it - see
 * UploadedFileController, where every route resolves a model first. A file
 * with no row is therefore unreachable by any means the application offers:
 * it is taking space and appearing in listings and nothing more.
 *
 * They come from two places. A delete that removed the row but failed to
 * remove the file leaves one; so does a test run written against the real disk
 * rather than a faked one. Neither is worth keeping.
 *
 * Deliberately conservative. The referenced set is built from every column
 * that holds a path, so a kind of upload added later without being added here
 * would have its files treated as orphans - which is why this asks before
 * deleting, prints what it found first, and has a dry run.
 */
class PruneOrphanUploads extends Command
{
    protected $signature = 'uploads:prune-orphans
                            {--dry-run : List what would be deleted and remove nothing}
                            {--force : Delete without asking}';

    protected $description = 'Delete uploaded files that no database row references';

    public function handle(): int
    {
        $referenced = $this->referencedPaths();
        $onDisk = collect(UploadStore::disk()->allFiles());
        $orphans = $onDisk->diff($referenced)->values();

        $this->line('');
        $this->line(sprintf(
            '  %d file(s) on the uploads disk, %d referenced by a row, <fg=yellow>%d orphaned</>.',
            $onDisk->count(),
            $referenced->count(),
            $orphans->count()
        ));

        if ($orphans->isEmpty()) {
            $this->line('');
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        $this->report($orphans);

        if ($this->option('dry-run')) {
            $this->line('');
            $this->warn('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete these permanently?', false)) {
            $this->line('');
            $this->line('Left alone.');

            return self::SUCCESS;
        }

        foreach ($orphans as $path) {
            UploadStore::remove($path);
        }

        $this->line('');
        $this->info(sprintf('Deleted %d orphaned file(s).', $orphans->count()));

        return self::SUCCESS;
    }

    /**
     * Every path any row holds. Anything on the disk and not in here is
     * unreachable.
     *
     * @return Collection<int, string>
     */
    private function referencedPaths(): Collection
    {
        return collect()
            ->merge(User::query()->whereNotNull('profile_photo_path')->pluck('profile_photo_path'))
            ->merge(Document::query()->pluck('document_path'))
            ->merge(ProjectCompletionPhoto::query()->pluck('photo_path'))
            ->merge(TaskImage::query()->pluck('image_path'))
            ->merge(TechnicianReportImage::query()->pluck('image_path'))
            ->merge(SystemContent::query()->where('content_type', 'image')->pluck('content_value'))
            ->filter()
            ->map(fn ($path): string => (string) $path)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, string>  $orphans
     */
    private function report(Collection $orphans): void
    {
        $disk = UploadStore::disk();

        $this->line('');

        foreach ($orphans->groupBy(fn (string $path): string => explode('/', $path)[0]) as $folder => $files) {
            $bytes = collect($files)->sum(fn (string $path): int => (int) $disk->size($path));

            $this->line(sprintf(
                '  %-22s %4d file(s)  %s',
                $folder,
                count($files),
                $this->humanBytes($bytes)
            ));
        }
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return sprintf('%.1f %s', $bytes, $unit);
            }

            $bytes /= 1024;
        }

        return $bytes.' B';
    }
}
