<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TechnicianReport;
use App\Policies\ProjectPolicy;
use App\Services\ProgressReportBuilder;
use App\Support\BusinessTime;
use App\Support\ReportFormContext;
use App\Support\RoleAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * A lead's generated daily progress report, and the printable page every
 * structured report is read on.
 *
 * Generating is a lead's, in the technician portal, and a Super Admin's on the
 * same form in the office's: choose a project, read what the system gathered
 * today, add remarks, submit. Who may, and for which day, is
 * ProgressReportBuilder's decision.
 */
class ProgressReportController extends Controller
{
    public function __construct(
        private readonly ProgressReportBuilder $builder,
        private readonly ProjectPolicy $projects,
    ) {}

    /**
     * Choose the project and the day; once both are chosen, the gathered
     * report with the lead's fields beneath it.
     */
    public function create(Request $request): View
    {
        $user = $request->user();

        $projects = Project::query()
            ->when($user->needsTechnicianRecord(), fn ($query) => $query
                ->whereHas('projectTechnicians', fn ($team) => $team->where('technician_id', $user->technicianRecord()->technician_id)))
            ->where('is_archived', false)
            ->orderByDesc('project_id')
            ->get()
            ->filter(fn (Project $project): bool => $this->projects->reportsOn($user, $project))
            ->values();

        // Always today: a report describes the day it is filed on.
        $date = BusinessTime::today()->toDateString();
        $project = $projects->firstWhere('project_id', $request->integer('project'));

        $refusal = $project ? $this->builder->refusal($user, $project, $date) : null;

        return view('technician.progress-report-create', [
            'portal' => ReportFormContext::portal($request),
            'fromProject' => ReportFormContext::cameFromProject($request),
            'backUrl' => ReportFormContext::backUrl($request, $project),
            'projects' => $projects,
            'project' => $project,
            'date' => $date,
            'today' => BusinessTime::today()->toDateString(),
            'refusal' => $refusal,
            'snapshot' => $project && $refusal === null ? $this->builder->gather($project, $date) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'project_id' => ['required', 'integer'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'issues' => ['nullable', 'string', 'max:2000'],
            'next_plan' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'project_id.required' => 'Choose a project.',
            'images.max' => 'At most 5 photos.',
            'images.*.image' => 'Photos must be JPG or PNG.',
            'images.*.mimes' => 'Photos must be JPG or PNG.',
            'images.*.max' => 'Each photo must be under 5 MB.',
        ]);

        if ($validator->fails()) {
            return back()->withInput()->with('error', $validator->errors()->first());
        }

        $data = $validator->validated();
        $project = Project::query()->find($data['project_id']);

        if ($project === null) {
            return back()->with('error', 'That project no longer exists.');
        }

        try {
            $this->builder->file($project, BusinessTime::today()->toDateString(), $data, $request->file('images') ?? [], $request->user());
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->to(ReportFormContext::backUrl($request, $project))
            ->with('success', 'Progress report filed.');
    }

    /**
     * The whole report on one printable page: a generated progress report,
     * or a structured incident report.
     *
     * Read by the office's scheduling side and by anyone on the project's
     * crew - the same audience the report's photographs have.
     */
    public function show(Request $request, TechnicianReport $report): View
    {
        $user = $request->user();
        $project = $report->project;

        $allowed = $user->canReach(RoleAccess::SCHEDULING)
            || ($project !== null && $user->needsTechnicianRecord() && $this->projects->viewAssigned($user, $project));

        abort_unless($allowed && ($report->isGenerated() || $report->hasIncidentDetails()), 404);

        return view('reports.document', [
            'report' => $report->load(['project.client', 'images', 'incident.persons.technician.account']),
            'backUrl' => url()->previous(),
            'canReview' => $user->canReach(RoleAccess::SCHEDULING) && $report->hasIncidentDetails(),
        ]);
    }
}
