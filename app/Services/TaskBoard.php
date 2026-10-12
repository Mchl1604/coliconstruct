<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Task;
use App\Models\User;
use App\Support\ServerTable;
use App\Support\ServerTablePage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The task board both portals share: one card per project, each card's table
 * paged on the server.
 *
 * The board used to arrive whole - every task on every project, and the three
 * dialogs behind every task - which is what made the Tasks page the slowest
 * page in either portal. Now the page carries the cards and the figures above
 * them, and each card asks for one page of its own tasks when it comes into
 * view. The dialogs a row opens come back with the row.
 *
 * Who may see which tasks is the caller's decision, handed over as a query -
 * Task::scopeVisibleTo() for the technician portal, everything for the
 * office. This class only pages, filters and draws what it is given.
 */
class TaskBoard
{
    /**
     * The attention chips' keys, plus `all` for every task that cannot
     * proceed - what a card's table may be narrowed to.
     *
     * @var array<int, string>
     */
    public const GAP_FILTERS = ['all', ...TaskAssignmentGaps::ORDER];

    public function __construct(
        private readonly TaskScheduleRules $scheduleRules,
        private readonly TaskAssignmentRules $assignmentRules,
        private readonly TechnicianTaskLoad $taskLoad,
    ) {}

    /**
     * How many of the given tasks each project holds, for the count on each
     * card's header.
     *
     * @param  Builder<Task>  $tasks
     * @param  Collection<int, int>  $projectIds
     * @return array<int, int> project_id => count
     */
    public function taskCounts(Builder $tasks, Collection $projectIds): array
    {
        return (clone $tasks)
            ->whereIn('project_id', $projectIds->all())
            ->selectRaw('project_id, count(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * How many tasks on each project each attention chip would leave, so a
     * chip can drop the cards it empties without asking every card first.
     *
     * Counted by the scopes the chips' own figures are counted by - see
     * TaskAssignmentGaps::summarise() - so a card is kept exactly when the
     * chip's figure promises it something.
     *
     * @param  Builder<Task>  $tasks
     * @param  Collection<int, int>  $projectIds
     * @return array<int, array<string, int>> project_id => (gap => count)
     */
    public function gapCounts(Builder $tasks, Collection $projectIds): array
    {
        $counts = [];

        foreach (self::GAP_FILTERS as $gap) {
            $this->narrowToGap((clone $tasks)->whereIn('project_id', $projectIds->all()), $gap)
                ->selectRaw('project_id, count(*) as aggregate')
                ->groupBy('project_id')
                ->pluck('aggregate', 'project_id')
                ->each(function ($count, $projectId) use (&$counts, $gap): void {
                    $counts[(int) $projectId][$gap] = (int) $count;
                });
        }

        return $counts;
    }

    /**
     * One page of one project's board.
     *
     * `$tasks` is the reader's own reach on this project. `gap` narrows it to
     * one attention chip; `linked_task` names a task whose dialogs the page
     * already carries - see dialogs() - so they are not written twice.
     *
     * @param  Builder<Task>  $tasks
     * @param  array{canManage: bool, viewerTechnicianId: ?int, updateRoute: string, completeRoute: string, deleteRoute: string, updateMethod?: string, completeMethod?: string}  $options
     */
    public function respond(Request $request, Project $project, Builder $tasks, array $options): JsonResponse
    {
        $gap = (string) $request->query('gap', '');

        [$table, $page] = $this->paginate(
            $request,
            $tasks->when(in_array($gap, self::GAP_FILTERS, true), fn (Builder $query): Builder => $this->narrowToGap($query, $gap)),
            defaultOrder: [[4, 'asc']],
        );

        $rows = $page->items;
        $linked = (int) $request->query('linked_task', '0');

        return $table->respond(
            $page,
            fn (Task $task): string => view('components.task-board.row', [
                'task' => $task,
                'canManage' => $options['canManage'],
                'viewerTechnicianId' => $options['viewerTechnicianId'],
            ])->render(),
            $this->dialogs($project, $rows->reject(fn (Task $task): bool => (int) $task->task_id === $linked), $options),
        );
    }

    /**
     * One page of a table of tasks, searched and sorted in SQL, with what
     * every task row draws already loaded.
     *
     * Shared by every task table - the boards and both Project Details pages
     * - so the seven columns they all print sort and search alike: Task,
     * Phase, Assigned To, Start Date, Due Date, Status, and the buttons.
     *
     * @param  Builder<Task>  $tasks
     * @param  array<int, array{0: int, 1: string}>  $defaultOrder
     * @return array{0: ServerTable, 1: ServerTablePage<Task>}
     */
    public function paginate(Request $request, Builder $tasks, array $defaultOrder): array
    {
        $table = ServerTable::fromRequest($request);
        $query = $tasks->withHolderCoverage();

        $page = $table->paginate(
            $query,
            sortable: [
                0 => 'task_title',
                // By the phase's place in the project, not alphabetically by
                // its title.
                1 => fn (Builder $query, string $dir) => $query->orderBy(
                    ProjectPhase::query()
                        ->select('sequence')
                        ->whereColumn('tbl_project_phases.phase_id', 'tbl_tasks.phase_id')
                        ->limit(1),
                    $dir
                ),
                2 => fn (Builder $query, string $dir) => $query->orderBy(
                    User::query()
                        ->select('users.name')
                        ->join('tbl_technicians', 'tbl_technicians.account_id', '=', 'users.id')
                        ->whereColumn('tbl_technicians.technician_id', 'tbl_tasks.technician_id')
                        ->limit(1),
                    $dir
                ),
                3 => 'start_date',
                4 => 'due_date',
                5 => 'status',
            ],
            search: function (Builder $query, string $word): void {
                $like = '%'.$word.'%';

                $query->where('task_title', 'like', $like)
                    ->orWhere('task_description', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhereHas('phase', fn (Builder $phases) => $phases->where('title', 'like', $like))
                    ->orWhereHas('technician.account', fn (Builder $accounts) => $accounts
                        ->where('name', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like));
            },
            defaultOrder: $defaultOrder,
        );

        $page->items->load(['technician.account', 'images', 'completedBy', 'phase']);

        return [$table, $page];
    }

    /**
     * The dialogs behind the given tasks of one project: view and edit,
     * complete, delete.
     *
     * Also what the page renders up front for a task a notification links
     * to, so ?task= opens its dialog without waiting for its card to load.
     *
     * @param  Collection<int, Task>  $tasks
     * @param  array{canManage: bool, viewerTechnicianId: ?int, updateRoute: string, completeRoute: string, deleteRoute: string, updateMethod?: string, completeMethod?: string}  $options
     */
    public function dialogs(Project $project, Collection $tasks, array $options): string
    {
        if ($tasks->isEmpty()) {
            return '';
        }

        $project->loadMissing('rosterTechnicians.technician.account');

        // Today's team and anybody due to join it: work can be dated for a
        // technician's first week before that week arrives.
        $technicians = $project->rosterTechnicians
            ->pluck('technician')
            ->filter()
            ->unique('technician_id')
            ->values();

        return view('components.task-board.dialogs', [
            'tasks' => $tasks,
            'canManage' => $options['canManage'],
            'viewerTechnicianId' => $options['viewerTechnicianId'],
            'technicians' => $technicians,
            // Every span each of them holds here, so the edit dialogs can grey
            // out the days a chosen technician is not assigned for - see
            // TaskAssignmentRules::periodsFor().
            'periods' => $this->assignmentRules->periodsFor(
                (int) $project->project_id,
                $technicians->pluck('technician_id')
            ),
            'ranges' => collect($this->scheduleRules->ranges((int) $project->project_id)),
            // This project's finalized phases, for the Phase select.
            'phases' => ProjectPhase::query()
                ->where('project_id', $project->project_id)
                ->inOrder()
                ->get(),
            // What each technician holds HERE rather than across every job
            // they are on - see TechnicianTaskLoad.
            'activeTaskCounts' => $this->taskLoad->forProject((int) $project->project_id),
            'updateRoute' => $options['updateRoute'],
            'completeRoute' => $options['completeRoute'],
            'deleteRoute' => $options['deleteRoute'],
            'updateMethod' => $options['updateMethod'] ?? 'PUT',
            'completeMethod' => $options['completeMethod'] ?? 'PATCH',
        ])->render();
    }

    /**
     * Narrow tasks to one attention chip, by the scopes its figure is counted
     * by.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private function narrowToGap(Builder $query, string $gap): Builder
    {
        $query->needsAssignment();

        return $gap === 'all' ? $query : $query->withAssignmentGap($gap);
    }
}
