<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rename every project to its reference number and whose job it is - the
 * company on commercial work, the person otherwise:
 *
 *     php artisan projects:rename --dry-run
 *     php artisan projects:rename --force
 *
 * The name new projects are given - see Project::standardNameFor(). Projects
 * already named that way, and projects with no client, are left alone, so
 * running it twice changes nothing the second time. Written straight to the
 * table so updated_at is untouched: a rename is not an edit anybody made.
 */
class RenameProjects extends Command
{
    protected $signature = 'projects:rename
                            {--dry-run : List the changes without saving them}
                            {--force : Rename without asking}';

    protected $description = 'Rename every project to "reference number - client or company name"';

    public function handle(): int
    {
        /** @var array<int, array{project_id: int, from: string, to: string}> $changes */
        $changes = [];

        Project::query()
            ->with('client')
            ->whereNotNull('client_id')
            ->orderBy('project_id')
            ->each(function (Project $project) use (&$changes): void {
                if ($project->client === null) {
                    return;
                }

                $name = $project->standardNameFor($project->client);

                if ($project->name !== $name) {
                    $changes[] = ['project_id' => (int) $project->project_id, 'from' => (string) $project->name, 'to' => $name];
                }
            });

        if ($changes === []) {
            $this->info('Every project is already named that way.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Current name', 'New name'], array_map(
            fn (array $change): array => [$change['project_id'], $change['from'], $change['to']],
            $changes
        ));

        if ($this->option('dry-run')) {
            $this->line(sprintf('%d project(s) would be renamed. Nothing was saved.', count($changes)));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf('Rename %d project(s)?', count($changes)))) {
            $this->line('Nothing was renamed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($changes): void {
            foreach ($changes as $change) {
                DB::table('tbl_projects')
                    ->where('project_id', $change['project_id'])
                    ->update(['name' => $change['to']]);
            }
        });

        $this->info(sprintf('%d project(s) renamed.', count($changes)));

        return self::SUCCESS;
    }
}
