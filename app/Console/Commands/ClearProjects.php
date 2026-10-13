<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete every project, and everything recorded against one, to start the
 * demonstration data afresh.
 *
 *     php artisan projects:clear
 *     php artisan db:seed --class=DemoDataSeeder --force
 *
 * Projects only: accounts, settings, project types, system contents and the
 * backups list are left as they are. With the projects go their schedules,
 * teams, tasks, phases, documents, reports, photos and histories (the foreign
 * keys cascade), the schedule corrections, the activity entries and the
 * notifications about them, and the client records - which exist only for
 * the projects. The uploaded files the deleted rows named are pruned after.
 *
 * Asks first, because nothing here can be undone except by restoring a
 * backup - take one in Configuration -> System Settings -> Backups before
 * running this on a site that matters.
 */
class ClearProjects extends Command
{
    protected $signature = 'projects:clear
                            {--force : Delete without asking}';

    protected $description = 'Delete every project and everything recorded against one';

    /**
     * The record types, in the activity log and on notifications, that only
     * ever describe project work.
     *
     * @var list<string>
     */
    private const PROJECT_RECORD_TYPES = ['Project', 'ProjectPhase', 'Task', 'TechnicianReport', 'Schedule', 'Document'];

    public function handle(): int
    {
        $projects = DB::table('tbl_projects')->count();
        $clients = DB::table('tbl_clients')->count();

        if ($projects === 0 && $clients === 0) {
            $this->info('No projects to clear.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf(
            'Delete all %d project(s), %d client record(s) and everything recorded on them? This cannot be undone.',
            $projects,
            $clients
        ))) {
            $this->line('Nothing was deleted.');

            return self::FAILURE;
        }

        $counts = DB::transaction(function (): array {
            $ids = DB::table('tbl_projects')->pluck('project_id')->all();

            return [
                'corrections' => DB::table('tbl_schedule_corrections')->whereIn('project_id', $ids)->delete(),
                'activity' => DB::table('tbl_activity_logs')->whereIn('record_type', self::PROJECT_RECORD_TYPES)->delete(),
                'notifications' => DB::table('tbl_notifications')->whereIn('reference_type', self::PROJECT_RECORD_TYPES)->delete(),
                // Schedules, teams, tasks, phases, documents, reports, photos
                // and histories go with these - every one cascades.
                'projects' => DB::table('tbl_projects')->delete(),
                'clients' => DB::table('tbl_clients')->delete(),
            ];
        });

        $this->info(sprintf(
            'Deleted %d project(s), %d client record(s), %d activity entr(ies), %d notification(s) and %d schedule correction(s).',
            $counts['projects'],
            $counts['clients'],
            $counts['activity'],
            $counts['notifications'],
            $counts['corrections']
        ));

        // The files the deleted documents, photos and reports named.
        $this->call('uploads:prune-orphans', ['--force' => true]);

        return self::SUCCESS;
    }
}
