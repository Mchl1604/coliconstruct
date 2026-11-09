<?php

namespace App\Http\Controllers;

use App\Models\IncidentReport;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Policies\ProjectPolicy;
use App\Services\IncidentReporting;
use App\Support\BusinessTime;
use App\Support\ReportFormContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Incident reports: the structured form a lead fills in from the technician
 * portal and a Super Admin from the office's, and the office's review on the
 * report's page. Who may file is
 * IncidentReporting's decision, asked again on every submission.
 */
class IncidentReportController extends Controller
{
    public function __construct(
        private readonly IncidentReporting $incidents,
        private readonly ProjectPolicy $projects,
    ) {}

    /**
     * Choose the project; once chosen, the form - its tasks and team
     * offered as the choices they are.
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

        $project = $projects->firstWhere('project_id', $request->integer('project'));

        return view('technician.incident-report-create', [
            'portal' => ReportFormContext::portal($request),
            'fromProject' => ReportFormContext::cameFromProject($request),
            'backUrl' => ReportFormContext::backUrl($request, $project),
            'projects' => $projects,
            'project' => $project,
            'team' => $project
                ? ProjectTechnician::query()
                    ->with('technician.account')
                    ->where('project_id', $project->project_id)
                    ->get()
                    ->map(fn (ProjectTechnician $member) => $member->technician)
                    ->filter()
                    ->unique('technician_id')
                    ->values()
                : collect(),
            'today' => BusinessTime::today()->toDateString(),
            'now' => BusinessTime::now()->format('H:i'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $project = Project::query()->find($request->integer('project_id'));

        if ($project === null) {
            return back()->withInput()->with('error', 'Choose a project.');
        }

        $validator = Validator::make($request->all(), [
            'incident_type' => ['required', Rule::in(array_keys(IncidentReport::TYPES))],
            'occurred_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessTime::today()->toDateString()],
            'occurred_time' => ['required', 'date_format:H:i'],
            'location' => ['required', Rule::in(array_keys(IncidentReport::LOCATIONS))],
            'description' => ['required', 'string', 'max:5000'],
            'injury_body_part' => ['nullable', 'required_if:incident_type,injury', Rule::in(array_keys(IncidentReport::BODY_PARTS))],
            'injury_type' => ['nullable', 'required_if:incident_type,injury', Rule::in(array_keys(IncidentReport::INJURY_TYPES))],
            'injury_treatment' => ['nullable', 'required_if:incident_type,injury', Rule::in(array_keys(IncidentReport::TREATMENTS))],
            'refrigerant_type' => ['nullable', 'required_if:incident_type,refrigerant_leak', Rule::in(array_keys(IncidentReport::REFRIGERANTS))],
            'refrigerant_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            ...$this->otherRules($request),
            'persons' => ['nullable', 'array', 'max:30'],
            'persons.*.technician_id' => ['nullable', 'integer'],
            'persons.*.name' => ['nullable', 'string', 'max:150'],
            'persons.*.person_type' => ['required', Rule::in(array_keys(IncidentReport::PERSON_TYPES))],
            'persons.*.involvement' => ['required', Rule::in(array_keys(IncidentReport::INVOLVEMENTS))],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'incident_type.required' => 'Choose the type of incident.',
            'occurred_date.required' => 'Say when it happened.',
            'occurred_date.before_or_equal' => 'It cannot have happened in the future.',
            'occurred_time.required' => 'Say what time it happened.',
            'location.required' => 'Choose where it happened.',
            'description.required' => 'Describe what happened.',
            'injury_body_part.required_if' => 'Choose the part of the body hurt.',
            'injury_type.required_if' => 'Choose the kind of injury.',
            'injury_treatment.required_if' => 'Choose the treatment given.',
            'refrigerant_type.required_if' => 'Choose the refrigerant released.',
            '*_other.required' => 'Please specify.',
            '*_other.max' => 'Please keep it short.',
            'persons.*.person_type.required' => 'Say who each person is.',
            'persons.*.involvement.required' => 'Say how each person was involved.',
            'images.max' => 'At most 5 photos.',
            'images.*.image' => 'Photos must be JPG or PNG.',
            'images.*.mimes' => 'Photos must be JPG or PNG.',
            'images.*.max' => 'Each photo must be under 5 MB.',
        ]);

        if ($validator->fails()) {
            return back()->withInput()->with('error', $validator->errors()->first());
        }

        $data = $validator->validated();

        try {
            $this->incidents->file(
                $project,
                $data,
                array_values($data['persons'] ?? []),
                $request->file('images') ?? [],
                $request->user()
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->to(ReportFormContext::backUrl($request, $project))
            ->with('success', 'Incident report filed.');
    }

    /**
     * The office's review: Unresolved or Resolved, with notes.
     */
    public function updateStatus(Request $request, IncidentReport $incidentReport): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in(array_keys(IncidentReport::STATUSES))],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'status.required' => 'Choose a status.',
            'status.in' => 'Choose a status.',
        ]);

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first());
        }

        try {
            $this->incidents->review(
                $incidentReport,
                $request->string('status')->toString(),
                $request->input('resolution_notes'),
                $request->user()
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Incident updated.');
    }

    /**
     * Choosing Other asks what, for the lists that offer it - and only where
     * the choice counts: an injury's for an injury, a refrigerant's for a leak.
     *
     * @return array<string, array<int, mixed>>
     */
    private function otherRules(Request $request): array
    {
        $rules = [];

        foreach (IncidentReport::OTHER_FIELDS as $field => $onlyForType) {
            $rules[$field.'_other'] = [
                Rule::requiredIf(fn (): bool => $request->input($field) === 'other'
                    && ($onlyForType === null || $request->input('incident_type') === $onlyForType)),
                'nullable',
                'string',
                'max:150',
            ];
        }

        return $rules;
    }
}
