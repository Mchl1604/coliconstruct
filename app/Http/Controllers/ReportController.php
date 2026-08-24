<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SystemReportService;
use App\Support\DisplayCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Centralised reporting: every technician report in one place, plus the
 * analytical dashboard and its PDF export.
 *
 * Report creation reuses TechnicianReportController@store - this page only
 * adds the project-selection step in front of the existing form.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /**
     * Statuses that may still receive a technician report. On-hold projects
     * keep their pending/ongoing status, so they are included: work paused
     * mid-way is exactly when an incident report matters.
     *
     * @var array<int, string>
     */
    private const REPORTABLE_STATUSES = ['unscheduled', 'pending', 'ongoing'];

    /**
     * Rows per page in the technician reports table, matching the tables on
     * the Configuration page so a page of rows is the same size everywhere.
     */
    private const PER_PAGE = 10;

    /**
     * Letterhead details for the exported PDF. The single place to change
     * them; move to a settings table if they ever become editable in-app.
     *
     * @var array<string, string>
     */
    private const COMPANY = [
        'name' => 'Coliconstruct',
        'address' => 'Carmona, Cavite, Philippines',
        'system' => 'Coliconstruct Project Management System',
    ];

    /**
     * The granularities a dashboard chart can be switched between.
     *
     * @var array<int, string>
     */
    private const GRANULARITIES = [
        SystemReportService::GRANULARITY_MONTHLY,
        SystemReportService::GRANULARITY_YEARLY,
    ];

    /**
     * Export sections, keyed by the value the dialog submits.
     *
     * @var array<string, string>
     */
    public const EXPORT_TYPES = SystemReportService::EXPORT_TYPES;

    /**
     * How far either side of today a report may be asked for. Wide enough for
     * the archive and next year's bookings, narrow enough that a typo in the
     * year cannot ask the database for the year 90210.
     */
    private const YEAR_RANGE = 10;

    public function index()
    {
        // `schedules` is eager loaded because statusLabel() consults every
        // range to decide whether a project reads as Overdue.
        $filterProjects = Project::query()
            ->with('schedules')
            ->orderBy('name')
            ->get();

        $reportableProjects = $filterProjects
            ->filter(fn (Project $project): bool => $this->isReportable($project))
            ->values();

        return view('super-admin.reports', [
            'filterProjects' => $filterProjects,
            'reportableProjects' => $reportableProjects,
            'reportTypes' => TechnicianReport::TYPES,
            'exportTypes' => self::EXPORT_TYPES,
            'quotationStatuses' => SystemReportService::QUOTATION_STATUSES,
            'exportStatuses' => SystemReportService::REPORT_STATUSES,
            'exportTechnicianKinds' => SystemReportService::TECHNICIAN_REPORT_KINDS,
            'exportTechnicians' => Technician::query()
                ->with('account')
                ->whereHas('account', fn ($query) => $query->whereIn('role', User::TECHNICIAN_ROLES))
                ->get()
                ->sortBy(fn (Technician $technician): string => $technician->name)
                ->map(fn (Technician $technician): array => [
                    'id' => $technician->technician_id,
                    'name' => $technician->name,
                ])
                ->values(),
            'exportYears' => $this->exportYears(),
        ]);
    }

    /**
     * The archive: every technician report that has been filed away.
     *
     * Server-rendered rather than fetched, the same way Archived Projects and
     * Archived Accounts are - an archive is read, not worked, so a DataTable
     * over the whole list is both simpler and enough.
     *
     * Every report here stays whole: its pictures are still served, its
     * submitter is still named, and its project is still linked. Restoring is
     * offered per row and only to whoever the policy says may do it, which for
     * a lead technician is their own reports and nobody else's.
     */
    public function archivedIndex(Request $request)
    {
        $reports = TechnicianReport::query()
            ->archived()
            ->with(['project.clients', 'technician.account', 'submitter', 'images', 'archiver'])
            // Rows archived before the timestamp existed sort last rather than
            // first, which is what a null would otherwise do on some engines.
            ->orderByRaw('archived_at is null')
            ->orderByDesc('archived_at')
            ->orderByDesc('id')
            ->get();

        $user = $request->user();

        return view('super-admin.archivedReports', [
            'reports' => $reports,
            'reportPayloads' => $reports->mapWithKeys(fn (TechnicianReport $report): array => [
                $report->id => $this->archivedPayload($report, $user),
            ]),
        ]);
    }

    // ------------------------------------------------------------------
    // Tab 1 - technician reports
    // ------------------------------------------------------------------

    /**
     * Filtered, searched, paginated list of every technician report.
     *
     * Filtering and paging both happen in SQL so the browser never has to hold
     * the whole table, and the eager loads keep it to a fixed number of
     * queries: the page costs the same with ten reports or ten thousand.
     */
    public function technicianReports(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_id' => ['nullable', 'integer'],
            'report_type' => ['nullable', 'string'],
            'date_filter' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $filters = $validator->validated();

        // Archived reports are not part of this list at all. They keep every
        // filter and every column they had, but they are read on the Archived
        // Reports page - see archivedIndex().
        $query = TechnicianReport::query()
            ->active()
            ->with(['project.clients', 'technician.account', 'submitter', 'images'])
            ->when(! empty($filters['project_id']), fn ($q) => $q->where('project_id', $filters['project_id']))
            ->when(
                ! empty($filters['report_type']) && $filters['report_type'] !== 'all',
                fn ($q) => $q->where('report_type', $filters['report_type'])
            );

        [$from, $to] = $this->resolveDateFilter(
            $filters['date_filter'] ?? 'all',
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null
        );

        if ($from && $to) {
            // whereDate, not whereBetween: report_date is written with a
            // midnight time component, which a bare string range would miss.
            $query->whereDate('report_date', '>=', $from->toDateString())
                ->whereDate('report_date', '<=', $to->toDateString());
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';

            // The table prints RPT-0007, so that is what somebody copies into
            // the search box - null when the term is anything else, which
            // leaves the key column out of the query entirely.
            $searchedId = DisplayCode::toId(DisplayCode::REPORT, $filters['search']);

            $query->where(function ($outer) use ($term, $searchedId): void {
                $outer->where('report_title', 'like', $term)
                    ->orWhere('report_description', 'like', $term)
                    ->orWhereHas('project', fn ($q) => $q->where('name', 'like', $term)
                        ->orWhere('reference_no', 'like', $term))
                    ->orWhereHas('technician.account', fn ($q) => $q->where('name', 'like', $term));

                if ($searchedId !== null) {
                    $outer->orWhere('id', $searchedId);
                }
            });
        }

        // Ordered by id as well as date: report_date carries no time, so a
        // day's reports would otherwise come back in whatever order the
        // database felt like - and an unstable order across pages is how a
        // row gets shown twice and another never at all.
        $page = $query
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'reports' => collect($page->items())
                ->map(fn (TechnicianReport $report): array => $this->reportRow($report, $request->user()))
                ->all(),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    /**
     * Everything the report viewer shows, including its image gallery.
     */
    public function showTechnicianReport(Request $request, TechnicianReport $report)
    {
        $report->load(['project.clients', 'technician.account', 'images', 'archiver']);

        return response()->json($this->reportRow($report, $request->user()) + [
            // An archived report is still read in full; the viewer says so
            // rather than pretending otherwise.
            'archived_at_label' => $report->archived_at?->format('M j, Y') ?? '—',
            'archived_by' => $report->archiver?->fullName() ?? '—',
            'can_restore' => (bool) $request->user()?->can('restore', $report),
            'description' => $report->report_description,
            'client' => $report->project?->clients->first()?->company_name
                ?: $report->project?->clients->first()?->fullname,
            'project_url' => $report->project
                ? route('super-admin.projects.show', $report->project->project_id)
                : null,
            'images' => $report->images
                ->map(fn ($image): array => [
                    'id' => $image->id,
                    'url' => $image->url(),
                ])
                ->all(),
        ]);
    }

    /**
     * Projects the Create Report flow may target, checked here as well as in
     * the dropdown so a stale page can't post a finished project.
     */
    public function reportableProjects()
    {
        $projects = Project::query()
            ->with('schedules')
            ->whereIn('status', self::REPORTABLE_STATUSES)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get();

        return response()->json([
            'projects' => $projects->map(fn (Project $project): array => [
                'project_id' => $project->project_id,
                'reference_no' => $project->reference_no,
                'name' => $project->name,
                'status_label' => $project->statusLabel(),
            ])->values()->all(),
        ]);
    }

    // ------------------------------------------------------------------
    // Tab 2 - system reports
    // ------------------------------------------------------------------

    /**
     * Every chart at once, each at whichever granularity the page currently
     * has it switched to. One request when the tab is opened; after that a
     * toggle only refetches its own chart.
     */
    public function systemReports(Request $request, SystemReportService $reports)
    {
        $validator = Validator::make($request->all(), [
            'granularities' => ['nullable', 'array'],
            'granularities.*' => ['nullable', 'string', 'in:'.implode(',', self::GRANULARITIES)],
            'quotation_status' => ['nullable', 'string', 'in:'.implode(',', array_keys(SystemReportService::QUOTATION_STATUSES))],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $filters = $validator->validated();

        return response()->json([
            'charts' => $reports->charts(
                $filters['granularities'] ?? [],
                $filters['quotation_status'] ?? null
            ),
        ]);
    }

    /**
     * One chart, so flipping its Monthly/Yearly toggle - or the quotation
     * status filter - costs a single query set rather than the whole page.
     */
    public function systemChart(Request $request, SystemReportService $reports)
    {
        $validator = Validator::make($request->all(), [
            'chart' => ['required', 'string', 'in:'.implode(',', SystemReportService::CHART_KEYS)],
            'granularity' => ['nullable', 'string', 'in:'.implode(',', self::GRANULARITIES)],
            'quotation_status' => ['nullable', 'string', 'in:'.implode(',', array_keys(SystemReportService::QUOTATION_STATUSES))],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $input = $validator->validated();

        return response()->json([
            'chart' => $input['chart'],
            'data' => $reports->chart(
                $input['chart'],
                $input['granularity'] ?? null,
                $input['quotation_status'] ?? null
            ),
        ]);
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    /**
     * Build the PDF.
     *
     * The report is assembled entirely on the server: the browser sends the
     * filters and gets a document back. Nothing is rasterised or posted from
     * the page, so what the PDF says never depends on what happened to be
     * drawn on screen when the button was pressed.
     */
    public function export(Request $request, SystemReportService $reports)
    {
        $validator = $this->exportValidator($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $input = $validator->validated();
        $reportType = $input['report_type'];

        if ($message = $this->irrelevantFilterMessage($reportType, $input)) {
            return response()->json(['error' => $message], 422);
        }

        $period = $reports->resolveExportPeriod(
            $input['period'],
            isset($input['month']) ? (int) $input['month'] : null,
            (int) $input['year']
        );

        $report = $reports->exportReport($reportType, $period, $input);

        $pdf = Pdf::loadView('super-admin.reports-pdf', [
            'report' => $report,
            'reportTitle' => $report['title'],
            'period' => $period,
            'appliedFilters' => $this->appliedFilters($reportType, $input),
            'generatedBy' => auth()->user()->name ?? 'Super Admin',
            'generatedAt' => CarbonImmutable::now(),
            'logoData' => $this->logoDataUri(),
            'company' => self::COMPANY,
        ])->setPaper('a4', 'landscape');

        $fileName = sprintf(
            '%s-%s.pdf',
            str_replace('_', '-', $reportType),
            CarbonImmutable::now()->format('Ymd-His')
        );

        $this->activityLogger->record(
            ActivityLog::REPORT_EXPORTED,
            null,
            sprintf(
                'Exported the %s as PDF for %s.',
                self::EXPORT_TYPES[$reportType],
                $period['label'] ?? 'the selected period'
            )
        );

        return $pdf->download($fileName);
    }

    // ------------------------------------------------------------------
    // Export internals
    // ------------------------------------------------------------------

    /**
     * The years the dialog offers, newest first: back far enough to reach the
     * archive, forward far enough to report on work already booked.
     *
     * @return array<int, int>
     */
    private function exportYears(): array
    {
        $thisYear = (int) CarbonImmutable::today()->format('Y');

        return range($thisYear + 1, $thisYear - self::YEAR_RANGE);
    }

    /**
     * What a valid export request looks like.
     *
     * Every filter is checked here rather than trusted from the dialog: the
     * dialog hides what does not apply, but a request can be made without it.
     */
    private function exportValidator(Request $request): \Illuminate\Validation\Validator
    {
        $thisYear = (int) CarbonImmutable::today()->format('Y');

        return Validator::make($request->all(), [
            'report_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::EXPORT_TYPES))],
            'period' => ['required', 'string', 'in:'.SystemReportService::PERIOD_MONTHLY.','.SystemReportService::PERIOD_YEARLY],
            'month' => ['nullable', 'required_if:period,'.SystemReportService::PERIOD_MONTHLY, 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:'.($thisYear - self::YEAR_RANGE).','.($thisYear + self::YEAR_RANGE)],
            'format' => ['nullable', 'string', 'in:pdf'],

            // Project Report only. Archived is absent from REPORT_STATUSES, so
            // asking for it fails here rather than returning a blank page.
            'project_status' => [
                'nullable',
                'string',
                'in:'.implode(',', array_keys(SystemReportService::REPORT_STATUSES)),
            ],

            // Technician Report only.
            'technician_scope' => ['nullable', 'string', 'in:all,specific'],
            'technician_id' => [
                'nullable',
                'required_if:technician_scope,specific',
                'integer',
                'exists:tbl_technicians,technician_id',
            ],
            'technician_kind' => [
                'nullable',
                'string',
                'in:'.implode(',', array_keys(SystemReportService::TECHNICIAN_REPORT_KINDS)),
            ],
        ], [
            'month.required_if' => 'Choose a month for a monthly report.',
            'year.required' => 'Choose a year.',
            'year.between' => 'Choose a year within ten years of today.',
            'project_status.in' => 'That project status cannot be reported on.',
            'technician_id.required_if' => 'Choose which technician to report on.',
            'technician_id.exists' => 'That technician no longer exists.',
        ]);
    }

    /**
     * Refuse a filter that belongs to a different report rather than quietly
     * ignoring it - a Project Status sent with a Schedule Report means the
     * caller believes it will be applied, and it will not be.
     *
     * @param  array<string, mixed>  $input
     */
    private function irrelevantFilterMessage(string $reportType, array $input): ?string
    {
        $allowed = match ($reportType) {
            'project' => ['project_status'],
            'technician' => ['technician_scope', 'technician_id', 'technician_kind'],
            // The New Projects Report counts intake, and everything opened in
            // the period is in it whatever became of it since - so there is
            // nothing to narrow it by.
            default => [],
        };

        foreach (['project_status', 'technician_scope', 'technician_id', 'technician_kind'] as $filter) {
            if (! in_array($filter, $allowed, true) && filled($input[$filter] ?? null)) {
                return sprintf(
                    'The %s filter does not apply to the %s.',
                    str_replace('_', ' ', $filter),
                    self::EXPORT_TYPES[$reportType]
                );
            }
        }

        return null;
    }

    /**
     * The filters worth printing on the document, so a reader can tell what
     * they are holding without being told separately.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function appliedFilters(string $reportType, array $input): array
    {
        if ($reportType === 'project') {
            $status = $input['project_status'] ?? 'all';

            return ['Project Status' => SystemReportService::REPORT_STATUSES[$status] ?? 'All Statuses'];
        }

        if ($reportType !== 'technician') {
            return [];
        }

        $kind = $input['technician_kind'] ?? 'all';

        $technician = ($input['technician_scope'] ?? 'all') === 'specific'
            ? Technician::with('account')->find($input['technician_id'] ?? 0)?->name ?? 'Unknown technician'
            : 'All Technicians';

        return [
            'Technician' => $technician,
            'Kind of Report' => SystemReportService::TECHNICIAN_REPORT_KINDS[$kind] ?? 'All',
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function reportRow(TechnicianReport $report, ?User $user = null): array
    {
        return [
            'id' => $report->id,
            'display_code' => $report->displayCode(),
            'project_id' => $report->project_id,
            'reference_no' => $report->project?->reference_no ?? '—',
            'project_name' => $report->project?->name ?? 'Project removed',
            'client' => $this->clientNameFor($report),
            'report_title' => $report->report_title,
            'report_type' => $report->report_type,
            'type_label' => $report->typeLabel(),
            'type_badge_class' => $report->typeBadgeClass(),
            'type_accent_class' => $report->typeAccentClass(),
            'submitted_by' => $report->submitterName(),
            'submitted_by_avatar' => $report->submitterAvatarUrl(),
            'report_date' => $report->report_date?->toDateString(),
            'report_date_label' => $report->report_date?->format('M j, Y') ?? '—',
            'image_count' => $report->images->count(),
            'is_archived' => $report->isArchived(),
            // What the row is allowed to offer. The endpoint asks the same
            // policy again before it does anything, so this only decides
            // whether a button is drawn - never whether it works.
            'can_archive' => (bool) $user?->can('archive', $report),
        ];
    }

    /**
     * One archived report, as the archive page's viewer reads it.
     *
     * @return array<string, mixed>
     */
    private function archivedPayload(TechnicianReport $report, ?User $user): array
    {
        return $this->reportRow($report, $user) + [
            'description' => $report->report_description,
            'can_restore' => (bool) $user?->can('restore', $report),
            'archived_at_label' => $report->archived_at?->format('M j, Y') ?? '—',
            'archived_by' => $report->archiver?->fullName() ?? '—',
            'project_url' => $report->project
                ? route('super-admin.projects.show', $report->project->project_id)
                : null,
            'images' => $report->images
                ->map(fn ($image): array => [
                    'id' => $image->id,
                    'url' => $image->url(),
                ])
                ->all(),
        ];
    }

    /**
     * The client a report's project belongs to: the company where there is
     * one, otherwise the person.
     */
    private function clientNameFor(TechnicianReport $report): string
    {
        $client = $report->project?->clients->first();

        return $client?->company_name
            ?: ($client?->fullname ?: '—');
    }

    private function isReportable(Project $project): bool
    {
        return in_array($project->status, self::REPORTABLE_STATUSES, true)
            && ! $project->isArchived();
    }

    /**
     * Translate the date filter into a concrete window.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function resolveDateFilter(string $filter, ?string $startDate, ?string $endDate): array
    {
        $today = CarbonImmutable::today();

        return match ($filter) {
            'today' => [$today, $today->endOfDay()],
            'week' => [$today->startOfWeek(), $today->endOfWeek()->endOfDay()],
            'month' => [$today->startOfMonth(), $today->endOfMonth()->endOfDay()],
            'custom' => $startDate && $endDate
                ? (function () use ($startDate, $endDate): array {
                    $from = CarbonImmutable::parse($startDate)->startOfDay();
                    $to = CarbonImmutable::parse($endDate)->endOfDay();

                    return $from->lte($to) ? [$from, $to] : [$to->startOfDay(), $from->endOfDay()];
                })()
                : [null, null],
            default => [null, null],
        };
    }

    /**
     * dompdf cannot fetch over HTTP, so the logo has to be inlined.
     */
    private function logoDataUri(): ?string
    {
        $path = public_path('img/coliconstructlogor.png');

        if (! is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }
}
