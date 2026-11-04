<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\TechnicianReport;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A project's progress from its start to today, gathered from what is already
 * recorded: phases, tasks and their updates, materials, incidents and the daily
 * reports filed.
 *
 * Worked out each time it is opened and never saved, so unlike a daily report
 * (see ProgressReportBuilder) it always says where the project stands now.
 */
class ProjectProgressOverview
{
    public function __construct(
        private readonly ProjectPhaseProgress $phases,
        private readonly ProjectMaterialLedger $materials,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Project $project): array
    {
        $project->loadMissing(['client', 'schedules']);

        $today = BusinessTime::today();
        $start = $project->schedules->first()?->startsOn();
        $summary = $this->phases->summary($project);

        $tasks = Task::query()
            ->with(['technician.account', 'updates.images', 'updates.technician.account'])
            ->where('project_id', $project->project_id)
            ->orderByRaw('start_date is null')
            ->orderBy('start_date')
            ->orderBy('task_id')
            ->get()
            ->filter(fn (Task $task): bool => $this->hasBegunOrIsLate($task, $today))
            ->values();

        $tasksByPhase = $tasks->groupBy('phase_id');
        $phaseIds = $summary['phases']->map(fn (array $row): int => $row['phase']->phase_id);

        return [
            'project' => [
                'reference_no' => $project->reference_no,
                'name' => $project->name,
                'client' => $project->client?->company_name ?: $project->client?->fullname,
                'address' => $project->address,
                'status' => $project->statusLabel(),
            ],
            'started_on' => $start?->format(BusinessTime::DATE),
            'generated_on' => $today->format(BusinessTime::DATE),
            'generated_at' => BusinessTime::now()->format(BusinessTime::DATE_TIME),
            'percent' => $summary['percent'],
            'phase_label' => $summary['label'],
            'phases' => $summary['phases']
                ->map(fn (array $row): array => [
                    'number' => $row['phase']->sequence,
                    'title' => $row['phase']->title,
                    'status' => $row['label'],
                    'is_future' => $row['status'] === ProjectPhase::STATUS_NOT_STARTED,
                    'tone' => match ($row['status']) {
                        ProjectPhase::STATUS_COMPLETED => 'is-green',
                        ProjectPhase::STATUS_IN_PROGRESS => 'is-blue',
                        default => 'is-gray',
                    },
                    'task_percent' => $row['taskPercent'],
                    'tasks_label' => sprintf('%d/%d tasks', $row['completedTasks'], $row['totalTasks']),
                    'tasks' => $this->taskRows($tasksByPhase->get($row['phase']->phase_id, collect()), $today),
                ])
                ->values()
                ->all(),
            'other_tasks' => $this->taskRows($tasks->whereNotIn('phase_id', $phaseIds->all()), $today),
            'materials' => $this->materials->summary($project)
                ->map(function (array $row): array {
                    $unit = $row['material']->unit;
                    $amount = fn (float $quantity): string => $unit->format($quantity).' '.$unit->value;

                    return [
                        'material' => $row['material']->displayName(),
                        'needed' => $amount($row['needed']),
                        'added' => $amount($row['added']),
                        'used' => $amount($row['used']),
                        'returned' => $amount($row['returned']),
                        'on_site' => $amount($row['on_site']),
                    ];
                })
                ->all(),
            'incidents' => $this->reportRows($project, 'incident'),
            'daily_reports' => $this->reportRows($project, 'progress'),
            'photo_ids' => $tasks->flatMap(fn (Task $task) => $task->updates->flatMap(fn (TaskUpdate $update) => $update->images->modelKeys()))->all(),
        ];
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array<int, array<string, mixed>>
     */
    private function taskRows(Collection $tasks, CarbonImmutable $today): array
    {
        return $tasks
            ->map(fn (Task $task): array => [
                'task' => $task->task_title,
                'technician' => $task->technician?->name,
                'status' => $task->statusLabel(),
                'tone' => $this->isOverdue($task, $today) ? 'is-red' : match ($task->status) {
                    'completed' => 'is-green',
                    'ongoing' => 'is-blue',
                    'pending' => 'is-amber',
                    'unassigned' => 'is-orange',
                    default => 'is-gray',
                },
                'overdue' => $this->isOverdue($task, $today),
                'due' => $task->due_date ? CarbonImmutable::parse($task->due_date)->format(BusinessTime::DATE) : null,
                // Oldest first: the report reads from the start forward.
                'updates' => $task->updates
                    ->reverse()
                    ->map(fn (TaskUpdate $update): array => [
                        'date' => $update->update_date->format(BusinessTime::DATE),
                        'technician' => $update->technician?->name,
                        'notes' => $update->notes,
                        'images' => $update->images->modelKeys(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{date: string, title: string, url: string|null}>
     */
    private function reportRows(Project $project, string $type): array
    {
        return TechnicianReport::query()
            ->active()
            ->where('project_id', $project->project_id)
            ->where('report_type', $type)
            ->orderBy('report_date')
            ->orderBy('id')
            ->get()
            ->map(fn (TechnicianReport $report): array => [
                'date' => $report->report_date->format(BusinessTime::DATE),
                'title' => $report->report_title,
                'url' => $report->documentUrl(),
            ])
            ->all();
    }

    /**
     * What the report lists: tasks completed, in progress or overdue. Work not
     * yet begun is left out; the phases still to come stand for it.
     */
    private function hasBegunOrIsLate(Task $task, CarbonImmutable $today): bool
    {
        if ($task->status === 'completed' || $this->isOverdue($task, $today)) {
            return true;
        }

        return in_array($task->status, Task::ACTIVE_STATUSES, true)
            && $task->start_date !== null
            && $this->dateOf($task->start_date) <= $today->toDateString();
    }

    private function isOverdue(Task $task, CarbonImmutable $today): bool
    {
        return $task->isOpen()
            && $task->due_date !== null
            && $this->dateOf($task->due_date) < $today->toDateString();
    }

    private function dateOf(mixed $date): string
    {
        return CarbonImmutable::parse($date)->toDateString();
    }
}
