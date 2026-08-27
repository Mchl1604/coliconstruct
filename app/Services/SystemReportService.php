<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Support\BusinessTime;
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
    /**
     * The two periods an exported report can cover: one named month, or one
     * named year. Nothing narrower and nothing open-ended - a report is a
     * statement about a period somebody can name, and "last 30 days" is not
     * one of those.
     */
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    /**
     * The three reports the export dialog offers, keyed by what it submits.
     *
     * @var array<string, string>
     */
    public const EXPORT_TYPES = [
        'project' => 'Project Report',
        'new_projects' => 'New Projects Report',
        'schedule' => 'Schedule Report',
        'technician' => 'Technician Report',
    ];

    /**
     * The sections a Technician Report can carry.
     *
     * @var array<string, string>
     */
    public const TECHNICIAN_REPORT_KINDS = [
        'all' => 'All',
        'assigned' => 'Assigned Projects',
        'schedule' => 'Schedule',
        'tasks' => 'Tasks',
    ];

    /**
     * The Project Report's status filter. Archived is deliberately absent:
     * archived work is excluded from every report, so offering it would only
     * promise a page that must come back empty.
     *
     * @var array<string, string>
     */
    public const REPORT_STATUSES = [
        'all' => 'All Statuses',
        'unscheduled' => 'Unscheduled',
        Project::STATUS_AWAITING_CLIENT_CONFIRMATION => 'Awaiting Client Confirmation',
        'pending' => 'Pending',
        'ongoing' => 'Ongoing',
        'on_hold' => 'On Hold',
        'overdue' => 'Overdue',
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
    ];

    /**
     * Statuses whose money is not committed and must never reach a quotation
     * total, however the rows were filtered.
     *
     * @var array<int, string>
     */
    private const UNBILLABLE_STATUSES = ['cancelled', 'archived'];

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
     * Every chart the System Reports tab draws, in the three sections the page
     * lays them out in: the work, the people leading it, and the calendar.
     *
     * @var array<int, string>
     */
    public const CHART_KEYS = [
        // Projects
        'activeProjectBreakdown',
        'completedProjects',
        'projectsByType',
        'residentialVsCommercial',
        'totalQuotation',
        'topClients',
        // Lead technicians
        'leadTechnicianProjects',
        'leadTechnicianWorkload',
        'leadTechnicianAvailability',
        // Schedules
        'scheduledProjectsTrend',
        'scheduleTypeDistribution',
        'averageProjectDuration',
    ];

    /**
     * How many lead technicians the workload chart will draw as separate
     * series before it collapses into a single total.
     *
     * A grouped bar chart compares a handful of people well and turns into
     * stripes beyond that, so past this point the chart answers "how much work
     * was booked" rather than "who by" - which is what the distribution chart
     * beside it is for.
     */
    private const WORKLOAD_SERIES_LIMIT = 5;

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
     * The stored statuses a project is in while it is still live work.
     *
     * Pending and Ongoing are also where On Hold and Overdue live: both are
     * derived - a flag and a passed schedule - and neither replaces the stored
     * status. So these three, minus the archived ones, are exactly Unscheduled
     * + Pending + Ongoing + On Hold + Overdue: the same five the Active
     * Projects Breakdown draws, and the one definition of "active" every
     * figure on this page counts by.
     *
     * Deliberately wider than Project::ACTIVE_PROJECT_STATUSES, which answers
     * a different question: that constant is about whether a technician's
     * DATES are taken, and an unscheduled project has no dates to take. A
     * project still does belong to whoever is assigned to it, though - work
     * that has not been booked yet is somebody's to book - so it counts here.
     *
     * @var array<int, string>
     */
    private const ACTIVE_STATUSES = ['unscheduled', 'pending', 'ongoing'];

    /** @var array<string, mixed> */
    private array $memo = [];

    /**
     * The window an exported report covers: one named month, or one named
     * year. Both ends are inclusive, and the label is what the reader is
     * promised on the header - "August 2026" or "2026".
     *
     * @return array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    public function resolveExportPeriod(string $period, ?int $month, int $year): array
    {
        if ($period === self::PERIOD_YEARLY) {
            $start = CarbonImmutable::create($year, 1, 1)->startOfDay();

            return [
                'key' => self::PERIOD_YEARLY,
                'label' => $start->format('Y'),
                'start' => $start,
                'end' => $start->endOfYear()->endOfDay(),
            ];
        }

        $start = CarbonImmutable::create($year, $month ?: 1, 1)->startOfDay();

        return [
            'key' => self::PERIOD_MONTHLY,
            'label' => $start->format('F Y'),
            'start' => $start,
            'end' => $start->endOfMonth()->endOfDay(),
        ];
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
            // Snapshots of where things stand right now, so they have no window
            // and ignore the granularity entirely.
            'activeProjectBreakdown' => $this->activeProjectBreakdown(),
            'leadTechnicianProjects' => $this->leadTechnicianProjects(),
            'leadTechnicianAvailability' => $this->leadTechnicianAvailability(),
            'scheduleTypeDistribution' => $this->scheduleTypeDistribution(),

            'completedProjects' => $this->completedProjects($period),
            'projectsByType' => $this->projectsByType($period),
            'totalQuotation' => $this->totalQuotation($period, $quotationStatus ?? 'all'),
            'residentialVsCommercial' => $this->residentialVsCommercial($period),
            'topClients' => $this->topClients($period),
            'leadTechnicianWorkload' => $this->leadTechnicianWorkload($period),
            'scheduledProjectsTrend' => $this->scheduledProjectsTrend($period),
            'averageProjectDuration' => $this->averageProjectDuration($period),
            default => throw new InvalidArgumentException("Unknown chart [{$key}]."),
        };
    }

    // ------------------------------------------------------------------
    // Project charts
    // ------------------------------------------------------------------

    /**
     * Where the live work stands right now, using the same vocabulary the rest
     * of the app shows: On Hold and Overdue are states in their own right, not
     * variations of Pending or Ongoing.
     *
     * Only work that is still someone's problem today is counted. Awaiting
     * Confirmation, Completed, Cancelled and Archived projects are left out
     * entirely rather than drawn as slices nobody can act on - which is also
     * what keeps the total honest: a company with four hundred finished
     * projects and six live ones should read as six.
     *
     * @return array<string, mixed>
     */
    private function activeProjectBreakdown(): array
    {
        $counts = $this->activeBreakdownQuery()
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

        // Colours come from the model so the pie, the status badges and the
        // exported PDF are one system rather than three.
        $slices = [
            'unscheduled' => 'Unscheduled',
            'pending' => 'Pending',
            'ongoing' => 'Ongoing',
            'on_hold' => 'On Hold',
            'overdue' => 'Overdue',
        ];

        $present = collect($slices)->filter(fn ($label, $status) => ($counts[$status] ?? 0) > 0);
        $values = $present->keys()->map(fn ($status) => (int) $counts[$status])->all();

        return [
            'labels' => $present->values()->all(),
            'values' => $values,
            'colors' => $present->keys()->map(fn ($status) => Project::statusColor($status)[0])->all(),
            'label' => 'Active Projects',
            'summary' => 'Active projects: '.number_format(array_sum($values)),
        ];
    }

    /**
     * The projects the Active Projects Breakdown may draw: everything that is
     * neither finished, abandoned nor filed away.
     *
     * The excluded statuses are dropped here, before the counting, so they
     * cannot reach the chart's total by any route.
     *
     * @return Builder<Project>
     */
    private function activeBreakdownQuery(): Builder
    {
        return Project::query()
            ->where('is_archived', false)
            ->whereNotIn('status', [
                Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
                'completed',
                'cancelled',
                'archived',
            ]);
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
     * Residential against commercial work opened in each bucket of the window,
     * as two series side by side.
     *
     * The split is read from the client attached to the project and from
     * nowhere else - not the project type, not the name. It is recorded once
     * when the wizard writes the client row, which is what makes it the single
     * answer to the question.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function residentialVsCommercial(array $period): array
    {
        $rows = DB::table('tbl_projects')
            ->join('tbl_clients', 'tbl_clients.project_id', '=', 'tbl_projects.project_id')
            ->whereBetween('tbl_projects.created_at', [$period['start'], $period['end']])
            ->selectRaw(
                'lower(tbl_clients.client_type) as series, '
                .$this->bucketExpression('tbl_projects.created_at', $period['bucket']).' as bucket, '
                // A project carrying two client rows of the same type is still
                // one project.
                .'count(distinct tbl_projects.project_id) as total'
            )
            ->groupBy('series', 'bucket')
            ->get();

        return $this->bucketedDatasets($period, $rows, [
            'residential' => ['Residential', '#2563eb'],
            'commercial' => ['Commercial', '#198754'],
        ]);
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

        $series = $this->bucketedSeries($period, $rows, $label, 'money');

        // The figure under the graph is the graph's own total, so changing
        // either filter moves the two together and they cannot disagree.
        return $series + [
            'status' => $status,
            'summary' => 'Total Quotation: '.$this->money(array_sum($series['values'])),
        ];
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
    // Lead technician charts
    // ------------------------------------------------------------------

    /**
     * How the live work is spread across the lead technicians carrying it.
     *
     * Only leads: this is a management view of who is answerable for what, and
     * the crews under them are followed on the technician pages rather than
     * here. Each project counts once for its lead however many schedules,
     * ranges or crew members it has, which is what the distinct count is for -
     * a project booked in four blocks is still one project to lead.
     *
     * Unscheduled work counts. A project handed to a lead before its dates are
     * set is already theirs to plan, and leaving it out understated exactly the
     * people with the most still to arrange.
     *
     * @return array<string, mixed>
     */
    private function leadTechnicianProjects(): array
    {
        $rows = $this->leadTechnicianActiveProjects();

        return [
            'labels' => $rows->pluck('technician')->all(),
            'values' => $rows->pluck('total')->map(fn ($total): int => (int) $total)->all(),
            'label' => 'Active Projects',
            'summary' => $rows->isEmpty()
                ? null
                : 'Lead technicians with active work: '.number_format($rows->count()),
        ];
    }

    /**
     * Active project assignments per lead, over time.
     *
     * A project lands in the bucket its work is booked in rather than the one
     * it was created in - workload is about when the crew is out, not when the
     * paperwork was raised. Within a bucket a project counts once per lead, so
     * neither extra ranges nor extra crew inflate it.
     *
     * Unscheduled work is therefore absent from this chart alone, having no
     * date to be placed under. It is counted everywhere else a lead's load is
     * reported; this axis is months, and a project with no dates has no month.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function leadTechnicianWorkload(array $period): array
    {
        $rows = $this->leadTechnicianQuery()
            ->join('tbl_schedule as s', 's.project_id', '=', 'p.project_id')
            ->whereBetween('s.start_datetime', [$period['start'], $period['end']])
            ->selectRaw(
                'u.name as series, '
                .$this->bucketExpression('s.start_datetime', $period['bucket']).' as bucket, '
                .'count(distinct p.project_id) as total'
            )
            ->groupBy('series', 'bucket')
            ->get();

        $names = $rows->pluck('series')->unique()->values();

        // Few enough leads to tell apart: one series each, so the chart shows
        // who as well as how much.
        if ($names->count() <= self::WORKLOAD_SERIES_LIMIT) {
            return $this->bucketedDatasets(
                $period,
                $rows,
                $names->mapWithKeys(fn ($name): array => [$name => [$name, null]])->all()
            );
        }

        // Past that the stripes stop being readable, so the chart falls back to
        // the total booked - and who is carrying it is read off the
        // distribution chart beside it.
        $totals = $rows
            ->groupBy('bucket')
            ->map(fn (Collection $bucket): int => (int) $bucket->sum('total'));

        return $this->bucketedSeries($period, $totals, 'Lead Technician Assignments');
    }

    /**
     * How many leads are carrying live work right now, and how many are free.
     *
     * Only active accounts are counted on either side: a deactivated lead is
     * not available, and their historical assignments stay exactly where they
     * are. Finished, cancelled and archived work releases a lead, so nobody
     * reads as busy on the strength of a project that ended last year.
     *
     * @return array<string, mixed>
     */
    private function leadTechnicianAvailability(): array
    {
        $total = $this->activeLeadTechnicianCount();
        $assigned = min($this->leadTechnicianActiveProjects()->count(), $total);

        return [
            'labels' => ['Assigned to Active Project', 'Available'],
            'values' => [$assigned, max($total - $assigned, 0)],
            'colors' => ['#2563eb', '#20c997'],
            'label' => 'Lead Technicians',
            'summary' => 'Active lead technicians: '.number_format($total),
        ];
    }

    /**
     * Active projects per lead technician, highest first.
     *
     * Memoised: the distribution chart and the availability doughnut are the
     * same question asked twice, and a page load asks for both.
     *
     * @return Collection<int, object{technician: string, total: int}>
     */
    private function leadTechnicianActiveProjects(): Collection
    {
        return $this->memo['leadTechnicianActiveProjects'] ??= $this->leadTechnicianQuery()
            ->selectRaw('u.id as account_id, u.name as technician, count(distinct p.project_id) as total')
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('total')
            ->orderBy('u.name')
            ->get();
    }

    /**
     * Live project assignments held by lead technicians whose accounts are
     * still active. Every lead chart starts here.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function leadTechnicianQuery()
    {
        $query = DB::table('tbl_project_technicians as pt')
            // Live assignments only. The table keeps closed memberships now so
            // a project's history survives a staffing change - see
            // ProjectTechnician - and a chart of who is leading what today
            // must not count somebody taken off in March.
            ->whereNull('pt.removed_at')
            ->join('tbl_projects as p', 'p.project_id', '=', 'pt.project_id')
            ->join('tbl_technicians as t', 't.technician_id', '=', 'pt.technician_id')
            // The role on the account, never the order the team was assigned
            // in: the lead is whoever holds the role.
            ->join('users as u', 'u.id', '=', 't.account_id')
            ->where('u.role', User::ROLE_LEAD_TECHNICIAN)
            ->where('u.status', User::STATUS_ACTIVE)
            ->where('u.is_archived', false);

        return $this->applyActiveProjects($query, 'p');
    }

    private function activeLeadTechnicianCount(): int
    {
        return $this->memo['activeLeadTechnicianCount'] ??= User::query()
            ->where('role', User::ROLE_LEAD_TECHNICIAN)
            ->where('status', User::STATUS_ACTIVE)
            ->where('is_archived', false)
            ->count();
    }

    // ------------------------------------------------------------------
    // Schedule charts
    // ------------------------------------------------------------------

    /**
     * How many projects have work booked in each bucket.
     *
     * Projects, not bookings: a project split into four ranges is one project
     * on site, and counting the rows instead would make a heavily re-scheduled
     * job look like four.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function scheduledProjectsTrend(array $period): array
    {
        $rows = $this->scheduleQuery()
            ->whereBetween('s.start_datetime', [$period['start'], $period['end']])
            ->selectRaw(
                $this->bucketExpression('s.start_datetime', $period['bucket']).' as bucket, '
                .'count(distinct s.project_id) as total'
            )
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return $this->bucketedSeries($period, $rows, 'Scheduled Projects');
    }

    /**
     * Whole-day bookings against hours-on-a-date ones, as they stand today.
     *
     * Read from the stored mode rather than guessed from the times: a
     * whole-day range carries midnight-to-midnight padding, which is not the
     * same fact as somebody booking 8 AM to noon, and only the column knows
     * which was meant. Rows written before modes existed are whole-day, which
     * is what the model's own isDateBased() says.
     *
     * @return array<string, mixed>
     */
    private function scheduleTypeDistribution(): array
    {
        $rows = $this->scheduleQuery()
            ->selectRaw(
                "case when s.scheduling_mode = ? then 'partial_day' else 'date_based' end as mode, count(*) as total",
                [Schedule::MODE_PARTIAL_DAY]
            )
            ->groupBy('mode')
            ->pluck('total', 'mode');

        $values = [
            (int) ($rows['date_based'] ?? 0),
            (int) ($rows['partial_day'] ?? 0),
        ];

        return [
            'labels' => ['Date Based', 'Partial Day'],
            'values' => $values,
            'colors' => ['#2563eb', '#f0ad4e'],
            'label' => 'Schedules',
            'summary' => 'Bookings: '.number_format(array_sum($values)),
        ];
    }

    /**
     * The average number of booked days a project runs to, per bucket.
     *
     * Each project is totalled from its own ranges first and then placed in the
     * bucket its work starts in, so a project appears once however it was
     * booked and its duration is never split across two columns.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, mixed>
     */
    private function averageProjectDuration(array $period): array
    {
        $perProject = DB::query()
            ->fromSub($this->projectScheduleDays(), 'per_project')
            ->whereBetween('first_start', [$period['start'], $period['end']]);

        $rows = (clone $perProject)
            ->selectRaw(
                $this->bucketExpression('first_start', $period['bucket']).' as bucket, '
                .'avg(days) as total'
            )
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($average): float => round((float) $average, 1));

        $overall = (float) ((clone $perProject)->avg('days') ?? 0);

        $series = $this->bucketedSeries($period, $rows, 'Average Scheduled Days', 'decimal');

        return $series + [
            'summary' => 'Average: '.number_format(round($overall, 1), 1).' days',
        ];
    }

    /**
     * Each scheduled project with its total booked days and the day its work
     * first starts, as a subquery the duration figures aggregate over.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function projectScheduleDays()
    {
        return $this->scheduleQuery()
            ->selectRaw(
                's.project_id, min(s.start_datetime) as first_start, '
                .'sum('.$this->scheduleDaysExpression('s').') as days'
            )
            ->groupBy('s.project_id');
    }

    /**
     * Bookings on projects that still count, which is every schedule chart's
     * starting point. Archived work is left out: it is a filed record rather
     * than part of the company's scheduling activity.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function scheduleQuery()
    {
        return DB::table('tbl_schedule as s')
            ->join('tbl_projects as p', 'p.project_id', '=', 's.project_id')
            ->where('p.is_archived', false)
            ->where('p.status', '!=', 'archived');
    }

    // ------------------------------------------------------------------
    // Exported reports
    // ------------------------------------------------------------------

    /**
     * Everything one exported report prints, as sections the PDF renders in
     * order. Each section carries its own rows and its own summary, so a
     * figure is always the sum of the table directly above it.
     *
     * Archived work never enters any of them. It is excluded in the query
     * rather than filtered out afterwards, so it cannot reach a row, a count,
     * a duration or a peso total by any route.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function exportReport(string $reportType, array $period, array $filters = []): array
    {
        $report = match ($reportType) {
            'schedule' => $this->scheduleReport($period),
            'technician' => $this->technicianReport($period, $filters),
            'new_projects' => $this->newProjectsReport($period),
            default => $this->projectReport($period, $filters['project_status'] ?? 'all'),
        };

        return $report + [
            'type' => $reportType,
            'title' => self::EXPORT_TYPES[$reportType] ?? 'Report',
            'is_empty' => collect($report['sections'])->every(
                fn (array $section): bool => $this->sectionIsEmpty($section)
            ),
        ];
    }

    /**
     * One row per project, however many types, schedules or crew it carries.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array<string, mixed>
     */
    private function projectReport(array $period, string $status): array
    {
        $projects = $this->periodProjects($period)
            ->with(['clients', 'projectTypes', 'schedules'])
            ->get();

        // Status is derived - On Hold is a flag and Overdue is a passed
        // schedule - so the filter is applied to the resolved status the row
        // will actually print, never to the stored column alone.
        if ($status !== 'all') {
            $projects = $projects->filter(
                fn (Project $project): bool => $project->statusKey() === $status
            );
        }

        $order = array_flip(Project::REPORT_STATUS_ORDER);

        $rows = $projects
            ->sortBy(fn (Project $project): array => [
                // Unscheduled first, Completed last - never alphabetical.
                $order[$project->statusKey()] ?? count($order),
                $this->projectDate($project),
                (string) $project->reference_no,
            ])
            ->map(fn (Project $project): array => [
                'reference_no' => $project->reference_no ?: '—',
                // When the project was opened, from the row's own timestamp -
                // never re-derived from a schedule, which says when the work
                // was booked rather than when the job arrived. Same formatter
                // as every other date in the report.
                'created_on' => $this->formatDate($project->created_at),
                'client' => $this->clientName($project),
                'client_type' => $project->clientType() ? ucfirst(mb_strtolower($project->clientType())) : '—',
                'project_types' => $project->projectTypes->pluck('type_name')->all(),
                'status_key' => $project->statusKey(),
                'status_label' => $project->shortStatusLabel(),
                'schedules' => $project->schedules
                    ->map(fn (Schedule $schedule): string => $schedule->describe())
                    ->all(),
                'quotation' => (float) $project->quotation,
            ])
            ->values();

        return [
            'sections' => [[
                'key' => 'projects',
                'title' => 'Projects',
                'rows' => $rows,
                'summary' => [
                    // Cancelled work is shown but never billed, so the total
                    // is taken from the rows that survive that rule rather
                    // than from the table as a whole.
                    ['label' => 'Total Quotation', 'value' => $this->money($this->billableTotal($rows))],
                    ...$this->statusCounts($rows),
                ],
            ]],
        ];
    }

    /**
     * The work that was opened in the period, whatever became of it since.
     *
     * A report of its own because the Project Report now answers a different
     * question. That one asks "what was the business carrying in August?" and
     * so counts a project opened in May and still running; this one asks "what
     * came in during August?", which is the intake figure - and the two are
     * only the same in a month where nothing was carried over.
     *
     * Ordered by the day it arrived, because that is the thing being counted.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array<string, mixed>
     */
    private function newProjectsReport(array $period): array
    {
        $projects = $this->excludeArchived(Project::query())
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->with(['clients', 'projectTypes', 'schedules'])
            ->orderBy('created_at')
            ->orderBy('project_id')
            ->get();

        $rows = $projects
            ->map(fn (Project $project): array => [
                'reference_no' => $project->reference_no ?: '—',
                'opened_on' => $this->formatDate($project->created_at),
                'client' => $this->clientName($project),
                'client_type' => $project->clientType() ? ucfirst(mb_strtolower($project->clientType())) : '—',
                'project_types' => $project->projectTypes->pluck('type_name')->all(),
                'status_key' => $project->statusKey(),
                'status_label' => $project->shortStatusLabel(),
                'schedules' => $project->schedules
                    ->map(fn (Schedule $schedule): string => $schedule->describe())
                    ->all(),
                'quotation' => (float) $project->quotation,
            ])
            ->values();

        return [
            'sections' => [[
                'key' => 'new_projects',
                'title' => 'Projects Opened',
                'rows' => $rows,
                'summary' => [
                    ['label' => 'Projects Opened', 'value' => number_format($rows->count())],
                    ['label' => 'Total Quotation', 'value' => $this->money($this->billableTotal($rows))],
                    ...$this->statusCounts($rows),
                ],
            ]],
        ];
    }

    /**
     * One row per schedule range, clipped to the reporting period.
     *
     * A project booked twice in the month is two rows: they are two separate
     * visits, and merging them would claim the days between as booked.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array<string, mixed>
     */
    private function scheduleReport(array $period): array
    {
        $rows = $this->scheduleRowsFor($period);

        return [
            'sections' => [[
                'key' => 'schedules',
                'title' => 'Schedules',
                'rows' => $rows,
                'summary' => $this->scheduleSummaryLines($rows),
            ]],
        ];
    }

    /**
     * The technician sections, grouped by the person they belong to.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function technicianReport(array $period, array $filters): array
    {
        $kind = $filters['technician_kind'] ?? 'all';
        $technicianId = ($filters['technician_scope'] ?? 'all') === 'specific'
            ? (int) ($filters['technician_id'] ?? 0)
            : null;

        $directory = $this->technicianDirectory($technicianId);
        $sections = [];

        if (in_array($kind, ['all', 'assigned'], true)) {
            $sections[] = $this->assignedProjectsSection($period, $directory);
        }

        if (in_array($kind, ['all', 'schedule'], true)) {
            $sections[] = $this->technicianScheduleSection($period, $directory);
        }

        if (in_array($kind, ['all', 'tasks'], true)) {
            $sections[] = $this->technicianTasksSection($period, $directory);
        }

        return ['sections' => $sections];
    }

    /**
     * Which projects each technician is carrying, listed inside one row per
     * technician rather than spread over a row each.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @param  Collection<int, array{name: string, position: string}>  $directory
     * @return array<string, mixed>
     */
    private function assignedProjectsSection(array $period, Collection $directory): array
    {
        $projects = $this->periodProjects($period)->with('clients')->get()->keyBy('project_id');
        $assignments = $this->assignmentsFor($projects->keys(), $directory->keys());

        $rows = $directory
            ->map(fn (array $technician, int $id): array => $technician + [
                // One entry per project, never per schedule or per crew row.
                'projects' => collect($assignments[$id] ?? [])
                    ->map(fn (int $projectId): ?string => $projects->has($projectId)
                        ? $projects[$projectId]->reference_no.' - '.$this->clientName($projects[$projectId])
                        : null)
                    ->filter()
                    ->sort()
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $row): bool => $row['projects'] !== [])
            ->values();

        return [
            'key' => 'assigned',
            'title' => 'Assigned Projects',
            'rows' => $rows,
            'summary' => [
                ['label' => 'Total Technicians', 'value' => number_format($rows->count())],
                ['label' => 'Total Assigned Projects', 'value' => number_format(
                    $rows->sum(fn (array $row): int => count($row['projects']))
                )],
            ],
        ];
    }

    /**
     * Each technician's booked dates in the period, using the same clipping
     * and duration rules as the Schedule Report.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @param  Collection<int, array{name: string, position: string}>  $directory
     * @return array<string, mixed>
     */
    private function technicianScheduleSection(array $period, Collection $directory): array
    {
        $rows = $this->scheduleRowsFor($period);
        $assignments = $this->assignmentsFor($rows->pluck('project_id')->unique(), $directory->keys());

        $groups = $this->groupByTechnician(
            $directory,
            $assignments,
            fn (array $projectIds): Collection => $rows->whereIn('project_id', $projectIds)->values()
        );

        return [
            'key' => 'technician_schedule',
            'title' => 'Schedule',
            'groups' => $groups,
            'summary' => $this->scheduleSummaryLines(
                $groups->flatMap(fn (array $group): Collection => $group['rows'])
            ),
        ];
    }

    /**
     * Each technician's tasks falling in the period, oldest deadline first.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @param  Collection<int, array{name: string, position: string}>  $directory
     * @return array<string, mixed>
     */
    private function technicianTasksSection(array $period, Collection $directory): array
    {
        $tasks = Task::query()
            ->whereIn('technician_id', $directory->keys())
            ->whereHas('project', fn (Builder $project) => $this->excludeArchived($project))
            ->where(function (Builder $dates) use ($period): void {
                $dates
                    ->whereBetween('due_date', [$period['start']->toDateString(), $period['end']->toDateString()])
                    // A task with no deadline still belongs to the period it
                    // was started in, rather than to no period at all.
                    ->orWhere(fn (Builder $started) => $started
                        ->whereNull('due_date')
                        ->whereBetween('start_date', [$period['start']->toDateString(), $period['end']->toDateString()]));
            })
            ->with(['project.clients'])
            ->orderByRaw('due_date is null, due_date')
            ->get();

        $byTechnician = $tasks->groupBy('technician_id');

        $groups = $directory
            ->map(fn (array $technician, int $id): array => $technician + [
                'rows' => collect($byTechnician[$id] ?? [])
                    ->map(fn (Task $task): array => [
                        'reference_no' => $task->project?->reference_no ?: '—',
                        'client' => $task->project ? $this->clientName($task->project) : '—',
                        'task' => $task->task_title,
                        'start_date' => $this->formatDate($task->start_date),
                        'due_date' => $this->formatDate($task->due_date),
                        // Both from the one derivation, so the report agrees
                        // with every screen - and so its summary counts a task
                        // closed late under Finished Late rather than folding
                        // it into Completed. See TaskStatus.
                        'status_key' => $task->derivedStatus(),
                        'status_label' => $task->statusLabel(),
                    ])
                    ->values(),
            ])
            ->filter(fn (array $group): bool => $group['rows']->isNotEmpty())
            ->values();

        $all = $groups->flatMap(fn (array $group): Collection => $group['rows']);

        return [
            'key' => 'technician_tasks',
            'title' => 'Tasks',
            'groups' => $groups,
            'summary' => [
                ['label' => 'Total Tasks', 'value' => number_format($all->count())],
                ...$all
                    ->groupBy('status_label')
                    ->map(fn (Collection $rows, string $label): array => [
                        'label' => $label,
                        'value' => number_format($rows->count()),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Export internals
    // ------------------------------------------------------------------

    /**
     * One row per project that has work booked in the period, carrying every
     * range of its that overlaps.
     *
     * Two things are deliberately different here from the Project Report. The
     * rows are chosen by the period rather than the project: only ranges with
     * a date inside the window are listed. But each of those ranges is printed
     * whole - a booking running July 28 to August 1 reads as "Jul 28 - Aug 1"
     * in an August report, because that is the booking, and clipping the
     * printed dates would describe a visit that never happened.
     *
     * The duration is the other half of that: it counts only the days inside
     * the window, so the same row says "this is the booking" and "this much of
     * it was August" without either statement being untrue.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return Collection<int, array<string, mixed>>
     */
    private function scheduleRowsFor(array $period): Collection
    {
        $projects = $this->excludeArchived(Project::query())
            // Cancelled work gave its dates back. The availability checker
            // ignores those rows, the calendar does not draw them and the crew
            // reads as free on them - so counting them here as scheduled time
            // would be this page disagreeing with the rest of the system about
            // days nobody worked. The rows are kept on the project for the
            // record; they are simply not a booking any more. Cancelled
            // projects still appear in the Project Report, which is about the
            // work rather than about the calendar.
            ->where('status', '!=', 'cancelled')
            ->whereHas('schedules', fn (Builder $schedule) => $this->applyOverlap($schedule, $period))
            // Every range, not only the matching ones: resolving Overdue reads
            // the whole schedule, and a constrained load would answer it from
            // half the facts. Two queries either way, and only for the
            // projects the period already narrowed us to.
            ->with(['clients', 'schedules'])
            ->get();

        return $projects
            ->map(function (Project $project) use ($period): array {
                $matching = $project->schedules
                    ->filter(fn (Schedule $schedule): bool => $this->overlapsPeriod($schedule, $period))
                    ->sortBy(fn (Schedule $schedule): string => $schedule->startsOn()->toDateString())
                    ->values();

                $earliest = $matching->first()?->startsOn();

                return [
                    'project_id' => (int) $project->project_id,
                    'reference_no' => $project->reference_no ?: '—',
                    'client' => $this->clientName($project),
                    // Chronological, and all of them - never just the first.
                    'schedules' => $matching
                        ->map(fn (Schedule $schedule): string => $schedule->describe())
                        ->all(),
                    'entries' => $matching->count(),
                    'duration' => (int) $matching->sum(
                        fn (Schedule $schedule): int => $this->scheduledDaysInPeriod($schedule, $period)
                    ),
                    'status_key' => $project->statusKey(),
                    'status_label' => $project->shortStatusLabel(),
                    'sort' => ($earliest?->toDateString() ?? '').($project->reference_no ?? ''),
                ];
            })
            // By the earliest booking that falls in the period, so the last
            // row is the latest work the period saw.
            ->sortBy('sort')
            ->values();
    }

    /**
     * How many days of one booking fall inside the reporting period.
     *
     * The range is cut to the window before counting, so July 28 - August 1
     * contributes a single day to an August report and August 30 - September 3
     * contributes two. A partial day books hours on one date, and that date
     * overlaps or the row would not be here, so it is always one.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     */
    private function scheduledDaysInPeriod(Schedule $schedule, array $period): int
    {
        if ($schedule->isPartialDay()) {
            return 1;
        }

        $start = $schedule->startsOn()->max($period['start']->startOfDay());
        $end = $schedule->endsOn()->min($period['end']->startOfDay());

        return (int) $start->diffInDays($end) + 1;
    }

    /**
     * Whether a booking has at least one date inside the period.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     */
    private function overlapsPeriod(Schedule $schedule, array $period): bool
    {
        return $schedule->startsOn()->lte($period['end'])
            && $schedule->endsOn()->gte($period['start']->startOfDay());
    }

    /**
     * The same overlap test in SQL: it starts on or before the period ends,
     * and ends on or after the period starts.
     *
     * @param  Builder<Schedule>  $query
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return Builder<Schedule>
     */
    private function applyOverlap(Builder $query, array $period): Builder
    {
        return $query
            ->where('start_datetime', '<=', $period['end'])
            ->whereRaw('coalesce(end_datetime, start_datetime) >= ?', [$period['start']]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{label: string, value: string}>
     */
    private function scheduleSummaryLines(Collection $rows): array
    {
        return [
            // Projects, not bookings: a project booked three times in the
            // month is one scheduled project.
            ['label' => 'Total Scheduled Projects', 'value' => number_format($rows->pluck('project_id')->unique()->count())],
            // Bookings, not rows: each row now carries several.
            ['label' => 'Total Schedule Entries', 'value' => number_format($rows->sum('entries'))],
            ['label' => 'Total Scheduled Days', 'value' => number_format($rows->sum('duration'))],
        ];
    }

    /**
     * Projects that belong to the reporting period.
     *
     * A booked project belongs to the period its work falls in; a project with
     * nothing booked yet belongs to the period it was opened in, which is the
     * date the rest of the reporting already places unscheduled work by.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return Builder<Project>
     */
    private function periodProjects(array $period): Builder
    {
        return $this->excludeArchived(Project::query())
            ->where(function (Builder $outer) use ($period): void {
                $outer
                    ->whereHas('schedules', fn (Builder $schedule) => $this->applyOverlap($schedule, $period))
                    ->orWhere(fn (Builder $unbooked) => $this->applyStillOpen($unbooked, $period));
            });
    }

    /**
     * Work with no dates on it, placed by the period it was open in.
     *
     * It used to be placed by the month it was created in and only that one,
     * which meant a project opened in May and still waiting for dates in August
     * appeared on no August report at all - and a project put on hold, whose
     * future dates are released, vanished from the current period entirely. A
     * monthly report is a statement about what the business was carrying that
     * month, and unbooked work is part of it for as long as it is open.
     *
     * Open means: it existed by the end of the period, and it had not already
     * been finished or cancelled before the period began.
     *
     * @param  Builder<Project>  $query
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return Builder<Project>
     */
    private function applyStillOpen(Builder $query, array $period): Builder
    {
        return $query
            ->whereDoesntHave('schedules')
            ->where('created_at', '<=', $period['end'])
            ->where(function (Builder $open) use ($period): void {
                $open
                    // Still live now, so it was live then.
                    ->whereNotIn('status', ['completed', 'cancelled', Project::STATUS_AWAITING_CLIENT_CONFIRMATION])
                    // Or it closed, but not before this period started.
                    ->orWhere('completed_at', '>=', $period['start'])
                    ->orWhere('cancelled_at', '>=', $period['start'])
                    // Or it closed and nothing recorded when. Work finished
                    // under older rules carries no closing date, and a row is
                    // not dropped from a report on a guess about when it ended
                    // - the reason it is being excluded has to be a fact.
                    ->orWhere(fn (Builder $undated) => $undated
                        ->whereNull('completed_at')
                        ->whereNull('cancelled_at'));
            });
    }

    /**
     * Archived work, gone from every report.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    private function excludeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', false)->where('status', '!=', 'archived');
    }

    /**
     * Which projects each technician holds, as technician id => project ids.
     *
     * One flat query over the join table, so a hundred technicians cost the
     * same as one.
     *
     * @param  Collection<int, mixed>  $projectIds
     * @param  Collection<int, mixed>  $technicianIds
     * @return Collection<int, array<int, int>>
     */
    private function assignmentsFor(Collection $projectIds, Collection $technicianIds): Collection
    {
        if ($projectIds->isEmpty() || $technicianIds->isEmpty()) {
            return collect();
        }

        return DB::table('tbl_project_technicians')
            ->whereIn('project_id', $projectIds->all())
            ->whereIn('technician_id', $technicianIds->all())
            // Closed memberships are kept for the record and are not
            // assignments any more - see ProjectTechnician.
            ->whereNull('removed_at')
            ->get(['technician_id', 'project_id'])
            ->groupBy('technician_id')
            ->map(fn (Collection $rows): array => $rows
                ->pluck('project_id')
                ->map(fn ($id): int => (int) $id)
                // A project counts once for a technician however many rows
                // link them.
                ->unique()
                ->values()
                ->all());
    }

    /**
     * The technicians a report covers, keyed by id, name and position ready to
     * print. One query, whether the report is for everybody or for one person.
     *
     * @return Collection<int, array{name: string, position: string}>
     */
    private function technicianDirectory(?int $technicianId): Collection
    {
        return Technician::query()
            ->with('account')
            ->whereHas('account', fn ($query) => $query->whereIn('role', User::TECHNICIAN_ROLES))
            ->when($technicianId, fn ($query) => $query->where('technician_id', $technicianId))
            ->get()
            ->sortBy(fn (Technician $technician): string => $technician->name)
            ->mapWithKeys(fn (Technician $technician): array => [
                (int) $technician->technician_id => [
                    'technician' => $technician->name,
                    'position' => $technician->account?->roleLabel() ?? 'Technician',
                ],
            ]);
    }

    /**
     * Turn per-technician assignments into printable groups, dropping anybody
     * the period gave nothing to say about.
     *
     * @param  Collection<int, array{name: string, position: string}>  $directory
     * @param  Collection<int, array<int, int>>  $assignments
     * @return Collection<int, array<string, mixed>>
     */
    private function groupByTechnician(Collection $directory, Collection $assignments, callable $rowsFor): Collection
    {
        return $directory
            ->map(fn (array $technician, int $id): array => $technician + [
                'rows' => $rowsFor($assignments[$id] ?? []),
            ])
            ->filter(fn (array $group): bool => $group['rows']->isNotEmpty())
            ->values();
    }

    /**
     * The money a report may claim: every row it shows, minus the statuses
     * that carry no committed money however they were filtered in.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function billableTotal(Collection $rows): float
    {
        return (float) $rows
            ->reject(fn (array $row): bool => in_array($row['status_key'], self::UNBILLABLE_STATUSES, true))
            ->sum('quotation');
    }

    /**
     * How many projects the table shows under each status, in reporting order.
     *
     * Counted from the rows themselves rather than by a second query, so the
     * summary can never disagree with the table above it.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{label: string, value: string}>
     */
    private function statusCounts(Collection $rows): array
    {
        $counts = $rows->countBy('status_key');

        return collect(Project::REPORT_STATUS_ORDER)
            ->filter(fn (string $status): bool => $counts->has($status))
            ->map(fn (string $status): array => [
                'label' => self::REPORT_STATUSES[$status] ?? ucfirst($status),
                'value' => number_format($counts[$status]),
            ])
            ->values()
            ->all();
    }

    /**
     * The date a project is placed by: when its work starts, or when it was
     * opened if nothing is booked.
     */
    private function projectDate(Project $project): string
    {
        $start = $project->schedules->min('start_datetime');

        return CarbonImmutable::parse($start ?? $project->created_at)->toDateTimeString();
    }

    /**
     * The client as the rest of the application names them: the company where
     * there is one, otherwise the person.
     */
    private function clientName(Project $project): string
    {
        $client = $project->clients->first();

        return $client?->company_name ?: ($client?->fullname ?: '—');
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function sectionIsEmpty(array $section): bool
    {
        return collect($section['rows'] ?? $section['groups'] ?? [])->isEmpty();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

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
     * Narrow a query to the projects that count as live work - see
     * ACTIVE_STATUSES for why those two statuses are the whole of it.
     *
     * Stated once and joined from, so the technician and schedule charts
     * cannot drift into disagreeing about what "active" means.
     *
     * @template TQuery of \Illuminate\Database\Query\Builder|Builder<Project>
     *
     * @param  TQuery  $query
     * @param  string  $table  the projects table's name or alias in this query
     * @return TQuery
     */
    private function applyActiveProjects($query, string $table = 'tbl_projects')
    {
        return $query
            ->whereIn($table.'.status', self::ACTIVE_STATUSES)
            ->where($table.'.is_archived', false);
    }

    /**
     * The calendar days one schedule row books, both ends inclusive: Aug 20 to
     * Aug 22 is three days, and a single date - which is every partial day - is
     * one. A row with no end date is a single day, hence the coalesce.
     */
    private function scheduleDaysExpression(string $table = 'tbl_schedule'): string
    {
        return '('.$this->dayDiffExpression(
            "coalesce({$table}.end_datetime, {$table}.start_datetime)",
            "{$table}.start_datetime"
        ).' + 1)';
    }

    /**
     * Money the way the rest of the app writes it.
     */
    private function money(float $amount): string
    {
        return '₱'.number_format($amount, 2);
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
     * @param  string  $cast  int|money|decimal
     * @return array<string, mixed>
     */
    private function bucketedSeries(array $period, Collection $rows, string $label, string $cast = 'int'): array
    {
        $buckets = $this->bucketKeys($period);
        $values = [];

        foreach (array_keys($buckets) as $key) {
            $raw = $rows[$key] ?? 0;

            $values[] = match ($cast) {
                'money' => (float) $raw,
                'decimal' => round((float) $raw, 1),
                default => (int) $raw,
            };
        }

        return [
            'labels' => array_values($buckets),
            'values' => $values,
            'label' => $label,
        ];
    }

    /**
     * The same padding for a chart drawing several series against one axis, so
     * a grouped bar chart never loses a bucket one series happens to be absent
     * from.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @param  Collection<int, object>  $rows  rows of series, bucket, total
     * @param  array<string, array{0: string, 1: ?string}>  $series  key => [label, colour]
     * @return array<string, mixed>
     */
    private function bucketedDatasets(array $period, Collection $rows, array $series): array
    {
        $buckets = $this->bucketKeys($period);
        $grouped = $rows->groupBy('series');

        $datasets = [];

        foreach ($series as $key => [$label, $colour]) {
            $totals = $grouped->get($key, collect())->pluck('total', 'bucket');

            $datasets[] = [
                'label' => $label,
                'values' => collect($buckets)
                    ->keys()
                    ->map(fn (string $bucket): int => (int) ($totals[$bucket] ?? 0))
                    ->all(),
                'color' => $colour,
            ];
        }

        return [
            'labels' => array_values($buckets),
            'datasets' => $datasets,
        ];
    }

    /**
     * Every bucket in the window, as the key a grouped query returns mapped to
     * the label the axis prints.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, bucket: string}  $period
     * @return array<string, string>
     */
    private function bucketKeys(array $period): array
    {
        $bucket = $period['bucket'];

        [$keyFormat, $labelFormat] = match ($bucket) {
            'year' => ['Y', 'Y'],
            'month' => ['Y-m', 'M Y'],
            default => ['Y-m-d', 'M j'],
        };

        $cursor = match ($bucket) {
            'year' => $period['start']->startOfYear(),
            'month' => $period['start']->startOfMonth(),
            default => $period['start']->startOfDay(),
        };

        $keys = [];
        $end = $period['end'];
        $guard = 0;

        while ($cursor->lte($end) && $guard < 400) {
            $keys[$cursor->format($keyFormat)] = $cursor->format($labelFormat);

            $cursor = match ($bucket) {
                'year' => $cursor->addYear(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
            $guard++;
        }

        return $keys;
    }

    private function formatDate($value): string
    {
        return $value ? CarbonImmutable::parse($value)->format(BusinessTime::DATE) : '—';
    }
}
