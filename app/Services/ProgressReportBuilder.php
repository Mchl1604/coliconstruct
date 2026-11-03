<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectMaterialEntry;
use App\Models\ProjectTechnician;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\TechnicianReport;
use App\Models\TechnicianReportImage;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Support\BusinessTime;
use App\Support\UploadStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A lead's daily progress report, gathered rather than written.
 *
 * For one project and one day the system collects what it already knows -
 * where the project stands in its phases, who was on the team, what each task
 * moved, what was finished and what is late, the materials recorded and any
 * incident filed - and the lead adds the remarks only they can: how the day
 * went, what held it up, what happens tomorrow.
 *
 * The gathered part is frozen into the report when it is submitted, so a task
 * edited or an entry voided afterwards never changes a report already filed.
 * One per project per day.
 */
class ProgressReportBuilder
{
    public function __construct(
        private readonly ProjectPolicy $projects,
        private readonly ProjectPhaseProgress $phases,
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Why this lead may not file a progress report for this project on this
     * day, or null when they may.
     */
    public function refusal(?User $user, Project $project, string $date): ?string
    {
        if ($user === null || ! $this->projects->reportsOn($user, $project)) {
            return 'Only the project\'s lead files progress reports.';
        }

        if ($date > BusinessTime::today()->toDateString()) {
            return 'The date cannot be in the future.';
        }

        if (! $project->isScheduledOn($date)) {
            return 'Reports only on scheduled days.';
        }

        if ($this->alreadyFiled($project, $date)) {
            return 'A progress report for this day is already filed.';
        }

        return null;
    }

    public function alreadyFiled(Project $project, string $date): bool
    {
        return TechnicianReport::query()
            ->active()
            ->where('project_id', $project->project_id)
            ->where('report_type', 'progress')
            ->whereNotNull('report_data')
            ->whereDate('report_date', $date)
            ->exists();
    }

    /**
     * Everything the system knows about one project's day.
     *
     * @return array<string, mixed>
     */
    public function gather(Project $project, string $date): array
    {
        $project->loadMissing('client');
        $summary = $this->phases->summary($project);
        $current = collect($summary['phases'])->firstWhere('isCurrent', true);

        $taskIds = Task::query()->where('project_id', $project->project_id)->pluck('task_id');

        [$dayStart, $dayEnd] = [
            BusinessTime::toStored($date.' 00:00:00'),
            BusinessTime::toStored($date.' 23:59:59'),
        ];

        return [
            'date' => $date,
            'date_label' => CarbonImmutable::parse($date)->format(BusinessTime::DATE),
            'project' => [
                'reference_no' => $project->reference_no,
                'name' => $project->name,
                'client' => $project->client?->company_name ?: $project->client?->fullname,
                'address' => $project->address,
                'status' => $project->statusLabel(),
            ],
            'phase' => [
                'label' => $summary['label'],
                'percent' => $summary['percent'],
                'current' => $current ? $current['phase']->title : null,
                'current_tasks' => $current ? sprintf('%d/%d tasks', $current['completedTasks'], $current['totalTasks']) : null,
            ],
            'team' => ProjectTechnician::query()
                ->with('technician.account')
                ->where('project_id', $project->project_id)
                ->coveringDate($date)
                ->get()
                ->map(fn (ProjectTechnician $member): array => [
                    'name' => $member->technician?->name ?? 'Technician',
                    'lead' => (bool) $member->technician?->isLead(),
                ])
                ->sortByDesc('lead')
                ->values()
                ->all(),
            'task_updates' => TaskUpdate::query()
                ->with(['task', 'technician.account', 'images'])
                ->whereIn('task_id', $taskIds)
                ->whereDate('update_date', $date)
                ->orderBy('task_update_id')
                ->get()
                ->map(fn (TaskUpdate $update): array => [
                    'task' => $update->task?->task_title,
                    'technician' => $update->technician?->name,
                    'notes' => $update->notes,
                    'photos' => $update->images->count(),
                ])
                ->all(),
            // Started, not finished, and nothing posted on it this day - so a
            // task under way still shows when nobody wrote an update.
            'in_progress_tasks' => Task::query()
                ->with('technician.account')
                ->where('project_id', $project->project_id)
                ->whereIn('status', Task::ACTIVE_STATUSES)
                ->whereNotNull('start_date')
                ->whereDate('start_date', '<=', $date)
                ->whereNotIn('task_id', TaskUpdate::query()
                    ->whereIn('task_id', $taskIds)
                    ->whereDate('update_date', $date)
                    ->select('task_id'))
                ->orderBy('start_date')
                ->get()
                ->map(fn (Task $task): array => [
                    'task' => $task->task_title,
                    'technician' => $task->technician?->name,
                ])
                ->all(),
            'completed_tasks' => Task::query()
                ->with('technician.account')
                ->where('project_id', $project->project_id)
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$dayStart, $dayEnd])
                ->orderBy('completed_at')
                ->get()
                ->map(fn (Task $task): array => [
                    'task' => $task->task_title,
                    'technician' => $task->technician?->name,
                ])
                ->all(),
            'overdue_tasks' => Task::query()
                ->with('technician.account')
                ->where('project_id', $project->project_id)
                ->whereIn('status', Task::OPEN_STATUSES)
                ->whereNotNull('due_date')
                ->where('due_date', '<', $date)
                ->orderBy('due_date')
                ->get()
                ->map(fn (Task $task): array => [
                    'task' => $task->task_title,
                    'technician' => $task->technician?->name,
                    'due' => CarbonImmutable::parse($task->due_date)->format(BusinessTime::DATE),
                ])
                ->all(),
            'materials' => ProjectMaterialEntry::query()
                ->with('projectMaterial.material')
                ->notVoided()
                ->where('project_id', $project->project_id)
                ->whereDate('entry_date', $date)
                ->orderBy('material_entry_id')
                ->get()
                ->map(function (ProjectMaterialEntry $entry): array {
                    $material = $entry->projectMaterial?->material;

                    return [
                        'material' => $material?->displayName() ?? 'Material',
                        'type' => $entry->typeLabel(),
                        'quantity' => $material
                            ? $material->unit->format($entry->quantity).' '.$material->unit->value
                            : (string) $entry->quantity,
                    ];
                })
                ->all(),
            'incidents' => TechnicianReport::query()
                ->with('incident')
                ->active()
                ->where('project_id', $project->project_id)
                ->where('report_type', 'incident')
                ->whereDate('report_date', $date)
                ->orderBy('id')
                ->get()
                ->map(fn (TechnicianReport $report): array => [
                    'title' => $report->report_title,
                    'code' => $report->displayCode(),
                ])
                ->all(),
        ];
    }

    /**
     * File the report: what was gathered, frozen now, and the lead's remarks.
     *
     * @param  array{remarks?: string|null, issues?: string|null, next_plan?: string|null}  $notes
     * @param  array<int, UploadedFile>  $images
     *
     * @throws RuntimeException
     */
    public function file(Project $project, string $date, array $notes, array $images, User $by): TechnicianReport
    {
        $refusal = $this->refusal($by, $project, $date);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        $snapshot = $this->gather($project, $date) + [
            'remarks' => $this->text($notes['remarks'] ?? null),
            'issues' => $this->text($notes['issues'] ?? null),
            'next_plan' => $this->text($notes['next_plan'] ?? null),
            'filed_by' => $by->fullName(),
            'filed_at' => BusinessTime::now()->format(BusinessTime::DATE.' g:i A'),
        ];

        $technicianId = TechnicianReport::technicianIdFor($project, $by);

        $report = DB::transaction(function () use ($project, $date, $snapshot, $images, $by, $technicianId): TechnicianReport {
            $report = TechnicianReport::create([
                'project_id' => $project->project_id,
                'technician_id' => $technicianId,
                'submitted_by' => $by->id,
                'report_type' => 'progress',
                'report_title' => sprintf('Progress Report - %s', $snapshot['date_label']),
                'report_description' => $this->summaryText($snapshot),
                'report_data' => $snapshot,
                'report_date' => $date,
            ]);

            foreach ($images as $image) {
                TechnicianReportImage::create([
                    'technician_report_id' => $report->id,
                    'image_path' => UploadStore::put($image, 'report_images'),
                ]);
            }

            return $report;
        });

        $this->activityLogger->record(
            ActivityLog::REPORT_GENERATED,
            null,
            sprintf('Filed the %s progress report on %s.', $snapshot['date_label'], $project->reference_no),
            $report
        );

        $this->notifications->technicianReportFiled($project, 'progress report');

        return $report;
    }

    /**
     * The report as plain text, for every screen that reads a report's
     * description - the client's page among them - and for search.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function summaryText(array $snapshot): string
    {
        $lines = [];

        $phase = $snapshot['phase'];
        $lines[] = sprintf('Progress: %s (%d%%)%s', $phase['label'], $phase['percent'], $phase['current'] ? ', current phase '.$phase['current'] : '');

        if ($snapshot['team'] !== []) {
            $lines[] = 'Team: '.implode(', ', array_column($snapshot['team'], 'name'));
        }

        foreach ($snapshot['task_updates'] as $update) {
            $lines[] = sprintf('- %s (%s): %s', $update['task'], $update['technician'], $update['notes']);
        }

        if ($snapshot['completed_tasks'] !== []) {
            $lines[] = 'Completed: '.implode(', ', array_column($snapshot['completed_tasks'], 'task'));
        }

        if ($snapshot['materials'] !== []) {
            $lines[] = 'Materials: '.implode(', ', array_map(
                fn (array $entry): string => sprintf('%s %s %s', $entry['type'], $entry['quantity'], $entry['material']),
                $snapshot['materials']
            ));
        }

        foreach (['remarks' => 'Remarks', 'issues' => 'Issues', 'next_plan' => 'Next'] as $key => $label) {
            if (filled($snapshot[$key] ?? null)) {
                $lines[] = $label.': '.$snapshot[$key];
            }
        }

        return implode("\n", $lines);
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 2000);
    }
}
