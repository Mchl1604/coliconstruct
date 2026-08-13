<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Schedule;
use App\Services\ProjectTeam;
use App\Services\ScheduleModeRules;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * A dry run over the damage left by the assigned-team editor.
 *
 * Until ProjectTeam existed, adding somebody to a project from its details
 * page wrote the team row but not the schedule rows. Availability reads only
 * the schedule rows, so those technicians have been reading as free for dates
 * they are booked for, and could be - and may already have been - booked onto
 * a second project over the same days.
 *
 * This command only reports. It writes nothing, and it is safe to run at any
 * time, as often as you like. The repair is a separate command, so the numbers
 * below can be read and agreed to before anything is inserted.
 *
 * The last section is the one to read carefully: repairing the data makes
 * every affected technician busy again, which will expose double bookings that
 * are already real but currently invisible. Nothing here creates them - it
 * only says which ones will stop being hidden.
 */
class AuditProjectTeamScheduleLinks extends Command
{
    protected $signature = 'project-team:audit
                            {--project= : Audit a single project by its project_id}';

    protected $description = 'Report team members missing their schedule rows, and the conflicts a repair would expose.';

    public function handle(ProjectTeam $projectTeam, ScheduleModeRules $scheduleRules): int
    {
        $projects = $this->projectsToAudit();

        if ($projects->isEmpty()) {
            $this->components->info('No projects to audit.');

            return self::SUCCESS;
        }

        /** @var Collection<int, array{project: Project, missing: Collection<int, array{schedule_id: int, project_technician_id: int, technician_id: int}>}> $affected */
        $affected = collect();

        foreach ($projects as $project) {
            $missing = $projectTeam->missingScheduleLinks($project);

            if ($missing->isNotEmpty()) {
                $affected->push(['project' => $project, 'missing' => $missing]);
            }
        }

        $this->components->info(sprintf(
            'Audited %d non-archived project%s.',
            $projects->count(),
            $projects->count() === 1 ? '' : 's'
        ));

        if ($affected->isEmpty()) {
            $this->components->info('Every team member holds a row on every one of their project\'s schedules. Nothing to repair.');

            return self::SUCCESS;
        }

        $this->reportProjects($affected);
        $this->reportTechnicians($affected);
        $this->reportConflicts($projects, $affected, $scheduleRules);

        $rows = $affected->sum(fn (array $entry): int => $entry['missing']->count());

        $this->newLine();
        $this->components->warn(sprintf(
            '%d row%s would be inserted into tbl_schedule_technicians across %d project%s. Nothing has been written.',
            $rows,
            $rows === 1 ? '' : 's',
            $affected->count(),
            $affected->count() === 1 ? '' : 's'
        ));

        return self::SUCCESS;
    }

    /**
     * Archived projects are left out: their teams were released on archive, so
     * there is nothing of theirs to repair, and availability ignores them.
     *
     * @return Collection<int, Project>
     */
    private function projectsToAudit(): Collection
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
            ->get();
    }

    /**
     * @param  Collection<int, array{project: Project, missing: Collection<int, array<string, int>>}>  $affected
     */
    private function reportProjects(Collection $affected): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Projects missing schedule rows</>', (string) $affected->count());

        $this->table(
            ['Project', 'Reference', 'Name', 'Status', 'Schedules', 'Team', 'Rows missing'],
            $affected->map(function (array $entry): array {
                /** @var Project $project */
                $project = $entry['project'];

                return [
                    $project->project_id,
                    $project->reference_no ?? '-',
                    mb_strimwidth((string) $project->name, 0, 32, '…'),
                    // The label, so an active project that is quietly overdue
                    // or on hold is not mistaken for a plain ongoing one.
                    $project->statusLabel(),
                    $project->schedules->count(),
                    $project->projectTechnicians->count(),
                    $entry['missing']->count(),
                ];
            })->all()
        );
    }

    /**
     * @param  Collection<int, array{project: Project, missing: Collection<int, array<string, int>>}>  $affected
     */
    private function reportTechnicians(Collection $affected): void
    {
        $rows = [];

        foreach ($affected as $entry) {
            /** @var Project $project */
            $project = $entry['project'];

            foreach ($entry['missing'] as $link) {
                $technicianId = $link['technician_id'];

                $rows[$technicianId] ??= [
                    'name' => $project->projectTechnicians
                        ->firstWhere('technician_id', $technicianId)?->technician?->name ?? 'Unknown technician',
                    'projects' => [],
                    'rows' => 0,
                ];

                $rows[$technicianId]['projects'][$project->project_id] = $project->reference_no ?? $project->name;
                $rows[$technicianId]['rows']++;
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Technicians affected</>', (string) count($rows));

        $this->table(
            ['Technician', 'Name', 'Projects', 'Rows missing'],
            collect($rows)
                ->map(fn (array $row, int $technicianId): array => [
                    $technicianId,
                    $row['name'],
                    implode(', ', $row['projects']),
                    $row['rows'],
                ])
                ->values()
                ->all()
        );
    }

    /**
     * Which double bookings the repair would make visible.
     *
     * Only projects availability actually consults can hide one - pending and
     * ongoing, never archived - so those are the only ones compared. A pair is
     * reported when the two bookings occupy the same minutes for the same
     * technician on two different projects, and at least one side of the pair
     * is a row this repair would insert. A clash already visible today is not
     * this repair's doing and is left out.
     *
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, array{project: Project, missing: Collection<int, array<string, int>>}>  $affected
     */
    private function reportConflicts(
        Collection $projects,
        Collection $affected,
        ScheduleModeRules $scheduleRules
    ): void {
        $bookings = $this->intendedBookings($projects, $affected);
        $conflicts = [];

        foreach ($bookings as $technicianId => $entries) {
            $entries = array_values($entries);

            for ($i = 0; $i < count($entries); $i++) {
                for ($j = $i + 1; $j < count($entries); $j++) {
                    [$first, $second] = [$entries[$i], $entries[$j]];

                    if ($first['project']->project_id === $second['project']->project_id) {
                        continue;
                    }

                    if (! $first['new'] && ! $second['new']) {
                        continue;
                    }

                    if (! $scheduleRules->overlaps(
                        $first['schedule']->occupiedInterval(),
                        $second['schedule']->occupiedInterval()
                    )) {
                        continue;
                    }

                    $conflicts[] = [
                        $technicianId,
                        $first['name'],
                        $first['project']->reference_no ?? $first['project']->name,
                        $first['schedule']->describe(),
                        $second['project']->reference_no ?? $second['project']->name,
                        $second['schedule']->describe(),
                    ];
                }
            }
        }

        $this->newLine();

        if ($conflicts === []) {
            $this->components->twoColumnDetail('<fg=green>Conflicts the repair would expose</>', '0');
            $this->components->info('No technician would end up double booked. The repair is purely corrective.');

            return;
        }

        $this->components->twoColumnDetail('<fg=red>Conflicts the repair would expose</>', (string) count($conflicts));
        $this->components->warn(
            'These technicians are already double booked - the missing rows are what hides it. '
                .'Repairing the data does not create these clashes, it reveals them, and they will then '
                .'have to be resolved by hand on the schedules page.'
        );

        $this->table(
            ['Technician', 'Name', 'Project A', 'Booked', 'Project B', 'Booked'],
            $conflicts
        );
    }

    /**
     * Every booking each technician would hold once the repair had run: the
     * rows that exist today, plus the rows it would insert.
     *
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, array{project: Project, missing: Collection<int, array<string, int>>}>  $affected
     * @return array<int, array<int, array{project: Project, schedule: Schedule, name: string, new: bool}>>
     */
    private function intendedBookings(Collection $projects, Collection $affected): array
    {
        $bookings = [];

        $active = $projects->filter(
            fn (Project $project): bool => in_array($project->status, Project::ACTIVE_PROJECT_STATUSES, true)
        );

        $missingByProject = $affected->mapWithKeys(fn (array $entry): array => [
            (int) $entry['project']->project_id => $entry['missing'],
        ]);

        foreach ($active as $project) {
            $technicianFor = $project->projectTechnicians
                ->mapWithKeys(fn ($assignment): array => [
                    (int) $assignment->project_technician_id => $assignment,
                ]);

            foreach ($project->schedules as $schedule) {
                foreach ($schedule->scheduleTechnicians as $link) {
                    $assignment = $technicianFor->get((int) $link->project_technician_id);

                    if (! $assignment) {
                        continue;
                    }

                    $bookings[(int) $assignment->technician_id][] = [
                        'project' => $project,
                        'schedule' => $schedule,
                        'name' => $assignment->technician?->name ?? 'Unknown technician',
                        'new' => false,
                    ];
                }
            }

            foreach ($missingByProject->get((int) $project->project_id, collect()) as $link) {
                $schedule = $project->schedules->firstWhere('schedule_id', $link['schedule_id']);
                $assignment = $technicianFor->get($link['project_technician_id']);

                if (! $schedule || ! $assignment) {
                    continue;
                }

                $bookings[$link['technician_id']][] = [
                    'project' => $project,
                    'schedule' => $schedule,
                    'name' => $assignment->technician?->name ?? 'Unknown technician',
                    'new' => true,
                ];
            }
        }

        return $bookings;
    }
}
