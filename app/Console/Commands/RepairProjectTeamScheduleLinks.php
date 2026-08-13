<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ScheduleTechnician;
use App\Services\ProjectTeam;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Book the team members the old assigned-team editor left unbooked.
 *
 * Every row this inserts is one ProjectTeam::attach() would write today: a
 * technician who is on a project's team gets a row against each of that
 * project's schedules, which is what makes them read as busy for those dates.
 *
 * What is missing is decided by ProjectTeam::missingScheduleLinks(), the same
 * method project-team:audit reports from, so the two commands cannot form
 * different opinions about what needs repairing.
 *
 * Safe to run twice: the second run finds nothing to do. Read
 * project-team:audit first - it names the double bookings these missing rows
 * are hiding, which repairing the data will make visible.
 */
class RepairProjectTeamScheduleLinks extends Command
{
    use ConfirmableTrait;

    protected $signature = 'project-team:repair
                            {--project= : Repair a single project by its project_id}
                            {--dry-run : List the rows that would be inserted and stop}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Insert the missing tbl_schedule_technicians rows for team members who hold none.';

    public function handle(ProjectTeam $projectTeam): int
    {
        $repairs = $this->pendingRepairs($projectTeam);

        if ($repairs->isEmpty()) {
            $this->components->info('Every team member already holds a row on every one of their project\'s schedules. Nothing to repair.');

            return self::SUCCESS;
        }

        $rows = $repairs->sum(fn (array $entry): int => count($entry['links']));

        $this->report($repairs);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->components->warn(sprintf(
                '%d row%s would be inserted. Nothing has been written - this was a dry run.',
                $rows,
                $rows === 1 ? '' : 's'
            ));

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed(sprintf('Inserting %d schedule row(s)', $rows))) {
            return self::FAILURE;
        }

        // One transaction for the lot: a repair that stopped half way would
        // leave the data in a third state that neither command describes.
        $inserted = DB::transaction(function () use ($repairs): int {
            $inserted = 0;

            foreach ($repairs as $entry) {
                foreach ($entry['links'] as $link) {
                    $row = ScheduleTechnician::firstOrCreate([
                        'schedule_id' => $link['schedule_id'],
                        'project_technician_id' => $link['project_technician_id'],
                    ]);

                    if ($row->wasRecentlyCreated) {
                        $inserted++;
                    }
                }
            }

            return $inserted;
        });

        $this->newLine();
        $this->components->info(sprintf(
            'Inserted %d row%s across %d project%s. Run project-team:audit to confirm.',
            $inserted,
            $inserted === 1 ? '' : 's',
            $repairs->count(),
            $repairs->count() === 1 ? '' : 's'
        ));

        return self::SUCCESS;
    }

    /**
     * Archived projects are excluded for the same reason the audit excludes
     * them: their teams were released on archive, so they hold nothing to
     * repair.
     *
     * @return Collection<int, array{project: Project, links: array<int, array{schedule_id: int, project_technician_id: int, technician_id: int}>}>
     */
    private function pendingRepairs(ProjectTeam $projectTeam): Collection
    {
        return Project::query()
            ->with([
                'schedules.scheduleTechnicians',
                'projectTechnicians.technician.account',
            ])
            ->where('is_archived', false)
            ->when($this->option('project'), function ($query): void {
                $query->where('project_id', (int) $this->option('project'));
            })
            ->orderBy('project_id')
            ->get()
            ->map(fn (Project $project): array => [
                'project' => $project,
                'links' => $projectTeam->missingScheduleLinks($project)->all(),
            ])
            ->reject(fn (array $entry): bool => $entry['links'] === [])
            ->values();
    }

    /**
     * @param  Collection<int, array{project: Project, links: array<int, array<string, int>>}>  $repairs
     */
    private function report(Collection $repairs): void
    {
        $rows = [];

        foreach ($repairs as $entry) {
            /** @var Project $project */
            $project = $entry['project'];

            foreach ($entry['links'] as $link) {
                $schedule = $project->schedules->firstWhere('schedule_id', $link['schedule_id']);

                $rows[] = [
                    $project->reference_no ?? $project->name,
                    $project->statusLabel(),
                    $project->projectTechnicians
                        ->firstWhere('technician_id', $link['technician_id'])?->technician?->name
                        ?? 'Unknown technician',
                    $schedule?->describe() ?? 'Unknown schedule',
                ];
            }
        }

        $this->table(['Project', 'Status', 'Technician', 'Booked'], $rows);
    }
}
