<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * How much open work each technician is holding, counted per project.
 *
 * This is the "N Active Tasks" figure under a name in the Assign To picker,
 * and it is a per-project figure. The picker only ever lists one project's
 * team, sits inside that project's dialog, and is answering one question:
 * how much of THIS job is already on this person's plate? A technician with
 * nothing to do here reads as free here, whatever they are doing elsewhere.
 *
 * It was previously counted across every project at once, in six separate
 * copies of the same query - so a technician with one task on another job
 * showed "1 Active Task" on a project they had none on. Extracted for the same
 * reason TaskScheduleRules and ProjectTeam were: one question, asked in one
 * place, so six screens cannot answer it six ways.
 *
 * Unassigned tasks are deliberately not counted. They are outstanding work on
 * the project, but they are nobody's load until somebody owns them.
 */
class TechnicianTaskLoad
{
    /**
     * technician_id => open task count, for one project.
     *
     * Technicians with nothing open on the project are absent rather than
     * zero, so callers read it with a `?? 0` - which is what they all did
     * already.
     *
     * @return Collection<int, int>
     */
    public function forProject(int $projectId): Collection
    {
        return Task::query()
            ->where('project_id', $projectId)
            ->whereNotNull('technician_id')
            ->whereIn('status', Task::ACTIVE_STATUSES)
            ->selectRaw('technician_id, count(*) as active_count')
            ->groupBy('technician_id')
            ->pluck('active_count', 'technician_id')
            ->map(fn ($count): int => (int) $count);
    }

    /**
     * project_id => (technician_id => open task count), for the pages that
     * show several projects' boards at once.
     *
     * One query covers the lot, however many projects are on the page: the
     * Tasks page lists every project a person can reach, and a query each
     * would be a query per project.
     *
     * @param  iterable<int, int>  $projectIds
     * @return Collection<int, Collection<int, int>>
     */
    public function forProjects(iterable $projectIds): Collection
    {
        $projectIds = collect($projectIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($projectIds->isEmpty()) {
            return collect();
        }

        return Task::query()
            ->whereIn('project_id', $projectIds->all())
            ->whereNotNull('technician_id')
            ->whereIn('status', Task::ACTIVE_STATUSES)
            ->selectRaw('project_id, technician_id, count(*) as active_count')
            ->groupBy('project_id', 'technician_id')
            ->get()
            ->groupBy('project_id')
            ->map(fn (Collection $rows): Collection => $rows->pluck('active_count', 'technician_id')
                ->map(fn ($count): int => (int) $count));
    }

    /**
     * The counts for one project out of a forProjects() map.
     *
     * A project with nothing open is missing from the map rather than present
     * and empty, and every caller wants an empty collection instead of null.
     *
     * @param  Collection<int, Collection<int, int>>  $byProject
     * @return Collection<int, int>
     */
    public function sliceFor(Collection $byProject, int $projectId): Collection
    {
        return $byProject->get($projectId, collect());
    }
}
