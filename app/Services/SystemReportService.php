<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
     * The two granularities every dashboard chart can be switched between.
     * Monthly draws the current year as twelve months; yearly draws the last
     * few whole years. Charts with no time axis use the same span as their
     * window, so the toggle always means the same thing.
     */
    public const GRANULARITY_MONTHLY = 'monthly';

    public const GRANULARITY_YEARLY = 'yearly';

    /** How many whole years the yearly view reaches back over, inclusive. */
    private const YEARLY_SPAN = 5;

    /**
     * Every chart the System Reports tab draws.
     *
     * @var array<int, string>
     */
    public const CHART_KEYS = [
        'currentProjectBreakdown',
        'completedProjects',
        'projectsByType',
        'totalQuotation',
        'residentialVsCommercial',
        'topClients',
    ];

    /**
     * The status filter on the Total Quotation chart. "all" is the union of
     * the other options - cancelled, archived and unscheduled work carries no
     * committed money, so none of them are ever counted.
     *
     * @var array<string, string>
     */
    public const QUOTATION_STATUSES = [
        'all' => 'All Projects',
        'on_hold' => 'On Hold',
        'pending' => 'Pending',
        'ongoing' => 'Ongoing',
        'overdue' => 'Overdue',
        'completed' => 'Completed',
    ];

    /**
     * The stored statuses that "all" rolls up, before On Hold and Overdue are
     * split back out of them.
     *
     * @var array<int, string>
     */
    private const QUOTATION_ALL_STATUSES = [
        'pending',
        'ongoing',
        Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
        'completed',
    ];

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
                    'label' => $start->format('M j, Y').' - '.$end->format('M j, Y'),
                    'start' => $start,
                    'end' => $end,
                    // Long custom windows get monthly buckets, short ones daily.
                    'bucket' => $start->diffInDays($end) > 62 ? 'month' : 'day',
                ];
            })(),
            self::PERIOD_WEEKLY => [
                'key' => self::PERIOD_WEEKLY,
                'label' => $today->startOfWeek()->format('M j').' - '.$today->endOfWeek()->format('M j, Y'),
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
     * The window a single chart covers, and the bucket its x-axis uses.
     *
     * Monthly is the current calendar year in twelve month-sized buckets;
     * yearly reaches back over the last few whole years, one bucket each.
     *
     * @return array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable, bucket: string}
     */
    public function resolveGranularity(?string $granularity): array
    {
        $today = CarbonImmutable::today();

        if ($granularity === self::GRANULARITY_YEARLY) {
            $start = $today->startOfYear()->subYears(self::YEARLY_SPAN - 1);

            return [
                'key' => self::GRANULARITY_YEARLY,
                'label' => $start->format('Y').' - '.$today->format('Y'),
                'start' => $start,
                'end' => $today->endOfYear()->endOfDay(),
                'bucket' => 'year',
            ];
        }

        return [
            'key' => self::GRANULARITY_MONTHLY,
            'label' => $today->format('Y'),
            'start' => $today->startOfYear(),
            'end' => $today->endOfYear()->endOfDay(),
            'bucket' => 'month',
        ];
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
     * Chart.js-ready datasets for every chart, each at its own granularity.
     *
     * Every chart carries its own Monthly/Yearly toggle, so the caller passes
     * the state of each one rather than a single period for the whole page.
     *
     * @param  array<string, string>  $granularities  chart key => monthly|yearly
     * @return array<string, array<string, mixed>>
     */
    public function charts(array $granularities = [], ?string $quotationStatus = null): array
    {
        $charts = [];

        foreach (self::CHART_KEYS as $key) {
            $charts[$key] = $this->chart($key, $granularities[$key] ?? null, $quotationStatus);
        }

        return $charts;
    }

    /**
     * One chart, so flipping a single toggle doesn't recompute the page.
     *
     * @return array<string, mixed>
     */
    public function chart(string $key, ?string $granularity = null, ?string $quotationStatus = null): array
    {
        $period = $this->resolveGranularity($granularity);

        return match ($key) {
            // A snapshot of where the work stands right now, so it has no
            // window and ignores the granularity entirely.
            'currentProjectBreakdown' => $this->currentProjectBreakdown(),
            'completedProjects' => $this->completedProjects($period),
            'projectsByType' => $this->projectsByType($period),
            'totalQuotation' => $this->totalQuotation($period, $quotationStatus ?? 'all'),
            'residentialVsCommercial' => $this->residentialVsCommercial($period),
            'topClients' => $this->topClients($period),
            default => throw new InvalidArgumentException("Unknown chart [{$key}]."),
        };
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
            'pending' => (int) ($counts['pending'] ?? 0) + (int) ($counts['unscheduled'] ?? 0),
            'ongoing' => (int) ($counts['ongoing'] ?? 0),
            // Finished work counts as completed in the headline figures, the
            // same way it does on the dashboard and in the Completed tab.
            'completed' => (int) ($counts['completed'] ?? 0)
                + (int) ($counts[Project::STATUS_AWAITING_CLIENT_CONFIRMATION] ?? 0),
            'awaiting_confirmation' => (int) ($counts[Project::STATUS_AWAITING_CLIENT_CONFIRMATION] ?? 0),
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
     * Where every project stands right now, using the same vocabulary the
     * rest of the app shows: On Hold and Overdue are states in their own
     * right, not variations of Pending or Ongoing.
     *
     * @return array<string, mixed>
     */
    private function currentProjectBreakdown(): array
    {
        $counts = Project::query()
            ->selectRaw($this->currentStatusExpression().' as bucket, count(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        // Overdue is derived from the schedule rather than stored, so it can't
        // come out of the CASE above; those projects are moved here out of the
        // Pending and Ongoing slices they were counted in.
        $overdue = Project::overdue()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = $counts->map(
            fn ($total, $status) => (int) $total - (int) ($overdue[$status] ?? 0)
        );

        $counts['overdue'] = (int) $overdue->sum();

        $slices = [
            'unscheduled' => ['Unscheduled', '#0dcaf0'],
            'pending' => ['Pending', '#f0ad4e'],
            'ongoing' => ['Ongoing', '#0d6efd'],
            'on_hold' => ['On Hold', '#6c757d'],
            'overdue' => ['Overdue', Project::OVERDUE_COLOR],
            // Its own slice rather than folded into Completed: this chart is
            // where the work stands, and "finished but not signed off" is a
            // real place for it to stand.
            'awaiting_client_confirmation' => ['Awaiting Confirmation', '#6ea67f'],
            'completed' => ['Completed', '#198754'],
            'cancelled' => ['Cancelled', '#dc3545'],
            'archived' => ['Archived', '#212529'],
        ];

        $present = collect($slices)->filter(fn ($slice, $status) => ($counts[$status] ?? 0) > 0);

        return [
            'labels' => $present->map(fn ($slice) => $slice[0])->values()->all(),
            'values' => $present->keys()->map(fn ($status) => (int) $counts[$status])->all(),
            'colors' => $present->map(fn ($slice) => $slice[1])->values()->all(),
        ];
    }

    /**
     * Projects finished in each bucket of the window.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function completedProjects(array $period): array
    {
        $rows = Project::query()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$period['start'], $period['end']])
            ->selectRaw($this->bucketExpression('completed_at', $period['bucket']).' as bucket, count(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->bucketedSeries($period, $rows, 'Completed Projects');
    }

    /**
     * How many projects of each type were opened in the window. A project can
     * carry several types, so it is counted once under each of them.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array<string, mixed>
     */
    private function projectsByType(array $period): array
    {
        $rows = DB::table('tbl_project_type_map')
            ->join('tbl_project_types', 'tbl_project_types.type_id', '=', 'tbl_project_type_map.type_id')
            ->join('tbl_projects', 'tbl_projects.project_id', '=', 'tbl_project_type_map.project_id')
            ->whereBetween('tbl_projects.created_at', [$period['start'], $period['end']])
            ->selectRaw('tbl_project_types.type_name as type, count(distinct tbl_projects.project_id) as total')
            ->groupBy('tbl_project_types.type_name')
            ->orderByDesc('total')
            ->get();

        return [
            'labels' => $rows->pluck('type')->all(),
            'values' => $rows->pluck('total')->map(fn ($total) => (int) $total)->all(),
            'label' => 'Projects',
        ];
    }

    /**
     * Residential against commercial work opened in the window, read from the
     * client attached to each project.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array<string, mixed>
     */
    private function residentialVsCommercial(array $period): array
    {
        $rows = DB::table('tbl_projects')
            ->join('tbl_clients', 'tbl_clients.project_id', '=', 'tbl_projects.project_id')
            ->whereBetween('tbl_projects.created_at', [$period['start'], $period['end']])
            ->selectRaw('lower(tbl_clients.client_type) as type, count(distinct tbl_projects.project_id) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'labels' => ['Residential', 'Commercial'],
            'values' => [
                (int) ($rows['residential'] ?? 0),
                (int) ($rows['commercial'] ?? 0),
            ],
            'colors' => ['#2563eb', '#198754'],
            'label' => 'Projects',
        ];
    }

    // ------------------------------------------------------------------
    // Quotation charts
    // ------------------------------------------------------------------

    /**
     * Committed quotation value over the window, optionally narrowed to one
     * project status.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function totalQuotation(array $period, string $status): array
    {
        $query = Project::query()
            ->whereNotNull('quotation')
            ->where('quotation', '>', 0)
            ->whereBetween('created_at', [$period['start'], $period['end']]);

        $this->applyQuotationStatus($query, $status);

        $rows = $query
            ->selectRaw($this->bucketExpression('created_at', $period['bucket']).' as bucket, sum(quotation) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $label = $status === 'all'
            ? 'Total Quotation'
            : 'Total Quotation - '.(self::QUOTATION_STATUSES[$status] ?? ucfirst($status));

        return $this->bucketedSeries($period, $rows, $label, true) + ['status' => $status];
    }

    /**
     * The ten biggest clients in the window, by the summed quotation of every
     * project they hold. Clients live on the project rather than in a table of
     * their own, so the company name - or the person's name for a residential
     * client - is what ties a client's projects together.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array<string, mixed>
     */
    private function topClients(array $period): array
    {
        $rows = DB::table('tbl_projects')
            ->join('tbl_clients', 'tbl_clients.project_id', '=', 'tbl_projects.project_id')
            ->whereNotNull('tbl_projects.quotation')
            ->where('tbl_projects.quotation', '>', 0)
            ->whereBetween('tbl_projects.created_at', [$period['start'], $period['end']])
            ->selectRaw("
                coalesce(nullif(tbl_clients.company_name, ''), tbl_clients.fullname) as client,
                sum(tbl_projects.quotation) as total
            ")
            ->groupBy('client')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return [
            'labels' => $rows->pluck('client')->map(fn ($name) => $name ?: 'Unnamed client')->all(),
            'values' => $rows->pluck('total')->map(fn ($total) => (float) $total)->all(),
            'label' => 'Total Quotation',
        ];
    }

    /**
     * Narrow a project query to one of the statuses the quotation filter
     * offers. On Hold and Overdue are derived rather than stored, so they are
     * both added to and subtracted from the stored statuses they overlap.
     *
     * @param  Builder<Project>  $query
     */
    private function applyQuotationStatus(Builder $query, string $status): void
    {
        // Nothing archived ever counts, whichever option is chosen.
        $query->where('is_archived', false)->where('status', '!=', 'archived');

        match ($status) {
            'on_hold' => $query
                ->where('on_hold', true)
                ->whereNotIn('status', Project::READ_ONLY_STATUSES),

            'overdue' => $query->whereIn('project_id', $this->overdueProjectIds()),

            // The work is done and the money is committed either way, so the
            // Completed option covers both - matching the Completed tab on the
            // projects table and the dashboard's Completed figure.
            'completed' => $query->whereIn('status', [
                'completed',
                Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            ]),

            'pending', 'ongoing' => $query
                ->where('status', $status)
                ->where(fn (Builder $hold) => $hold->where('on_hold', false)->orWhereNull('on_hold'))
                ->whereNotIn('project_id', $this->overdueProjectIds()),

            // "All" is the union of the options above: live, paused, late and
            // finished work, which is every project carrying committed money.
            default => $query->where(function (Builder $outer): void {
                $outer
                    ->whereIn('status', self::QUOTATION_ALL_STATUSES)
                    ->orWhere(fn (Builder $hold) => $hold
                        ->where('on_hold', true)
                        ->whereNotIn('status', Project::READ_ONLY_STATUSES));
            }),
        };
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
                // The shared formatter, so a Partial Day is reported with its
                // hours rather than as a date printed twice.
                $ranges = $project->schedules
                    ->map(fn (Schedule $schedule): string => $schedule->describe())
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

    /**
     * The projects Project::overdue() currently matches. Memoised because the
     * quotation filter consults it for several of its options.
     *
     * @return Collection<int, int>
     */
    private function overdueProjectIds(): Collection
    {
        return $this->memo['overdueProjectIds'] ??= Project::overdue()->pluck('project_id');
    }

    /**
     * A project's status as the app displays it, minus Overdue, which depends
     * on the schedule and has to be resolved separately.
     */
    private function currentStatusExpression(): string
    {
        return "case
            when is_archived = 1 or status = 'archived' then 'archived'
            when status = 'completed' then 'completed'
            when status = 'awaiting_client_confirmation' then 'awaiting_client_confirmation'
            when status = 'cancelled' then 'cancelled'
            when on_hold = 1 then 'on_hold'
            else status
        end";
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
            ->selectRaw('project_id, sum('.$this->dayDiffExpression('end_datetime', 'start_datetime').' + 1) as days')
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
            return match ($bucket) {
                'year' => "strftime('%Y', {$column})",
                'month' => "strftime('%Y-%m', {$column})",
                default => "strftime('%Y-%m-%d', {$column})",
            };
        }

        return match ($bucket) {
            'year' => "date_format({$column}, '%Y')",
            'month' => "date_format({$column}, '%Y-%m')",
            default => "date_format({$column}, '%Y-%m-%d')",
        };
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
        $bucket = $period['bucket'];

        [$keyFormat, $labelFormat] = match ($bucket) {
            'year' => ['Y', 'Y'],
            'month' => ['Y-m', 'M Y'],
            default => ['Y-m-d', 'M j'],
        };

        $labels = [];
        $values = [];
        $cursor = match ($bucket) {
            'year' => $period['start']->startOfYear(),
            'month' => $period['start']->startOfMonth(),
            default => $period['start']->startOfDay(),
        };
        $end = $period['end'];
        $guard = 0;

        while ($cursor->lte($end) && $guard < 400) {
            $labels[] = $cursor->format($labelFormat);

            $raw = $rows[$cursor->format($keyFormat)] ?? 0;
            $values[] = $isMoney ? (float) $raw : (int) $raw;

            $cursor = match ($bucket) {
                'year' => $cursor->addYear(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
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
