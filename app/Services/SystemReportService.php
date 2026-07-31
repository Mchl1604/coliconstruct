<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\Technician;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every figure on the System Reports tab, and the same figures again for the
 * PDF export, so the dashboard and the document can never disagree.
 *
 * Each metric is a grouped aggregate query rather than a loop over models, so
 * cost stays flat as the data grows. Results are memoised per instance because
 * a single request asks for several overlapping numbers.
 */
class SystemReportService
{
    public const PERIOD_WEEKLY = 'weekly';

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    public const PERIOD_CUSTOM = 'custom';

    /**
     * Statuses that count as live work when judging technician utilisation.
     *
     * @var array<int, string>
     */
    private const ACTIVE_STATUSES = ['pending', 'ongoing'];

    /** @var array<string, mixed> */
    private array $memo = [];

    /**
     * Resolve a period name into a concrete window plus the bucket size the
     * "over time" charts should use.
     *
     * @return array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable, bucket: string}
     */
    public function resolvePeriod(?string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        $today = CarbonImmutable::today();

        return match ($period) {
            self::PERIOD_YEARLY => [
                'key' => self::PERIOD_YEARLY,
                'label' => $today->format('Y'),
                'start' => $today->startOfYear(),
                'end' => $today->endOfYear(),
                // A year of daily points is unreadable; group by month.
                'bucket' => 'month',
            ],
            self::PERIOD_CUSTOM => (function () use ($startDate, $endDate, $today): array {
                $start = $startDate ? CarbonImmutable::parse($startDate)->startOfDay() : $today->startOfMonth();
                $end = $endDate ? CarbonImmutable::parse($endDate)->endOfDay() : $today->endOfDay();

                if ($start->gt($end)) {
                    [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
                }

                return [
                    'key' => self::PERIOD_CUSTOM,
                    'label' => $start->format('M j, Y') . ' - ' . $end->format('M j, Y'),
                    'start' => $start,
                    'end' => $end,
                    // Long custom windows get monthly buckets, short ones daily.
                    'bucket' => $start->diffInDays($end) > 62 ? 'month' : 'day',
                ];
            })(),
            self::PERIOD_WEEKLY => [
                'key' => self::PERIOD_WEEKLY,
                'label' => $today->startOfWeek()->format('M j') . ' - ' . $today->endOfWeek()->format('M j, Y'),
                'start' => $today->startOfWeek(),
                'end' => $today->endOfWeek()->endOfDay(),
                'bucket' => 'day',
            ],
            default => [
                'key' => self::PERIOD_MONTHLY,
                'label' => $today->format('F Y'),
                'start' => $today->startOfMonth(),
                'end' => $today->endOfMonth()->endOfDay(),
                'bucket' => 'day',
            ],
        };
    }

    /**
     * Every summary card, keyed by module.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, array<string, mixed>>
     */
    public function summary(array $period): array
    {
        return [
            'projects' => $this->projectSummary($period),
            'quotations' => $this->quotationSummary($period),
            'technicians' => $this->technicianSummary(),
            'schedules' => $this->scheduleSummary(),
            'tasks' => $this->taskSummary(),
        ];
    }

    /**
     * Chart.js-ready datasets for every module.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, array<string, mixed>>
     */
    public function charts(array $period): array
    {
        return [
            'projectsOverTime' => $this->projectsOverTime($period),
            'projectsByStatus' => $this->projectsByStatus(),
            'projectCompletionTrend' => $this->projectCompletionTrend($period),
            'quotationValueOverTime' => $this->quotationValueOverTime($period),
            'quotationCountOverTime' => $this->quotationCountOverTime($period),
            'topClientsByValue' => $this->topClientsByValue(),
            'projectsPerTechnician' => $this->projectsPerTechnician(),
            'technicianUtilization' => $this->technicianUtilizationChart(),
            'specialtyDistribution' => $this->specialtyDistribution(),
            'schedulesOverTime' => $this->schedulesOverTime($period),
            'scheduleHealth' => $this->scheduleHealth(),
            'tasksByStatus' => $this->tasksByStatus(),
            'taskCompletionTrend' => $this->taskCompletionTrend($period),
            'tasksByProject' => $this->tasksByProject(),
        ];
    }

    // ------------------------------------------------------------------
    // Summary cards
    // ------------------------------------------------------------------

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array<string, mixed>
     */
    private function projectSummary(array $period): array
    {
        // One grouped query instead of a count per status.
        $counts = Project::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'pending' => (int) ($counts['pending'] ?? 0) + (int) ($counts['not_yet_scheduled'] ?? 0),
            'ongoing' => (int) ($counts['ongoing'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'archived' => (int) ($counts['archived'] ?? 0),
            'overdue' => Project::overdue()->count(),
            'created_in_period' => Project::query()
                ->whereBetween('created_at', [$period['start'], $period['end']])
                ->count(),
        ];
    }

    /**
     * The system stores only approved quotations, as the amount on the
     * project itself, so there is no pending/rejected split to report.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array<string, mixed>
     */
    private function quotationSummary(array $period): array
    {
        $totals = Project::query()
            ->whereNotNull('quotation')
            ->where('quotation', '>', 0)
            ->selectRaw('count(*) as total, coalesce(sum(quotation), 0) as value, coalesce(avg(quotation), 0) as average')
            ->first();

        $inPeriod = Project::query()
            ->whereNotNull('quotation')
            ->where('quotation', '>', 0)
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->selectRaw('count(*) as total, coalesce(sum(quotation), 0) as value')
            ->first();

        return [
            'total_approved' => (int) ($totals->total ?? 0),
            'total_value' => (float) ($totals->value ?? 0),
            'average_value' => (float) ($totals->average ?? 0),
            'created_in_period' => (int) ($inPeriod->total ?? 0),
            'value_in_period' => (float) ($inPeriod->value ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technicianSummary(): array
    {
        $total = $this->technicianCount();
        $assigned = $this->assignedTechnicianIds()->count();

        return [
            'total' => $total,
            'assigned' => $assigned,
            'available' => max($total - $assigned, 0),
            'utilization' => $total > 0 ? round($assigned / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleSummary(): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $scheduledProjects = DB::table('tbl_schedule')
            ->distinct()
            ->count('project_id');

        $activeSchedules = DB::table('tbl_schedule')
            ->join('tbl_projects', 'tbl_projects.project_id', '=', 'tbl_schedule.project_id')
            ->whereIn('tbl_projects.status', self::ACTIVE_STATUSES)
            ->where('tbl_projects.is_archived', false)
            ->whereDate('tbl_schedule.start_datetime', '<=', $today)
            ->whereDate('tbl_schedule.end_datetime', '>=', $today)
            ->count();

        // Each range beyond a project's first one is an extension.
        $rangeCounts = DB::table('tbl_schedule')
            ->selectRaw('project_id, count(*) as ranges')
            ->groupBy('project_id')
            ->pluck('ranges', 'project_id');

        $extensions = $rangeCounts->sum(fn ($ranges) => max((int) $ranges - 1, 0));

        return [
            'scheduled_projects' => (int) $scheduledProjects,
            'active_schedules' => (int) $activeSchedules,
            'overdue_projects' => Project::overdue()->count(),
            'average_duration_days' => $this->averageProjectDurationDays(),
            'extensions' => (int) $extensions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskSummary(): array
    {
        $counts = Task::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'pending' => (int) ($counts['pending'] ?? 0) + (int) ($counts['unassigned'] ?? 0),
            'ongoing' => (int) ($counts['ongoing'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'average_completion_days' => $this->averageTaskCompletionDays(),
        ];
    }

    // ------------------------------------------------------------------
    // Project charts
    // ------------------------------------------------------------------

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function projectsOverTime(array $period): array
    {
        $rows = Project::query()
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->selectRaw($this->bucketExpression('created_at', $period['bucket']) . ' as bucket, count(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->bucketedSeries($period, $rows, 'Projects Created');
    }

    /**
     * @return array<string, mixed>
     */
    private function projectsByStatus(): array
    {
        $counts = Project::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $labels = [
            'not_yet_scheduled' => 'Not Yet Scheduled',
            'pending' => 'Pending',
            'ongoing' => 'Ongoing',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'archived' => 'Archived',
        ];

        $colors = [
            'not_yet_scheduled' => '#0dcaf0',
            'pending' => '#f0ad4e',
            'ongoing' => '#0d6efd',
            'completed' => '#198754',
            'cancelled' => '#dc3545',
            'archived' => '#212529',
        ];

        $present = collect($labels)->filter(fn ($label, $status) => ($counts[$status] ?? 0) > 0);

        return [
            'labels' => $present->values()->all(),
            'values' => $present->keys()->map(fn ($status) => (int) $counts[$status])->all(),
            'colors' => $present->keys()->map(fn ($status) => $colors[$status])->all(),
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function projectCompletionTrend(array $period): array
    {
        $rows = Project::query()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$period['start'], $period['end']])
            ->selectRaw($this->bucketExpression('completed_at', $period['bucket']) . ' as bucket, count(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->bucketedSeries($period, $rows, 'Projects Completed');
    }

    // ------------------------------------------------------------------
    // Quotation charts
    // ------------------------------------------------------------------

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function quotationValueOverTime(array $period): array
    {
        $rows = Project::query()
            ->whereNotNull('quotation')
            ->where('quotation', '>', 0)
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->selectRaw($this->bucketExpression('created_at', $period['bucket']) . ' as bucket, sum(quotation) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->bucketedSeries($period, $rows, 'Quotation Value', true);
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function quotationCountOverTime(array $period): array
    {
        $rows = Project::query()
            ->whereNotNull('quotation')
            ->where('quotation', '>', 0)
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->selectRaw($this->bucketExpression('created_at', $period['bucket']) . ' as bucket, count(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->bucketedSeries($period, $rows, 'Approved Quotations');
    }

    /**
     * @return array<string, mixed>
     */
    private function topClientsByValue(): array
    {
        $rows = DB::table('tbl_projects')
            ->join('tbl_clients', 'tbl_clients.project_id', '=', 'tbl_projects.project_id')
            ->whereNotNull('tbl_projects.quotation')
            ->where('tbl_projects.quotation', '>', 0)
            ->selectRaw("
                coalesce(nullif(tbl_clients.company_name, ''), tbl_clients.fullname) as client,
                sum(tbl_projects.quotation) as total
            ")
            ->groupBy('client')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        return [
            'labels' => $rows->pluck('client')->map(fn ($name) => $name ?: 'Unnamed client')->all(),
            'values' => $rows->pluck('total')->map(fn ($total) => (float) $total)->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Technician charts
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function projectsPerTechnician(): array
    {
        $rows = DB::table('tbl_project_technicians')
            ->join('tbl_projects', 'tbl_projects.project_id', '=', 'tbl_project_technicians.project_id')
            ->join('tbl_technicians', 'tbl_technicians.technician_id', '=', 'tbl_project_technicians.technician_id')
            ->join('users', 'users.id', '=', 'tbl_technicians.account_id')
            ->whereIn('tbl_projects.status', self::ACTIVE_STATUSES)
            ->where('tbl_projects.is_archived', false)
            ->selectRaw('users.name as technician, count(distinct tbl_projects.project_id) as total')
            ->groupBy('users.name')
            ->orderByDesc('total')
            ->limit(12)
            ->get();

        return [
            'labels' => $rows->pluck('technician')->all(),
            'values' => $rows->pluck('total')->map(fn ($total) => (int) $total)->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technicianUtilizationChart(): array
    {
        $total = $this->technicianCount();
        $assigned = $this->assignedTechnicianIds()->count();

        return [
            'labels' => ['Assigned', 'Available'],
            'values' => [$assigned, max($total - $assigned, 0)],
            'colors' => ['#0d6efd', '#cbd5e1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specialtyDistribution(): array
    {
        $rows = DB::table('tbl_skill_map')
            ->join('tbl_skills', 'tbl_skills.skill_id', '=', 'tbl_skill_map.skill_id')
            ->selectRaw('tbl_skills.skill_name as skill, count(*) as total')
            ->groupBy('tbl_skills.skill_name')
            ->orderByDesc('total')
            ->get();

        return [
            'labels' => $rows->pluck('skill')->all(),
            'values' => $rows->pluck('total')->map(fn ($total) => (int) $total)->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Schedule charts
    // ------------------------------------------------------------------

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function schedulesOverTime(array $period): array
    {
        $rows = DB::table('tbl_schedule')
            ->whereBetween('start_datetime', [$period['start'], $period['end']])
            ->selectRaw($this->bucketExpression('start_datetime', $period['bucket']) . ' as bucket, count(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->bucketedSeries($period, $rows, 'Schedules Starting');
    }

    /**
     * Where the live projects stand relative to their own schedule.
     *
     * @return array<string, mixed>
     */
    private function scheduleHealth(): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $active = DB::table('tbl_projects')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('is_archived', false)
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('tbl_schedule')
                ->whereColumn('tbl_schedule.project_id', 'tbl_projects.project_id')
                ->whereDate('tbl_schedule.start_datetime', '<=', $today)
                ->whereDate('tbl_schedule.end_datetime', '>=', $today))
            ->count();

        $upcoming = DB::table('tbl_projects')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('is_archived', false)
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('tbl_schedule')
                ->whereColumn('tbl_schedule.project_id', 'tbl_projects.project_id')
                ->whereDate('tbl_schedule.start_datetime', '>', $today))
            ->count();

        $overdue = Project::overdue()->count();

        return [
            'labels' => ['Active Now', 'Upcoming', 'Overdue'],
            'values' => [(int) $active, (int) $upcoming, (int) $overdue],
            'colors' => ['#0d6efd', '#f0ad4e', '#fd7e14'],
        ];
    }

    // ------------------------------------------------------------------
    // Task charts
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function tasksByStatus(): array
    {
        $counts = Task::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $labels = [
            'unassigned' => 'Unassigned',
            'pending' => 'Pending',
            'ongoing' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ];

        $colors = [
            'unassigned' => '#f0ad4e',
            'pending' => '#6c757d',
            'ongoing' => '#0d6efd',
            'completed' => '#198754',
            'cancelled' => '#dc3545',
        ];

        $present = collect($labels)->filter(fn ($label, $status) => ($counts[$status] ?? 0) > 0);

        return [
            'labels' => $present->values()->all(),
            'values' => $present->keys()->map(fn ($status) => (int) $counts[$status])->all(),
            'colors' => $present->keys()->map(fn ($status) => $colors[$status])->all(),
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function taskCompletionTrend(array $period): array
    {
        // There is no completed_at column, so updated_at stands in as the
        // moment a task was marked done.
        $rows = Task::query()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$period['start'], $period['end']])
            ->selectRaw($this->bucketExpression('updated_at', $period['bucket']) . ' as bucket, count(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->bucketedSeries($period, $rows, 'Tasks Completed');
    }

    /**
     * @return array<string, mixed>
     */
    private function tasksByProject(): array
    {
        $rows = DB::table('tbl_tasks')
            ->join('tbl_projects', 'tbl_projects.project_id', '=', 'tbl_tasks.project_id')
            ->selectRaw('tbl_projects.name as project, count(*) as total')
            ->groupBy('tbl_projects.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return [
            'labels' => $rows->pluck('project')->all(),
            'values' => $rows->pluck('total')->map(fn ($total) => (int) $total)->all(),
        ];
    }

    // ------------------------------------------------------------------
    // PDF data tables
    // ------------------------------------------------------------------

    /**
     * Rows for the tables in the exported PDF, limited to the sections the
     * chosen report type needs.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array<string, Collection<int, array<string, mixed>>>
     */
    public function exportTables(string $reportType, array $period): array
    {
        $tables = [];

        if (in_array($reportType, ['complete', 'projects'], true)) {
            $tables['projects'] = $this->projectRows($period);
        }

        if (in_array($reportType, ['complete', 'technicians'], true)) {
            $tables['technicians'] = $this->technicianRows();
        }

        if (in_array($reportType, ['complete', 'schedules'], true)) {
            $tables['schedules'] = $this->scheduleRows();
        }

        if (in_array($reportType, ['complete', 'tasks'], true)) {
            $tables['tasks'] = $this->taskRows($period);
        }

        if (in_array($reportType, ['complete', 'quotations'], true)) {
            $tables['quotations'] = $this->quotationRows($period);
        }

        return $tables;
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return Collection<int, array<string, mixed>>
     */
    private function projectRows(array $period): Collection
    {
        return Project::query()
            ->with('schedules')
            ->orderByDesc('project_id')
            ->get()
            ->map(fn (Project $project): array => [
                'reference_no' => $project->reference_no,
                'name' => $project->name,
                'status' => $project->statusLabel(),
                'start_date' => $this->formatDate($project->schedules->min('start_datetime')),
                'end_date' => $this->formatDate($project->schedules->max('end_datetime')),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function technicianRows(): Collection
    {
        $assignmentCounts = DB::table('tbl_project_technicians')
            ->join('tbl_projects', 'tbl_projects.project_id', '=', 'tbl_project_technicians.project_id')
            ->whereIn('tbl_projects.status', self::ACTIVE_STATUSES)
            ->where('tbl_projects.is_archived', false)
            ->selectRaw('tbl_project_technicians.technician_id, count(distinct tbl_projects.project_id) as total')
            ->groupBy('tbl_project_technicians.technician_id')
            ->pluck('total', 'technician_id');

        $maxLoad = max((int) $assignmentCounts->max(), 1);

        return Technician::query()
            ->with(['account', 'skills'])
            ->whereHas('account', fn ($query) => $query->whereIn('role', ['technician', 'lead_technician']))
            ->orderBy('technician_id')
            ->get()
            ->map(function (Technician $technician) use ($assignmentCounts, $maxLoad): array {
                $assigned = (int) ($assignmentCounts[$technician->technician_id] ?? 0);

                return [
                    'name' => $technician->name,
                    'position' => optional($technician->account)->role === 'lead_technician'
                        ? 'Lead Technician'
                        : 'Technician',
                    'assigned_projects' => $assigned,
                    // Relative to the busiest technician, so the column reads
                    // as a workload comparison rather than a false absolute.
                    'utilization' => round($assigned / $maxLoad * 100, 1),
                    'specialties' => implode(', ', $technician->skill_names) ?: '—',
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function scheduleRows(): Collection
    {
        return Project::query()
            ->with('schedules')
            ->whereHas('schedules')
            ->orderByDesc('project_id')
            ->get()
            ->map(function (Project $project): array {
                $ranges = $project->schedules
                    ->map(fn ($schedule) => CarbonImmutable::parse($schedule->start_datetime)->format('M j, Y')
                        . ' - '
                        . CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->format('M j, Y'))
                    ->implode('; ');

                return [
                    'reference_no' => $project->reference_no,
                    'name' => $project->name,
                    'schedule' => $ranges,
                    'duration_days' => $this->projectDurationDays($project),
                    'status' => $project->statusLabel(),
                ];
            });
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return Collection<int, array<string, mixed>>
     */
    private function taskRows(array $period): Collection
    {
        return Task::query()
            ->with(['project', 'technician.account'])
            ->orderByDesc('task_id')
            ->get()
            ->map(fn (Task $task): array => [
                'title' => $task->task_title,
                'project' => $task->project?->name ?? '—',
                'technician' => $task->technician?->name ?? 'Unassigned',
                'status' => ucfirst((string) $task->status),
                'completion_date' => $task->status === 'completed'
                    ? $this->formatDate($task->updated_at)
                    : '—',
            ]);
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return Collection<int, array<string, mixed>>
     */
    private function quotationRows(array $period): Collection
    {
        return Project::query()
            ->with('clients')
            ->whereNotNull('quotation')
            ->where('quotation', '>', 0)
            ->orderByDesc('project_id')
            ->get()
            ->map(function (Project $project): array {
                $client = $project->clients->first();

                return [
                    // There is no separate quotation number; the project
                    // reference identifies the approved quotation.
                    'reference_no' => $project->reference_no,
                    'project' => $project->name,
                    'client' => $client?->company_name ?: ($client?->fullname ?? '—'),
                    'date_approved' => $this->formatDate($project->created_at),
                    'amount' => (float) $project->quotation,
                ];
            });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Technicians with at least one live project.
     *
     * @return Collection<int, int>
     */
    private function assignedTechnicianIds(): Collection
    {
        return $this->memo['assignedTechnicianIds'] ??= DB::table('tbl_project_technicians')
            ->join('tbl_projects', 'tbl_projects.project_id', '=', 'tbl_project_technicians.project_id')
            ->whereIn('tbl_projects.status', self::ACTIVE_STATUSES)
            ->where('tbl_projects.is_archived', false)
            ->distinct()
            ->pluck('tbl_project_technicians.technician_id');
    }

    private function technicianCount(): int
    {
        return $this->memo['technicianCount'] ??= Technician::query()
            ->whereHas('account', fn ($query) => $query->whereIn('role', ['technician', 'lead_technician']))
            ->count();
    }

    /**
     * Mean number of scheduled days per project, counting every range so a
     * project booked in blocks isn't credited for the gaps between them.
     */
    private function averageProjectDurationDays(): float
    {
        $perProject = DB::table('tbl_schedule')
            ->selectRaw('project_id, sum(' . $this->dayDiffExpression('end_datetime', 'start_datetime') . ' + 1) as days')
            ->groupBy('project_id')
            ->pluck('days');

        if ($perProject->isEmpty()) {
            return 0.0;
        }

        return round($perProject->sum() / $perProject->count(), 1);
    }

    private function projectDurationDays(Project $project): int
    {
        return (int) $project->schedules->sum(function ($schedule): int {
            $start = CarbonImmutable::parse($schedule->start_datetime)->startOfDay();
            $end = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->startOfDay();

            return $start->diffInDays($end) + 1;
        });
    }

    /**
     * Mean days from a completed task's start date to when it was marked done.
     * updated_at stands in for a completion timestamp, which the table lacks.
     */
    private function averageTaskCompletionDays(): float
    {
        $diff = $this->dayDiffExpression('updated_at', 'start_date');

        $average = Task::query()
            ->where('status', 'completed')
            ->whereNotNull('start_date')
            ->selectRaw("avg(case when {$diff} > 0 then {$diff} else 0 end) as days")
            ->value('days');

        return $average === null ? 0.0 : round((float) $average, 1);
    }

    /**
     * Whole-day difference between two columns, for the active driver.
     *
     * MySQL has datediff(); sqlite - which the test suite uses - does not,
     * so julianday() stands in.
     */
    private function dayDiffExpression(string $later, string $earlier): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "cast(julianday(date({$later})) - julianday(date({$earlier})) as integer)";
        }

        return "datediff({$later}, {$earlier})";
    }

    /**
     * Portable date grouping expression for the active connection.
     */
    private function bucketExpression(string $column, string $bucket): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return $bucket === 'month'
                ? "strftime('%Y-%m', {$column})"
                : "strftime('%Y-%m-%d', {$column})";
        }

        return $bucket === 'month'
            ? "date_format({$column}, '%Y-%m')"
            : "date_format({$column}, '%Y-%m-%d')";
    }

    /**
     * Pad a sparse grouped result into a continuous series so charts don't
     * skip days or months with no activity.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @param  Collection<string, mixed>  $rows
     * @return array<string, mixed>
     */
    private function bucketedSeries(array $period, Collection $rows, string $label, bool $isMoney = false): array
    {
        $labels = [];
        $values = [];
        $cursor = $period['bucket'] === 'month'
            ? $period['start']->startOfMonth()
            : $period['start']->startOfDay();
        $end = $period['end'];
        $guard = 0;

        while ($cursor->lte($end) && $guard < 400) {
            $key = $period['bucket'] === 'month'
                ? $cursor->format('Y-m')
                : $cursor->format('Y-m-d');

            $labels[] = $period['bucket'] === 'month'
                ? $cursor->format('M Y')
                : $cursor->format('M j');

            $raw = $rows[$key] ?? 0;
            $values[] = $isMoney ? (float) $raw : (int) $raw;

            $cursor = $period['bucket'] === 'month'
                ? $cursor->addMonth()
                : $cursor->addDay();
            $guard++;
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'label' => $label,
        ];
    }

    private function formatDate($value): string
    {
        return $value ? CarbonImmutable::parse($value)->format('M j, Y') : '—';
    }
}
