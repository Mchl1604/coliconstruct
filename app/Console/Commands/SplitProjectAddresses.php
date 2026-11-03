<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ProjectAddressSplitter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give projects saved before the address was split their parts:
 *
 *     php artisan projects:split-addresses --dry-run
 *     php artisan projects:split-addresses --force
 *
 * See ProjectAddressSplitter for what it can read. Projects that already have
 * a barangay are left alone, so running it twice changes nothing the second
 * time. Written straight to the table so updated_at is untouched: this is not
 * an edit anybody made.
 */
class SplitProjectAddresses extends Command
{
    protected $signature = 'projects:split-addresses
                            {--dry-run : List the results without saving them}
                            {--force : Save without asking}';

    protected $description = 'Split old free-text project addresses into province, municipality, barangay and street';

    public function handle(ProjectAddressSplitter $splitter): int
    {
        /** @var array<int, array<string, string|null>> $changes */
        $changes = [];

        Project::query()
            ->whereNull('address_barangay')
            ->whereNotNull('address')
            ->orderBy('project_id')
            ->each(function (Project $project) use (&$changes, $splitter): void {
                $changes[(int) $project->project_id] = $splitter->split((string) $project->address);
            });

        if ($changes === []) {
            $this->info('Every project address is already split.');

            return self::SUCCESS;
        }

        $complete = array_filter($changes, fn (array $parts): bool => $parts['address_barangay'] !== null);
        $unfinished = array_diff_key($changes, $complete);

        $this->info(sprintf('%d of %d addresses can be fully split.', count($complete), count($changes)));

        foreach ($unfinished as $projectId => $parts) {
            $this->line(sprintf('  Needs the edit form: project %d - %s', $projectId, $parts['address']));
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Save these addresses?')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($changes): void {
            foreach ($changes as $projectId => $parts) {
                DB::table('tbl_projects')->where('project_id', $projectId)->update($parts);
            }
        });

        $this->info('Saved.');

        return self::SUCCESS;
    }
}
