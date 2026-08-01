<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TechnicianReport;
use App\Services\SystemReportService;
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
    /**
     * Statuses that may still receive a technician report. On-hold projects
     * keep their pending/ongoing status, so they are included: work paused
     * mid-way is exactly when an incident report matters.
     *
     * @var array<int, string>
     */
    private const REPORTABLE_STATUSES = ['not_yet_scheduled', 'pending', 'ongoing'];

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
    public const EXPORT_TYPES = [
        'complete' => 'Complete System Report',
        'projects' => 'Project Report',
        'technicians' => 'Technician Report',
        'schedules' => 'Schedule Report',
        'tasks' => 'Task Report',
        'quotations' => 'Quotation Report',
    ];

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
        ]);
    }

    // ------------------------------------------------------------------
    // Tab 1 - technician reports
    // ------------------------------------------------------------------

    /**
     * Filtered, searched list of every technician report.
     *
     * Filtering happens in SQL so the browser never has to hold the whole
     * table, and the eager loads keep it to a fixed number of queries.
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
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $filters = $validator->validated();

        $query = TechnicianReport::query()
            ->with(['project', 'technician.account', 'images'])
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

            $query->where(function ($outer) use ($term): void {
                $outer->where('report_title', 'like', $term)
                    ->orWhere('report_description', 'like', $term)
                    ->orWhereHas('project', fn ($q) => $q->where('name', 'like', $term)
                        ->orWhere('reference_no', 'like', $term))
                    ->orWhereHas('technician.account', fn ($q) => $q->where('name', 'like', $term));
            });
        }

        $reports = $query->orderByDesc('report_date')->orderByDesc('id')->get();

        return response()->json([
            'reports' => $reports->map(fn (TechnicianReport $report): array => $this->reportRow($report))->all(),
            'total' => $reports->count(),
        ]);
    }

    /**
     * Everything the report viewer shows, including its image gallery.
     */
    public function showTechnicianReport(TechnicianReport $report)
    {
        $report->load(['project.clients', 'technician.account', 'images']);

        return response()->json($this->reportRow($report) + [
            'description' => $report->report_description,
            'client' => $report->project?->clients->first()?->company_name
                ?: $report->project?->clients->first()?->fullname,
            'project_url' => $report->project
                ? route('super-admin.projects.show', $report->project->project_id)
                : null,
            'images' => $report->images
                ->map(fn ($image): array => [
                    'id' => $image->id,
                    'url' => asset('storage/'.$image->image_path),
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
     * dompdf cannot run JavaScript, so the browser rasterises each visible
     * chart to a PNG data URI and posts them along with the request. That is
     * what puts real graphs in the document at full canvas resolution.
     */
    public function export(Request $request, SystemReportService $reports)
    {
        $validator = Validator::make($request->all(), [
            'report_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::EXPORT_TYPES))],
            'period' => ['required', 'string', 'in:weekly,monthly,yearly,custom'],
            'start_date' => ['nullable', 'required_if:period,custom', 'date'],
            'end_date' => ['nullable', 'required_if:period,custom', 'date', 'after_or_equal:start_date'],
            'format' => ['nullable', 'string', 'in:pdf'],
            'charts' => ['nullable', 'array'],
            'charts.*' => ['nullable', 'string'],
        ], [
            'start_date.required_if' => 'A start date is required for a custom range.',
            'end_date.required_if' => 'An end date is required for a custom range.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $input = $validator->validated();
        $reportType = $input['report_type'];

        $period = $reports->resolvePeriod(
            $input['period'],
            $input['start_date'] ?? null,
            $input['end_date'] ?? null
        );

        // Only keep images that really are inline PNG data URIs.
        $charts = collect($input['charts'] ?? [])
            ->filter(fn ($value): bool => is_string($value) && str_starts_with($value, 'data:image/png;base64,'))
            ->all();

        $pdf = Pdf::loadView('super-admin.reports-pdf', [
            'reportType' => $reportType,
            'reportTitle' => self::EXPORT_TYPES[$reportType],
            'period' => $period,
            'summary' => $reports->summary($period),
            'tables' => $reports->exportTables($reportType, $period),
            'charts' => $charts,
            'generatedBy' => auth()->user()->name ?? 'Super Admin',
            'generatedAt' => CarbonImmutable::now(),
            'logoData' => $this->logoDataUri(),
            'company' => self::COMPANY,
        ])->setPaper('a4', 'portrait');

        $fileName = sprintf(
            '%s-%s.pdf',
            str_replace('_', '-', $reportType),
            CarbonImmutable::now()->format('Ymd-His')
        );

        return $pdf->download($fileName);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function reportRow(TechnicianReport $report): array
    {
        return [
            'id' => $report->id,
            'project_id' => $report->project_id,
            'reference_no' => $report->project?->reference_no ?? '—',
            'project_name' => $report->project?->name ?? 'Project removed',
            'report_title' => $report->report_title,
            'report_type' => $report->report_type,
            'type_label' => $report->typeLabel(),
            'type_badge_class' => $report->typeBadgeClass(),
            'submitted_by' => $report->technician?->name ?? 'Unknown',
            'report_date' => $report->report_date?->toDateString(),
            'report_date_label' => $report->report_date?->format('M j, Y') ?? '—',
            'image_count' => $report->images->count(),
        ];
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
