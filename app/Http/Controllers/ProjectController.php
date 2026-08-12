<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectCompletionPhoto;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\ProjectCompletion;
use App\Services\ProjectEmails;
use App\Services\ProjectTeamCandidates;
use App\Services\TechnicianAvailabilityService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Throwable;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
        private readonly ProjectEmails $clientEmails
    ) {}

    public function index()
    {
        $this->updateStatus(); // Call the function to update project statuses

        // `schedules` is eager loaded because isOverdue() reads every range;
        // without it the status column would fire a query per row.
        $projects = Project::query()
            ->with(['clients', 'documents', 'schedule', 'schedules', 'projectTypes', 'projectTechnicians.technician'])
            ->where('is_archived', false)
            ->where('status', '!=', 'archived')
            ->orderBy('project_id', 'desc')
            ->get();

        $overdueCount = $projects->filter->isOverdue()->count();

        return view('super-admin.projects', compact('projects', 'overdueCount'));
    }

    public function archivedIndex()
    {
        // projectTypes and archivedByUser are the two columns the table gained
        // beyond the project's own row; loading them here is what keeps the
        // page to a fixed number of queries however long the archive gets.
        $projects = Project::query()
            ->with(['clients', 'documents', 'projectTypes', 'archivedByUser'])
            ->where(function ($query): void {
                $query->where('is_archived', true)
                    ->orWhere('status', 'archived');
            })
            ->orderBy('archived_at', 'desc')
            ->get();

        return view('super-admin.archivedProjects', compact('projects'));
    }

    public function create()
    {
        $projectTypes = ProjectType::query()->orderBy('type_name', 'asc')->get();
        $technicians = Technician::query()
            ->with(['account', 'skills'])
            ->whereHas('account', function ($query): void {
                $query->whereIn('role', ['technician', 'lead_technician']);
            })
            ->orderBy('technician_id')
            ->get();

        $selectedProjectTypes = $this->defaultSelectedProjectTypes($projectTypes);
        $suggestedTechnicians = $this->suggestTechnicians($technicians, $selectedProjectTypes);
        $otherTechnicians = $technicians
            ->reject(function (Technician $technician) use ($suggestedTechnicians): bool {
                return $suggestedTechnicians->contains('technician_id', $technician->technician_id);
            })
            ->values();

        $technicianSchedules = $this->buildTechnicianSchedules();
        // Stated by the model so the dropdowns and the server agree on which
        // hours exist without the list being written out twice.
        $workingHours = Schedule::workingHourOptions();

        return view('super-admin.createProject', compact(
            'projectTypes',
            'technicians',
            'suggestedTechnicians',
            'otherTechnicians',
            'technicianSchedules',
            'workingHours'
        ));
    }

    public function store(StoreProjectRequest $request)
    {
        $validated = $request->validated();
        // Worked out by the request during validation, so the mode, the dates
        // and the times are read once and interpreted once.
        $scheduleEntry = $request->scheduleEntry();
        $created = null;

        try {

            DB::transaction(function () use ($validated, $request, $scheduleEntry, &$created): void {

                $project = Project::create([
                    'name' => $this->resolveProjectName($validated),
                    'status' => 'not_yet_scheduled',
                    'quotation' => $validated['quotation_amount'],
                    'address' => $validated['project_address'],
                    'description' => $validated['project_description'],
                ]);

                $project->forceFill([
                    'reference_no' => $this->generateReferenceNumber($project->project_id),
                ])->save();

                Client::create([
                    'project_id' => $project->project_id,
                    'client_type' => $validated['client_type'],
                    'company_name' => $this->inputCompanyName($validated),
                    'surname' => $validated['surname'] ?? null,
                    'firstname' => $validated['firstname'],
                    'middlename' => $validated['middle_name'] ?? null,
                    'fullname' => trim(collect([
                        $validated['firstname'],
                        $validated['middle_name'] ?? null,
                        $validated['surname'] ?? null,
                    ])->filter()->implode(' ')),
                    'email_address' => $validated['client_email'],
                    'contact_number' => $validated['client_phone'],
                ]);

                $projectTypeIds = ProjectType::query()
                    ->get()
                    ->filter(fn (ProjectType $projectType) => in_array($projectType->type_name, $validated['project_types'], true))
                    ->pluck('type_id')
                    ->all();

                $project->projectTypes()->sync($projectTypeIds);

                $selectedTechnicianIds = collect([
                    $validated['lead_tech'],
                    ...$validated['technicians'],
                ])
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values();

                $projectTechnicians = $selectedTechnicianIds->map(function (int $technicianId) use ($project) {
                    return ProjectTechnician::create([
                        'project_id' => $project->project_id,
                        'technician_id' => $technicianId,
                    ]);
                });

                $schedule = Schedule::create([
                    'project_id' => $project->project_id,
                    'start_datetime' => $scheduleEntry['start'],
                    'end_datetime' => $scheduleEntry['end'],
                    'scheduling_mode' => $scheduleEntry['mode'],
                    'status' => 'scheduled',
                    'remarks' => 'Created from project wizard',
                ]);

                $projectTechnicians->each(function ($projectTechnician) use ($schedule) {
                    ScheduleTechnician::create([
                        'schedule_id' => $schedule->schedule_id,
                        'project_technician_id' => $projectTechnician->project_technician_id,
                    ]);
                });

                $this->promoteStatusAfterScheduling($project);

                $this->storeDocument(
                    $request->file('assessment_report'),
                    $project->project_id,
                    'assessment'
                );

                $this->storeDocument(
                    $request->file('approved_quotation'),
                    $project->project_id,
                    'quotation'
                );

                if ($validated['client_type'] !== 'Residential' && $request->hasFile('contract')) {
                    $this->storeDocument(
                        $request->file('contract'),
                        $project->project_id,
                        'contract'
                    );
                }

                $created = $project;
            });

            // The closure always sets this or throws, so reaching here without
            // it is impossible - but saying so narrows the type for everything
            // below, which all reads the project's fields.
            if (! $created instanceof Project) {
                throw new RuntimeException('The project was not created.');
            }

            // The project exists from here on. Everything below is follow-up -
            // the audit trail, the bells, the client's welcome - and none of it
            // may turn a project that was created into one the interface
            // reports as failed. announceNewProject() therefore swallows its
            // own faults rather than letting them reach the catch below.
            $this->announceNewProject($created, count($validated['technicians']));

            return redirect()
                ->route('super-admin.projects')
                ->with('success', 'Project created successfully.');
        } catch (Throwable $exception) {
            // Nothing reached the tables: the whole of the work above runs in
            // one transaction, so a failure anywhere in it leaves no
            // half-made project behind.
            //
            // The person is sent back to the wizard rather than to the
            // Projects list, with everything they typed still in it. Losing
            // four steps of data entry to a database hiccup is a worse failure
            // than the hiccup.
            report($exception);

            return back()
                ->withInput()
                ->with('error', $this->creationFailureMessage($exception));
        }
    }

    /**
     * Everything that happens because a project was created, as opposed to
     * everything that creates it.
     *
     * Kept apart from store() and deliberately unable to fail it. A mail
     * server being down, or a notification row refusing to write, is not a
     * reason to tell an administrator their project was not created when it
     * was - and the transaction has already committed by the time any of this
     * runs, so there is nothing left to roll back either way.
     */
    private function announceNewProject(Project $project, int $supportingTechnicianCount): void
    {
        try {
            $this->activityLogger->record(
                ActivityLog::PROJECT_CREATED,
                null,
                sprintf("Created project '%s' for %s.", $project->reference_no, $project->name),
                $project
            );

            $this->activityLogger->record(
                ActivityLog::LEAD_TECHNICIAN_ASSIGNED,
                null,
                sprintf(
                    "Assigned a lead technician and %d supporting technician(s) to '%s'.",
                    $supportingTechnicianCount,
                    $project->reference_no
                ),
                $project
            );

            $this->notifications->projectCreated($project);

            $team = $this->notifications->projectTeam($project);

            if ($lead = $this->notifications->projectLead($project)) {
                $this->notifications->leadAssignedToProject($project, $lead);
            }

            $this->notifications->techniciansAssignedToProject(
                $project,
                $team->reject(fn ($user) => $user->role === User::ROLE_LEAD_TECHNICIAN)
            );

            // The client is welcomed to the address the project was booked
            // under, whether or not they have an account yet - registering
            // with that same address is what connects the two.
            $this->clientEmails->projectCreated($project);

            // The documents that arrived with the wizard are the client's to
            // read, so they are told each one is available.
            foreach (['assessment', 'quotation', 'contract'] as $documentType) {
                if ($project->documents()->where('document_type', $documentType)->exists()) {
                    $this->clientEmails->documentUploaded($project, $documentType);
                }
            }
        } catch (Throwable $exception) {
            // Worth knowing about, but not worth telling an administrator
            // their project failed when it is sitting in the table.
            report($exception);
        }
    }

    /**
     * What to put in the toast when creating a project fails.
     *
     * A RuntimeException is something this module raised deliberately, and its
     * message is written for a person to read. Anything else is a fault whose
     * message belongs in the log: a raw SQL error tells an administrator
     * nothing useful and describes the shape of the database to anyone
     * watching.
     *
     * PDOException is excluded by name because it *extends* RuntimeException -
     * which makes a failed query look like a deliberate message unless it is
     * ruled out here. That is precisely the case that would leak.
     *
     * In debug mode the detail is appended regardless, because that is what
     * debug mode is for.
     */
    private function creationFailureMessage(Throwable $exception): string
    {
        $isDeliberate = $exception instanceof RuntimeException
            && ! $exception instanceof PDOException;

        if ($isDeliberate) {
            return $exception->getMessage();
        }

        $message = 'The project could not be created. Nothing was saved, so you can correct the details and try again.';

        return config('app.debug')
            ? $message.' ('.$exception->getMessage().')'
            : $message;
    }

    private function defaultSelectedProjectTypes(Collection $projectTypes): array
    {
        if ($projectTypes->isEmpty()) {
            return ['Aircon Installation'];
        }

        return [$projectTypes->first()->type_name];
    }

    private function suggestTechnicians(Collection $technicians, array $selectedProjectTypes): Collection
    {
        if ($selectedProjectTypes === []) {
            return collect();
        }

        return $technicians
            ->map(function (Technician $technician) use ($selectedProjectTypes): Technician {
                $matchCount = $technician->skills
                    ->pluck('skill_name')
                    ->intersect($selectedProjectTypes)
                    ->count();

                $technician->setAttribute('match_count', $matchCount);

                return $technician;
            })
            ->filter(function (Technician $technician): bool {
                return (int) $technician->getAttribute('match_count') > 0;
            })
            ->sortByDesc('match_count')
            ->values();
    }

    /**
     * Every busy range per technician, for the wizard's client-side screening.
     *
     * `start` and `end` stay the dates they always were, so a whole-day
     * booking reads exactly as before. A partial day carries its hours as
     * well, which is what lets the browser offer a date where somebody is
     * booked for part of the day but free for the rest of it.
     *
     * @return array<int, array<int, array{start: string, end: string, mode: string, start_time: ?string, end_time: ?string}>>
     */
    private function buildTechnicianSchedules(): array
    {
        $schedules = Schedule::query()
            ->whereHas('project', function ($query): void {
                $query->whereIn('status', ['pending', 'ongoing']);
            })
            ->with([
                'scheduleTechnicians:schedule_technician_id,schedule_id,project_technician_id',
                'scheduleTechnicians.projectTechnician:project_technician_id,technician_id',
            ])
            ->get(['schedule_id', 'project_id', 'start_datetime', 'end_datetime', 'scheduling_mode']);

        $scheduleMap = [];

        foreach ($schedules as $schedule) {
            $busyRange = $this->busyRangePayload($schedule);

            foreach ($schedule->scheduleTechnicians as $scheduleTechnician) {
                $technicianId = $scheduleTechnician->projectTechnician?->technician_id;

                if (! $technicianId) {
                    continue;
                }

                $scheduleMap[$technicianId][] = $busyRange;
            }
        }

        return $scheduleMap;
    }

    /**
     * One busy range as the browser needs to see it.
     *
     * @return array{start: string, end: string, mode: string, start_time: ?string, end_time: ?string}
     */
    private function busyRangePayload(Schedule $schedule): array
    {
        $start = CarbonImmutable::parse($schedule->start_datetime);
        $end = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime);
        $isPartialDay = $schedule->isPartialDay();

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'mode' => $schedule->scheduling_mode,
            'start_time' => $isPartialDay ? $start->format('H:i') : null,
            'end_time' => $isPartialDay ? $end->format('H:i') : null,
        ];
    }

    private function resolveProjectName(array $validated): string
    {
        if ($validated['client_type'] === 'Commercial' && filled($validated['company_name'] ?? null)) {
            return $validated['company_name'];
        }

        return trim(collect([
            $validated['firstname'],
            $validated['middle_name'] ?? null,
            $validated['surname'],
        ])->filter()->implode(' '));
    }

    private function inputCompanyName(array $validated): ?string
    {
        if ($validated['client_type'] === 'Commercial' && filled($validated['company_name'] ?? null)) {
            return $validated['company_name'] ?? null;
        }

        return null;
    }

    private function generateReferenceNumber(int $projectId): string
    {
        return sprintf('PRJ-%s-%s', now()->format('Ymd'), str_pad((string) $projectId, 5, '0', STR_PAD_LEFT));
    }

    private function storeDocument(?UploadedFile $uploadedFile, int $projectId, string $folder): void
    {
        if (! $uploadedFile) {
            return;
        }

        $directory = public_path('uploads/'.$folder);

        File::ensureDirectoryExists($directory);

        $fileName = Str::uuid()->toString().'.'.$uploadedFile->getClientOriginalExtension();
        $uploadedFile->move($directory, $fileName);

        Document::create([
            'project_id' => $projectId,
            'document_type' => $folder,
            'document_name' => $fileName,
            'document_path' => 'uploads/'.$folder.'/'.$fileName,
            'uploaded_at' => now(),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $project = Project::with([
            'clients',
            'documents',
            'schedule',
            'schedules',
            'projectTypes',
            'projectTechnicians.technician' => function ($query) {

                // `skills` is loaded because the Assigned Team panel now lists
                // each technician's approved specialties beside their name.
                $query->with(['account', 'skills'])
                    ->withCount([
                        'tasks as tasks_count' => function ($q) {

                            $q->whereIn('status', [
                                'pending',
                                'ongoing',
                            ]);
                        },
                    ]);
            },

        ])->findOrFail($id);
        $sortedProjectTechnicians = $project->projectTechnicians
            ->sortByDesc(function ($projectTechnician) {
                return optional($projectTechnician->technician?->account)->role === 'lead_technician' ? 1 : 0;
            })
            ->values();

        $project->setRelation('projectTechnicians', $sortedProjectTechnicians);

        // Screened against EVERY date range the project has, not just the
        // first, and ranked so the technicians whose skills match the project
        // types come up as suggestions.
        $teamCandidates = app(ProjectTeamCandidates::class)->forProject($project);

        $currentLeadTechnicianId = optional(
            $project->projectTechnicians->first(function ($projectTechnician) {
                return optional($projectTechnician->technician?->account)->role === 'lead_technician';
            })
        )->technician_id;

        $currentTeamTechnicianIds = $project->projectTechnicians
            ->pluck('technician_id')
            ->reject(fn ($technicianId) => $technicianId === $currentLeadTechnicianId)
            ->values();

        // The lead select and the technician picker draw from the same screened
        // list, so the two can never disagree about who is free.
        $leadTechnicianOptions = $teamCandidates
            ->filter(fn (array $candidate): bool => $candidate['role'] === 'lead_technician')
            ->values();

        $assignedTeamLookup = $teamCandidates;

        $projectTypes = ProjectType::query()
            ->orderBy('type_name', 'asc')
            ->get();

        // Get technician reports for this project
        $reports = TechnicianReport::with('images')
            ->where('project_id', $id);

        $tasks = Task::with(['technician', 'images', 'completedBy'])
            ->where('project_id', $id)
            ->latest()
            ->get();

        // Filter by report type
        if ($request->filled('report_type')) {
            $reports->where('report_type', $request->report_type);
        }

        $reports = $reports->latest()->get();

        $isReadOnly = $project->isReadOnly();

        $assignedTeamTechnicianIds = $project->projectTechnicians->pluck('technician_id')->filter()->values();

        $technicianActiveTaskCounts = Task::query()
            ->whereIn('technician_id', $assignedTeamTechnicianIds)
            ->whereIn('status', ['pending', 'ongoing'])
            ->selectRaw('technician_id, count(*) as active_count')
            ->groupBy('technician_id')
            ->pluck('active_count', 'technician_id');

        return view('super-admin.projectDetails', compact(
            'project',
            'projectTypes',
            'reports',
            'tasks',
            'leadTechnicianOptions',
            'currentLeadTechnicianId',
            'currentTeamTechnicianIds',
            'isReadOnly',
            'assignedTeamLookup',
            'technicianActiveTaskCounts'
        ));

    }

    public function previewDocument(int $id, string $type)
    {
        $project = Project::query()
            ->with(['documents'])
            ->findOrFail($id);

        $document = $project->documents->firstWhere('document_type', $type);

        abort_unless($document, 404);

        $documentUrl = asset($document->document_path);
        $extension = strtolower(pathinfo($document->document_path, PATHINFO_EXTENSION));

        $previewType = match ($extension) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' => 'image',
            'pdf' => 'pdf',
            'doc', 'docx' => 'docx',
            default => 'file',
        };

        $title = match ($type) {
            'assessment' => 'Assessment',
            'quotation' => 'Quotation',
            'contract' => 'Contract',
            default => ucfirst($type),
        };

        return view('super-admin.projectDocumentPreview', compact(
            'project',
            'document',
            'documentUrl',
            'previewType',
            'title'
        ));
    }

    public function update(Request $request, int $id)
    {
        $project = Project::findOrFail($id);

        if ($project->isReadOnly()) {
            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('error', 'This project is '.$project->status.' and can no longer be edited.');
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'contact_number' => ['required', 'regex:/^09\d{9}$/'],
            'email_address' => ['required', 'email', 'max:255'],
            'quotation' => ['required', 'numeric', 'min:0'],
            'project_description' => ['required', 'string'],
            'project_types' => ['required', 'array', 'min:1'],
            'project_types.*' => ['required', 'integer', 'exists:tbl_project_types,type_id'],

            'assessmentDocument' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,docx', 'max:'.Document::MAX_KILOBYTES],
            'quotationDocument' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,docx', 'max:'.Document::MAX_KILOBYTES],
            'contractDocument' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,docx', 'max:'.Document::MAX_KILOBYTES],
        ], [
            'assessmentDocument.max' => 'The assessment document must be '.Document::MAX_LABEL.' or smaller.',
            'quotationDocument.max' => 'The quotation document must be '.Document::MAX_LABEL.' or smaller.',
            'contractDocument.max' => 'The contract document must be '.Document::MAX_LABEL.' or smaller.',
        ]);

        // Collected inside the transaction and read after it commits, so the
        // client is only told about a document that actually landed.
        $uploadedDocuments = [];

        try {

            DB::transaction(function () use ($validated, $request, $id, &$uploadedDocuments) {

                $project = Project::findOrFail($id);

                $project->update([
                    'quotation' => $validated['quotation'],
                    'address' => $validated['address'],
                    'description' => $validated['project_description'],
                ]);

                $client = Client::query()
                    ->where('project_id', $project->project_id)
                    ->firstOrFail();

                $client->update([
                    'client_type' => $client->client_type,
                    'company_name' => $validated['company_name'] ?? null,
                    'surname' => $validated['last_name'],
                    'firstname' => $validated['first_name'],
                    'middlename' => $validated['middle_initial'] ?? null,
                    'fullname' => trim(collect([
                        $validated['first_name'],
                        $validated['middle_initial'] ?? null,
                        $validated['last_name'],
                    ])->filter()->implode(' ')),
                    'email_address' => $validated['email_address'],
                    'contact_number' => $validated['contact_number'],
                ]);

                $project->projectTypes()->sync($validated['project_types']);

                // Update documents here if needed...
                if ($this->replaceDocument($request->file('assessmentDocument'), $project->project_id, 'assessment')) {
                    $uploadedDocuments[] = 'assessment';
                }

                if ($this->replaceDocument($request->file('quotationDocument'), $project->project_id, 'quotation')) {
                    $uploadedDocuments[] = 'quotation';
                }

                if (
                    $client->client_type === 'Commercial' &&
                    $request->hasFile('contractDocument')
                ) {
                    if ($this->replaceDocument($request->file('contractDocument'), $project->project_id, 'contract')) {
                        $uploadedDocuments[] = 'contract';
                    }
                }
            });

            $this->activityLogger->record(
                ActivityLog::PROJECT_UPDATED,
                null,
                sprintf("Updated the details of project '%s'.", $project->reference_no),
                $project
            );

            $this->notifications->projectUpdated($project);

            // A new document is the one part of an edit the client cares
            // about, so that - and only that - is emailed to them.
            foreach ($uploadedDocuments as $documentType) {
                $this->clientEmails->documentUploaded($project, $documentType);
            }

            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('success', 'Project updated successfully.');
        } catch (Throwable $e) {

            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('error', $e->getMessage());
        }
    }

    /**
     * @return bool whether a file was actually stored
     */
    private function replaceDocument(?UploadedFile $uploadedFile, int $projectId, string $documentType): bool
    {
        if (! $uploadedFile) {
            return false;
        }

        $directory = public_path('uploads/'.$documentType);

        File::ensureDirectoryExists($directory);

        // Find existing document
        $document = Document::query()
            ->where('project_id', $projectId)
            ->where('document_type', $documentType)
            ->first();

        // Delete old physical file
        if ($document && File::exists(public_path($document->document_path))) {
            File::delete(public_path($document->document_path));
        }

        // Store new file
        $fileName = Str::uuid().'.'.$uploadedFile->getClientOriginalExtension();

        $uploadedFile->move($directory, $fileName);

        $data = [
            'document_name' => $fileName,
            'document_path' => 'uploads/'.$documentType.'/'.$fileName,
            'uploaded_at' => now(),
        ];

        if ($document) {

            // Replace existing database record
            $document->update($data);
        } else {

            // Create one if it doesn't exist
            Document::create(array_merge($data, [
                'project_id' => $projectId,
                'document_type' => $documentType,
            ]));
        }

        return true;
    }

    public function putOnHold(Request $request, int $id)
    {
        $project = Project::findOrFail($id);

        if ($project->isReadOnly()) {
            return redirect()
                ->route('super-admin.projects', $id)
                ->with('error', 'This project is '.$project->status.' and cannot be put on hold.');
        }

        if ($project->status === 'not_yet_scheduled') {
            return redirect()
                ->route('super-admin.projects', $id)
                ->with('error', 'This project has no schedule yet.');
        }

        // Read the team before the hold releases it, or there would be
        // nobody left to tell.
        $team = $this->notifications->projectTeam($project);

        try {
            DB::transaction(function () use ($project): void {
                $project->update([
                    'on_hold' => true,
                    'status' => 'not_yet_scheduled',
                ]);

                $this->releaseScheduleAndTechnicians($project);
            });

            $this->notifications->projectPutOnHold($project, $team);
            $this->clientEmails->projectPutOnHold($project);

            $this->activityLogger->record(
                ActivityLog::PROJECT_PUT_ON_HOLD,
                null,
                sprintf("Put project '%s' on hold, releasing its schedule and technicians.", $project->reference_no),
                $project
            );

            return redirect()
                ->route('super-admin.projects', $id)
                ->with('success', 'Project has been put on hold. Its schedule and technicians were released.');
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.projects', $id)
                ->with('error', 'An error occurred while putting the project on hold: '.$e->getMessage());
        }
    }

    public function resume(Request $request, int $id)
    {
        $project = Project::findOrFail($id);

        if ($project->isReadOnly()) {
            return redirect()
                ->route('super-admin.projects', $id)
                ->with('error', 'This project is '.$project->status.' and cannot be resumed.');
        }

        $project->update(['on_hold' => false]);

        $this->notifications->projectResumed($project);
        $this->clientEmails->projectResumed($project);

        $this->activityLogger->record(
            ActivityLog::PROJECT_RESUMED,
            null,
            sprintf("Resumed project '%s'.", $project->reference_no),
            $project
        );

        return redirect()
            ->route('super-admin.projects', $id)
            ->with('success', 'Project has been resumed. It is Not Yet Scheduled and must be scheduled again.');
    }

    /**
     * Show the completion confirmation modal's data validation, mark a
     * project as Complete, and store the completion report. Completed
     * projects keep every schedule/technician/task record for auditing;
     * only their editability changes.
     */
    public function complete(Request $request, int $id)
    {
        $project = Project::findOrFail($id);

        if ($project->isReadOnly()) {
            return redirect()
                ->route('super-admin.projects')
                ->with('error', 'This project is already '.$project->status.' and cannot be completed.');
        }

        $validated = $request->validate([
            'completion_date' => ['required', 'date'],
            'completion_summary' => ['required', 'string'],
            'completion_remarks' => ['nullable', 'string'],
            'completion_photos' => ['nullable', 'array', 'max:20'],
            // Matched to every other photo upload in the system, so a phone
            // picture is always accepted and nothing arrives that the browser
            // then struggles to show.
            'completion_photos.*' => ['file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'completion_photos.max' => 'Up to 20 completion photos can be uploaded at once.',
            'completion_photos.*.max' => 'Each completion photo must be 5 MB or smaller.',
        ]);

        try {
            DB::transaction(function () use ($validated, $project, $request): void {
                $project->update([
                    'status' => 'completed',
                    'on_hold' => false,
                    'completed_at' => CarbonImmutable::parse($validated['completion_date']),
                    'completion_summary' => $validated['completion_summary'],
                    'completion_remarks' => $validated['completion_remarks'] ?? null,
                ]);

                if ($request->hasFile('completion_photos')) {
                    $directory = public_path('uploads/completion');
                    File::ensureDirectoryExists($directory);

                    foreach ($request->file('completion_photos') as $photo) {
                        $fileName = Str::uuid()->toString().'.'.$photo->getClientOriginalExtension();
                        $photo->move($directory, $fileName);

                        ProjectCompletionPhoto::create([
                            'project_id' => $project->project_id,
                            'photo_path' => 'uploads/completion/'.$fileName,
                            'uploaded_at' => now(),
                        ]);
                    }
                }

                // Dates booked past the completion date are released: the work
                // is done, so the project must stop reading as booked and its
                // technicians must stop reading as busy.
                app(ProjectCompletion::class)->releaseFutureSchedules(
                    $project,
                    CarbonImmutable::parse($validated['completion_date'])
                );

                // Everything else (technicians, task history, and the days
                // already worked) is intentionally left untouched for
                // auditing and reporting.
            });

            $this->activityLogger->record(
                ActivityLog::PROJECT_COMPLETED,
                null,
                sprintf("Marked project '%s' as completed.", $project->reference_no),
                $project
            );

            $this->notifications->projectCompleted($project);
            $this->clientEmails->projectCompleted($project->refresh());

            return redirect()
                ->route('super-admin.projects')
                ->with('success', 'Project marked as completed.');
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.projects')
                ->with('error', 'An error occurred while completing the project: '.$e->getMessage());
        }
    }

    /**
     * Cancel a project: store the cancellation report and free up every
     * schedule/technician assignment so those technicians become
     * available for other projects again.
     */
    public function cancel(Request $request, int $id)
    {
        $project = Project::findOrFail($id);

        if ($project->isCompleted()) {
            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('error', 'A completed project cannot be cancelled.');
        }

        if ($project->isCancelled() || $project->isArchived()) {
            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('error', 'This project is already '.$project->status.'.');
        }

        $validated = $request->validate([
            'cancellation_date' => ['required', 'date'],
            'cancellation_reason' => ['required', 'string', 'max:255'],
            'cancellation_remarks' => ['nullable', 'string'],
        ]);

        try {
            DB::transaction(function () use ($validated, $project): void {
                $project->update([
                    'status' => 'cancelled',
                    'on_hold' => false,
                    'cancelled_at' => CarbonImmutable::parse($validated['cancellation_date']),
                    'cancellation_reason' => $validated['cancellation_reason'],
                    'cancellation_remarks' => $validated['cancellation_remarks'] ?? null,
                ]);

                // Schedule, technician assignments, and task history are kept
                // intact for the record. Technicians are still freed up for
                // other work because the availability checker already
                // ignores cancelled projects entirely.
            });

            $this->activityLogger->record(
                ActivityLog::PROJECT_CANCELLED,
                null,
                sprintf("Cancelled project '%s'.", $project->reference_no),
                $project
            );

            $this->notifications->projectCancelled($project);
            $this->clientEmails->projectCancelled($project->refresh());

            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('success', 'Project has been cancelled.');
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('error', 'An error occurred while cancelling the project: '.$e->getMessage());
        }
    }

    /**
     * Archive a project: keep every field for historical purposes but move
     * it out of the active Projects list and free its technicians.
     */
    public function archive(Request $request, int $id)
    {
        $project = Project::findOrFail($id);

        if ($project->isArchived()) {
            return redirect()
                ->route('super-admin.projects')
                ->with('error', 'This project is already archived.');
        }

        try {
            DB::transaction(function () use ($project, $request): void {
                $project->update([
                    'status' => 'archived',
                    'is_archived' => true,
                    'on_hold' => false,
                    'archived_at' => now(),
                    'archived_by' => $request->user()?->id,
                ]);

                $this->releaseScheduleAndTechnicians($project);
            });

            $this->activityLogger->record(
                ActivityLog::PROJECT_ARCHIVED,
                null,
                sprintf("Archived project '%s'.", $project->reference_no),
                $project
            );

            $this->notifications->projectArchived($project);

            return redirect()
                ->route('super-admin.projects')
                ->with('success', 'Project has been archived.');
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.projects')
                ->with('error', 'An error occurred while archiving the project: '.$e->getMessage());
        }
    }

    /**
     * Restore an archived project back into the active pipeline as
     * Not Yet Scheduled. It must be scheduled again from scratch.
     */
    public function restore(int $id)
    {
        $project = Project::findOrFail($id);

        if (! $project->isArchived()) {
            return redirect()
                ->route('super-admin.projects.archived')
                ->with('error', 'Only archived projects can be restored.');
        }

        try {
            DB::transaction(function () use ($project): void {
                $project->update([
                    'status' => 'not_yet_scheduled',
                    'is_archived' => false,
                    'archived_at' => null,
                    'archived_by' => null,
                ]);

                // Schedule/technicians were already released on archive,
                // but this guarantees a clean slate either way.
                $this->releaseScheduleAndTechnicians($project);
            });

            $this->activityLogger->record(
                ActivityLog::PROJECT_RESTORED,
                null,
                sprintf("Restored project '%s' from the archive.", $project->reference_no),
                $project
            );

            $this->notifications->projectRestored($project);

            return redirect()
                ->route('super-admin.projects')
                ->with('success', 'Project restored. It is now Not Yet Scheduled.');
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.projects.archived')
                ->with('error', 'An error occurred while restoring the project: '.$e->getMessage());
        }
    }

    /**
     * Remove a project's schedule, schedule-technician, and
     * project-technician records so its technicians are freed up for
     * other work. Used by cancel/archive/restore. Task history is left
     * alone; unassigned tasks simply show "Unassigned".
     */
    private function releaseScheduleAndTechnicians(Project $project): void
    {
        $projectTechnicianIds = DB::table('tbl_project_technicians')
            ->where('project_id', $project->project_id)
            ->pluck('project_technician_id');

        if ($projectTechnicianIds->isNotEmpty()) {
            DB::table('tbl_schedule_technicians')
                ->whereIn('project_technician_id', $projectTechnicianIds->all())
                ->delete();
        }

        DB::table('tbl_project_technicians')
            ->where('project_id', $project->project_id)
            ->delete();

        DB::table('tbl_schedule')
            ->where('project_id', $project->project_id)
            ->delete();

        Task::where('project_id', $project->project_id)
            ->where('status', '!=', 'completed')
            ->update([
                'technician_id' => null,
                'status' => 'unassigned',
                'start_date' => null,
                'due_date' => null,
            ]);
    }

    /**
     * Promote a freshly-scheduled project out of Not Yet Scheduled. Mirrors
     * the same "pending vs ongoing" rule used by updateStatus().
     */
    private function promoteStatusAfterScheduling(Project $project): void
    {
        if ($project->status !== 'not_yet_scheduled') {
            return;
        }

        $firstSchedule = DB::table('tbl_schedule')
            ->where('project_id', $project->project_id)
            ->orderBy('start_datetime', 'asc')
            ->first();

        if (! $firstSchedule) {
            return;
        }

        $status = now()->gte(Carbon::parse($firstSchedule->start_datetime))
            ? 'ongoing'
            : 'pending';

        $project->update(['status' => $status]);
    }

    public function updateStatus($projects = null)
    {
        $projects = Project::all();

        foreach ($projects as $project) {

            if (in_array($project->status, Project::READ_ONLY_STATUSES, true)) {
                continue;
            }

            $firstSchedule = DB::table('tbl_schedule')
                ->select('*')
                ->where('project_id', '=', $project->project_id)
                ->orderBy('start_datetime', 'asc')
                ->first();

            if (! $firstSchedule) {
                continue;
            }

            switch ($project->status) {

                case 'not_yet_scheduled':
                    $this->promoteStatusAfterScheduling($project);
                    break;

                case 'pending':
                    if (now()->gte(Carbon::parse($firstSchedule->start_datetime))) {
                        $project->update([
                            'status' => 'ongoing',
                        ]);
                    }
                    break;

                case 'ongoing':
                    // Check if project should become completed
                    break;

                case 'on_hold':
                    // Do nothing while on hold
                    break;
            }
        }
    }

    public function updateAssignedTeam(Request $request, int $id)
    {
        $project = Project::with(['schedules', 'projectTechnicians'])->findOrFail($id);

        if ($project->isReadOnly()) {
            return back()->with('error', 'This project is '.$project->status.' and its team can no longer be edited.');
        }

        $validated = $request->validate([
            'lead_tech' => ['required', 'integer', 'exists:tbl_technicians,technician_id'],
            'technicians' => ['nullable', 'array'],
            'technicians.*' => ['integer', 'exists:tbl_technicians,technician_id'],
        ], [
            'lead_tech.required' => 'A lead technician is required.',
        ]);

        $technicianIds = collect([
            $validated['lead_tech'],
            ...($validated['technicians'] ?? []),
        ])
            ->map(fn ($technicianId) => (int) $technicianId)
            ->unique()
            ->values();

        $currentlyAssignedIds = $project->projectTechnicians->pluck('technician_id');
        $newlyAddedIds = $technicianIds->diff($currentlyAssignedIds)->values();

        // Read before the save so the people who are about to be dropped can
        // still be identified.
        $teamBefore = $this->notifications->projectTeam($project);
        $previousLeadId = $this->notifications->projectLead($project)?->id;

        // Every one of the project's schedules has to be checked, not just the
        // first one, and every day inside each range - a technician who is free on
        // the endpoints but booked mid-range must still be rejected. A partial-day
        // schedule only asks about its own hours, so joining a team booked for a
        // morning does not require the whole day to be free.
        $ranges = $project->schedules
            ->map(fn (Schedule $schedule): array => $schedule->toAvailabilityRange())
            ->all();

        if ($ranges !== [] && $newlyAddedIds->isNotEmpty()) {
            $availability = app(TechnicianAvailabilityService::class);

            $conflicts = $availability->findConflicts(
                $newlyAddedIds,
                $ranges,
                $project->project_id
            );

            if ($conflicts->isNotEmpty()) {
                return back()->with('error', $availability->conflictMessage($conflicts));
            }
        }

        DB::transaction(function () use ($project, $technicianIds): void {
            $projectTechniciansToRemove = DB::table('tbl_project_technicians')
                ->select('project_technician_id', 'technician_id')
                ->where('project_id', '=', $project->project_id)
                ->whereNotIn('technician_id', $technicianIds->toArray())
                ->get();

            if ($projectTechniciansToRemove->isNotEmpty()) {

                $projectTechnicianIds = $projectTechniciansToRemove
                    ->pluck('project_technician_id')
                    ->toArray();

                $removedTechnicianIds = $projectTechniciansToRemove
                    ->pluck('technician_id')
                    ->toArray();

                DB::table('tbl_schedule_technicians')
                    ->whereIn('project_technician_id', $projectTechnicianIds)
                    ->delete();

                DB::table('tbl_project_technicians')
                    ->whereIn('project_technician_id', $projectTechnicianIds)
                    ->delete();

                Task::where('project_id', $project->project_id)
                    ->whereIn('technician_id', $removedTechnicianIds)
                    ->where('status', '!=', 'completed') // don't touch finished work
                    ->update([
                        'technician_id' => null,
                        'status' => 'unassigned',
                    ]);
            }

            $technicianIds->each(function (int $technicianId) use ($project): void {
                ProjectTechnician::firstOrCreate([
                    'project_id' => $project->project_id,
                    'technician_id' => $technicianId,
                ]);
            });
        });

        $this->activityLogger->record(
            ActivityLog::TECHNICIAN_ASSIGNED,
            null,
            sprintf(
                "Updated the assigned team on '%s': %d technician(s), %d newly added.",
                $project->reference_no,
                $technicianIds->count(),
                $newlyAddedIds->count()
            ),
            $project
        );

        $project->load('projectTechnicians.technician.account');

        $teamAfter = $this->notifications->projectTeam($project);
        $lead = $this->notifications->projectLead($project);

        if ($lead && $lead->id !== $previousLeadId) {
            $this->notifications->leadAssignedToProject($project, $lead);
        }

        if ($previousLeadId && $lead?->id !== $previousLeadId) {
            $formerLead = $teamBefore->firstWhere('id', $previousLeadId);

            if ($formerLead) {
                $this->notifications->leadRemovedFromProject($project, $formerLead);
            }
        }

        $this->notifications->techniciansAssignedToProject(
            $project,
            $teamAfter->filter(
                fn (User $user): bool => $user->id !== $lead?->id
                    && ! $teamBefore->contains(fn (User $before): bool => $before->id === $user->id)
            )
        );

        $this->notifications->techniciansRemovedFromProject(
            $project,
            $teamBefore->filter(
                fn (User $user): bool => $user->id !== $previousLeadId
                    && ! $teamAfter->contains(fn (User $after): bool => $after->id === $user->id)
            )
        );

        return back()->with('success', 'Assigned team updated.');
    }
}
