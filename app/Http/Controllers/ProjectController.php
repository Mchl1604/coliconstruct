<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\ActivityLogger;
use App\Services\ClientProjects;
use App\Services\ImportableTeamSources;
use App\Services\NotificationService;
use App\Services\ProjectCompletion;
use App\Services\ProjectEmails;
use App\Services\ProjectReopen;
use App\Services\ProjectStatusRules;
use App\Services\ProjectTeam;
use App\Services\ProjectTeamCandidates;
use App\Services\ProjectTeamRules;
use App\Services\ScheduleHoldCutoff;
use App\Services\ScheduleModeRules;
use App\Services\TaskScheduleRules;
use App\Services\TechnicianAvailabilityService;
use App\Services\TechnicianTaskLoad;
use App\Support\UploadStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use PDOException;
use RuntimeException;
use Throwable;

class ProjectController extends Controller
{
    /**
     * How far ahead the Reopen dialog's date pickers are screened for
     * technician availability. Far enough that nobody meets the edge of it in
     * practice; a date beyond it is simply not greyed out, and the reopen
     * itself is checked on the way in whatever the picker offered.
     */
    private const REOPEN_PICKER_HORIZON_MONTHS = 24;

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
        private readonly ProjectEmails $clientEmails,
        private readonly ProjectTeam $projectTeam,
        private readonly ScheduleHoldCutoff $holdCutoff
    ) {}

    public function index()
    {
        $this->updateStatus(); // Call the function to update project statuses

        // `schedules` is eager loaded because isOverdue() reads every range;
        // without it the status column would fire a query per row.
        $projects = Project::query()
            // `technician.account` is loaded because every row now asks
            // needsRecrew(), which reads each assigned technician's account to
            // decide whether they can still sign in. Without it that is two
            // queries per row on the busiest page in the portal.
            ->with(['clients', 'documents', 'schedule', 'schedules', 'projectTypes', 'projectTechnicians.technician.account'])
            // What the completion rules will object to, per project, as two
            // subqueries rather than two queries per row - see
            // ProjectPolicy::blockersFor(), which reads these when they are
            // here. The completion dialog on this page needs them: an
            // administrator may complete a project the rules refuse, but only
            // by saying why, and the dialog has to show what it is asking
            // about before it asks.
            ->withCount([
                'tasks',
                'tasks as open_tasks_count' => fn ($query) => $query->whereIn('status', Task::OPEN_STATUSES),
            ])
            ->where('is_archived', false)
            ->where('status', '!=', 'archived')
            ->orderBy('project_id', 'desc')
            ->get();

        // Every tab carries its count, in the pattern Overdue and On Hold
        // already used. Grouped by the same method each row is labelled with,
        // so a badge cannot promise more rows than its tab shows.
        //
        // With the attention tabs on the end - Unscheduled, No Technicians,
        // Inactive Crew - which appear only while they hold something and are
        // where the dashboard's Urgent Actions land.
        $statusTabs = Project::statusTabs($projects, null, withAttention: true);

        $policy = app(ProjectPolicy::class);

        $completionBlockers = $projects
            ->mapWithKeys(fn (Project $project): array => [
                $project->project_id => $project->isReadOnly() ? [] : $policy->blockersFor($project),
            ])
            ->all();

        return view('super-admin.projects', compact('projects', 'statusTabs', 'completionBlockers'));
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
        // Only technicians who can actually be given the work. A new project
        // assigns everybody on it from scratch, so there is no existing member
        // to make an exception for - see ProjectTeamRules, which refuses the
        // same people on the way back in.
        $technicians = Technician::query()
            ->with(['account', 'skills'])
            ->assignable()
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

    /**
     * The teams that could be imported onto a project, screened against the
     * dates that project will hold.
     *
     * Serves both callers, because the question is the same one either way and
     * only the destination's dates differ. An existing project is named by
     * `project_id` and its stored schedules are used. A project being created
     * has no id and no rows yet, so the wizard sends the schedule it is about
     * to save, in exactly the shape every other scheduling screen submits.
     */
    public function importableTeams(Request $request)
    {
        // Hand-rolled rather than $request->validate(): this endpoint must
        // always answer with JSON, and only api/* paths render exceptions that
        // way (see bootstrap/app.php).
        $scheduleRules = app(ScheduleModeRules::class);

        $validator = Validator::make($request->all(), [
            'project_id' => ['nullable', 'integer', 'exists:tbl_projects,project_id'],
            ...$scheduleRules->rules(),
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $destination = $request->filled('project_id')
            ? Project::with('schedules')->find($request->integer('project_id'))
            : null;

        if ($destination) {
            $ranges = $destination->schedules
                ->map(fn (Schedule $schedule): array => $schedule->toAvailabilityRange())
                ->all();
        } else {
            $schedule = $request->only([
                'scheduling_mode',
                'start_date',
                'end_date',
                'project_date',
                'start_time',
                'end_time',
            ]);

            // The wizard cannot fill its schedule in until a team exists, so a
            // project being created usually has no dates yet when this is
            // asked. With none there is nothing to clash with, and every team
            // comes back offerable - the wizard then screens each technician
            // against the dates as they are chosen, and StoreProjectRequest
            // refuses a team that does not fit them.
            $ranges = [];

            if ($this->describesASchedule($request)) {
                $entry = $scheduleRules->validateEntry($validator, $schedule, '', true, true);

                if (! $entry) {
                    return response()->json([
                        'error' => $validator->errors()->first()
                            ?: 'That schedule could not be read, so nobody could be checked against it.',
                    ], 422);
                }

                $ranges = [$entry];
            }
        }

        return response()->json([
            'projects' => app(ImportableTeamSources::class)->forRanges(
                $ranges,
                $destination?->project_id
            ),
        ]);
    }

    /**
     * Whether the caller is asking about a schedule at all.
     *
     * A wizard that has not reached its dates yet sends none of these, which
     * is a different thing from sending one that cannot be read: the first is
     * screened against nothing, the second is refused.
     */
    private function describesASchedule(Request $request): bool
    {
        return collect(['start_date', 'end_date', 'project_date', 'start_time', 'end_time'])
            ->contains(fn (string $field): bool => $request->filled($field));
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
                    'status' => 'unscheduled',
                    'quotation' => $validated['quotation_amount'],
                    'address' => $validated['project_address'],
                    'description' => $validated['project_description'],
                ]);

                $project->forceFill([
                    'reference_no' => $this->generateReferenceNumber($project->project_id),
                ])->save();

                Client::create([
                    'project_id' => $project->project_id,
                    // Linked to the account behind the address when there is
                    // one. Null when there is not, which is the ordinary case:
                    // work is often booked before the client registers, and
                    // registering is what fills this in.
                    'user_id' => app(ClientProjects::class)
                        ->accountFor($validated['client_email'])?->id,
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

                // The dates are written first so that attaching each technician
                // books them onto this schedule in the same step, through the
                // one path that always writes both rows.
                Schedule::create([
                    'project_id' => $project->project_id,
                    'start_datetime' => $scheduleEntry['start'],
                    'end_datetime' => $scheduleEntry['end'],
                    'scheduling_mode' => $scheduleEntry['mode'],
                    'status' => 'scheduled',
                    'remarks' => 'Created from project wizard',
                ]);

                $selectedTechnicianIds->each(function (int $technicianId) use ($project): void {
                    $this->projectTeam->attach($project, $technicianId);
                });

                $this->syncStatusWithSchedule($project);

                $this->storeDocuments(
                    $request->file('assessment_report'),
                    $project->project_id,
                    'assessment'
                );

                $this->storeDocuments(
                    $request->file('approved_quotation'),
                    $project->project_id,
                    'quotation'
                );

                if ($validated['client_type'] !== 'Residential' && $request->hasFile('contract')) {
                    $this->storeDocuments(
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

        $message = 'Unable to create project. Nothing was saved.';

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
        // The same two conditions TechnicianAvailabilityService screens by, so
        // a day the browser offers is a day the server will accept. Archived
        // work is named as well as excluded by status: archiving keeps the
        // schedule now, and a project archived while its status still read
        // Pending or Ongoing would otherwise go on booking its crew from
        // inside the archive.
        $schedules = Schedule::query()
            ->whereHas('project', function ($query): void {
                $query->whereIn('status', Project::ACTIVE_PROJECT_STATUSES)
                    ->where('is_archived', false);
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

    /**
     * File every upload of one document type against the project.
     *
     * Files are added, never swapped: a quotation can run to several pages,
     * and a project keeps every one of them until somebody removes it by hand.
     *
     * @param  array<int, UploadedFile>|UploadedFile|null  $uploadedFiles
     * @return int how many were stored, so a caller can tell the client only
     *             about a document that actually landed
     */
    private function storeDocuments(array|UploadedFile|null $uploadedFiles, int $projectId, string $folder): int
    {
        // A form written before these fields took several files still sends
        // one, and is still stored the same way.
        $files = collect(is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles])
            ->filter(fn ($file): bool => $file instanceof UploadedFile);

        if ($files->isEmpty()) {
            return 0;
        }

        $files->each(function (UploadedFile $uploadedFile) use ($projectId, $folder): void {
            // On the private uploads disk, which is object storage in a
            // deployment and a directory outside public/ everywhere else.
            // Never under public/: a contract is not a static asset, and the
            // route that serves it checks who is asking - see
            // UploadedFileController.
            $path = UploadStore::put($uploadedFile, 'documents');

            Document::create([
                'project_id' => $projectId,
                'document_type' => $folder,
                // The name it arrived under, so the list reads as the person
                // who uploaded it would expect rather than as a uuid. Clipped
                // to what the column holds: the name is a label here, and the
                // file on disk is found by document_path either way.
                'document_name' => mb_substr($uploadedFile->getClientOriginalName(), 0, 255),
                // The path on the uploads disk. Nothing derives a URL from
                // it - route('media.document') does that from the id.
                'document_path' => $path,
                'uploaded_at' => now(),
            ]);
        });

        return $files->count();
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
                //
                // A `tasks_count` was withCount()ed here too, counting every
                // project's tasks rather than this one's. Nothing read it, and
                // it was a second way to get the same figure wrong, so the
                // count now comes from TechnicianTaskLoad and only from there.
                $query->with(['account', 'skills']);
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

        // Get technician reports for this project. `submitter` and the
        // technician's account are loaded because every card names who filed
        // the report - asked per row, that is a query per report.
        // Archived reports are deliberately absent: this is the project's
        // active record, and an archived report is read on the Archived
        // Reports page - the same rule the Reports listing follows, so a
        // report archived from either place disappears from both.
        $reports = TechnicianReport::with(['images', 'submitter', 'technician.account'])
            ->active()
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

        // A hold is not the same as a locked record: everything on this page
        // is still readable, and the project can still be resumed, cancelled
        // and archived. What it cannot do while paused is take work - a new
        // technician, a new report, a task edit or a new date - so the two are
        // handed to the view separately rather than folded into one flag.
        $isOnHold = (bool) $project->on_hold;
        $canTakeWork = ! $isReadOnly && ! $isOnHold;

        // Scoped to this project: the picker below lists this project's team
        // and is answering "how much of THIS job is already on them?". Counted
        // across every project, somebody busy elsewhere read as busy here.
        $technicianActiveTaskCounts = app(TechnicianTaskLoad::class)
            ->forProject($project->project_id);

        // Reopening is an administrator's move, and only on a project still
        // waiting for its client - the model settles which, so the button and
        // the endpoint cannot disagree about it.
        $canReopen = $project->canBeReopened()
            && (bool) $request->user()?->isEmployee()
            && in_array($request->user()?->role, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN], true);

        // The reopen dialog books new dates, so it needs the same bookable
        // hours every other scheduling screen offers, and the days its pickers
        // must refuse - see reopenBlockedDates().
        $workingHours = Schedule::workingHourOptions();
        $reopenBlockedDates = $canReopen
            ? $this->reopenBlockedDates($project)
            : ['whole_day' => [], 'partial_day' => []];

        // What the completion rules would object to, if anything. An
        // administrator may complete a project regardless - unlike a lead
        // technician, who is simply refused - but only by saying why, so the
        // dialog has to show what it is being asked to override before it
        // asks for a reason.
        $completionBlockers = $project->isReadOnly()
            ? []
            : app(ProjectPolicy::class)->blockersFor($project);

        return view('super-admin.projectDetails', compact(
            'project',
            'projectTypes',
            'reports',
            'tasks',
            'leadTechnicianOptions',
            'currentLeadTechnicianId',
            'currentTeamTechnicianIds',
            'isReadOnly',
            'isOnHold',
            'canTakeWork',
            'assignedTeamLookup',
            'technicianActiveTaskCounts',
            'canReopen',
            'workingHours',
            'reopenBlockedDates',
            'completionBlockers'
        ));

    }

    /**
     * One document, on its own page.
     *
     * Addressed by document rather than by type: a project may hold several
     * assessments, and "the assessment" no longer names one of them.
     */
    public function previewDocument(int $id, Document $document)
    {
        $project = Project::query()
            ->with(['documents'])
            ->findOrFail($id);

        abort_unless((int) $document->project_id === (int) $project->project_id, 404);

        $type = $document->document_type;
        $documentUrl = $document->url();
        $extension = strtolower(pathinfo($document->document_path, PATHINFO_EXTENSION));

        $previewType = match ($extension) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' => 'image',
            'pdf' => 'pdf',
            // No longer accepted on the way in, but old documents are still
            // read, so the viewer still knows what to do with one.
            'doc', 'docx' => 'docx',
            default => 'file',
        };

        $title = Document::TYPES[$type] ?? ucfirst($type);

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

            // Each type takes any number of files, and anything uploaded here
            // is added to what the project already holds rather than
            // replacing it - removing one is its own action.
            'assessmentDocument' => ['nullable', 'array', 'max:'.Document::MAX_FILES],
            'assessmentDocument.*' => Document::fileRules(),
            'quotationDocument' => ['nullable', 'array', 'max:'.Document::MAX_FILES],
            'quotationDocument.*' => Document::fileRules(),
            'contractDocument' => ['nullable', 'array', 'max:'.Document::MAX_FILES],
            'contractDocument.*' => Document::fileRules(),
        ], [
            'assessmentDocument.max' => 'Upload at most '.Document::MAX_FILES.' assessment files at a time.',
            'assessmentDocument.*.mimes' => Document::mimesMessage('assessment'),
            'assessmentDocument.*.max' => Document::maxMessage('assessment'),
            'quotationDocument.max' => 'Upload at most '.Document::MAX_FILES.' quotation files at a time.',
            'quotationDocument.*.mimes' => Document::mimesMessage('quotation'),
            'quotationDocument.*.max' => Document::maxMessage('quotation'),
            'contractDocument.max' => 'Upload at most '.Document::MAX_FILES.' contract files at a time.',
            'contractDocument.*.mimes' => Document::mimesMessage('contract'),
            'contractDocument.*.max' => Document::maxMessage('contract'),
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

                // Anything uploaded is added to what the project already
                // holds. Files are only ever taken away one at a time, by the
                // remove button beside each of them.
                if ($this->storeDocuments($request->file('assessmentDocument'), $project->project_id, 'assessment')) {
                    $uploadedDocuments[] = 'assessment';
                }

                if ($this->storeDocuments($request->file('quotationDocument'), $project->project_id, 'quotation')) {
                    $uploadedDocuments[] = 'quotation';
                }

                if ($client->client_type === 'Commercial') {
                    if ($this->storeDocuments($request->file('contractDocument'), $project->project_id, 'contract')) {
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
                ->with('error', $this->safeErrorMessage($e, 'Unable to update project. Nothing was saved.'));
        }
    }

    /**
     * Take one file off a project.
     *
     * The only way a document leaves a project, now that uploading adds
     * rather than replaces. Read-only work is a historical record and is not
     * edited, which is the same rule the edit dialog itself runs on.
     */
    public function destroyDocument(Request $request, int $id, Document $document)
    {
        $project = Project::findOrFail($id);

        if ((int) $document->project_id !== (int) $project->project_id) {
            return response()->json(['error' => 'That document belongs to another project.'], 404);
        }

        if ($project->isReadOnly() || $project->is_archived) {
            return response()->json([
                'error' => 'This project is '.$project->status.' and its documents can no longer be changed.',
            ], 422);
        }

        $name = $document->document_name;
        $type = $document->document_type;
        $path = $document->document_path;

        $document->delete();

        // The row is what the pages read, so it goes first; a file left on
        // the disk by a failed delete is invisible rather than broken.
        UploadStore::remove($path);

        $this->activityLogger->record(
            ActivityLog::PROJECT_UPDATED,
            null,
            sprintf(
                "Removed the %s document '%s' from project '%s'.",
                Document::TYPES[$type] ?? $type,
                $name,
                $project->reference_no ?? $project->name
            ),
            $project
        );

        return response()->json([
            'message' => sprintf('%s removed.', $name),
            'remaining' => $project->documents()->where('document_type', $type)->count(),
        ]);
    }

    public function putOnHold(Request $request, int $id)
    {
        $project = Project::findOrFail($id);

        if ($project->isReadOnly()) {
            return redirect()
                ->route('super-admin.projects', $id)
                ->with('error', 'This project is '.$project->status.' and cannot be put on hold.');
        }

        if ($project->status === 'unscheduled') {
            return redirect()
                ->route('super-admin.projects', $id)
                ->with('error', 'This project has no schedule yet.');
        }

        // Read up front so the crew is told whatever the hold does to the
        // records underneath them.
        $team = $this->notifications->projectTeam($project);

        try {
            $summary = DB::transaction(function () use ($project): array {
                $project->update([
                    'on_hold' => true,
                    // Whatever dates it keeps are days that have already been
                    // worked; it holds nothing ahead of it, so it needs a new
                    // schedule before it can run again.
                    'status' => 'unscheduled',
                ]);

                return $this->releaseScheduleOnly($project);
            });

            $this->notifications->projectPutOnHold($project, $team);
            $this->clientEmails->projectPutOnHold($project);

            $this->activityLogger->record(
                ActivityLog::PROJECT_PUT_ON_HOLD,
                null,
                sprintf(
                    "Put project '%s' on hold as of %s. %s Its team was kept.",
                    $project->reference_no,
                    Schedule::businessToday()->format('F j, Y'),
                    $this->describeHoldCutoff($summary)
                ),
                $project
            );

            return redirect()
                ->route('super-admin.projects', $id)
                ->with('success', 'Project put on hold.');
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.projects', $id)
                ->with('error', $this->safeErrorMessage($e, 'Unable to put project on hold. Nothing was changed.'));
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

        // Resuming something that was never paused is not a no-op: it emails
        // the client that their project has resumed and tells the whole crew.
        if (! $project->on_hold) {
            return redirect()
                ->route('super-admin.projects', $id)
                ->with('error', 'This project is not on hold.');
        }

        // A hold hands the crew's remaining days back to everybody else, so
        // somebody may have been booked over them while the project was
        // paused. Lifting the hold puts those days back into force, and it
        // must not do so on top of a promise made in the meantime.
        $clash = $this->resumeConflictMessage($project);

        if ($clash !== null) {
            return redirect()
                ->route('super-admin.projects', $id)
                ->with('error', $clash);
        }

        // The hold is lifted first, then the status is worked out from the
        // dates that are actually left - never assumed. A project resumed with
        // nothing ahead of it must not read as work in progress just because
        // the hold kept a record of the days already worked.
        $project->update(['on_hold' => false]);
        $project->unsetRelation('schedules');

        app(ProjectStatusRules::class)->apply($project);

        $project->refresh();

        $this->notifications->projectResumed($project);
        $this->clientEmails->projectResumed($project);

        $this->activityLogger->record(
            ActivityLog::PROJECT_RESUMED,
            null,
            sprintf("Resumed project '%s'. It is now %s.", $project->reference_no, $project->statusLabel()),
            $project
        );

        return redirect()
            ->route('super-admin.projects', $id)
            ->with('success', sprintf(
                'Project resumed - %s.%s',
                $project->statusLabel(),
                $project->status === 'unscheduled' ? ' Schedule it again.' : ''
            ));
    }

    /**
     * Hand a finished project over to its client for confirmation.
     *
     * This used to close the project outright. It no longer does: the company
     * saying the work is done and the client signing it off are two different
     * statements, and only the second one closes a project. What has not
     * changed is everything else this step does - the completion report, the
     * photographs, and releasing the dates booked past the completion date.
     *
     * The project is locked from here. An administrator's one remaining move
     * is Reopen Project, which is a different action with a schedule attached.
     */
    public function complete(Request $request, int $id)
    {
        $project = Project::findOrFail($id);

        if ($project->isReadOnly()) {
            return redirect()
                ->route('super-admin.projects')
                ->with('error', sprintf(
                    'This project is %s and cannot be completed.',
                    $project->statusLabel()
                ));
        }

        $completion = app(ProjectCompletion::class);

        $validated = $request->validate($completion->rules(), $completion->messages());

        // What the completion rules object to - open tasks, a project with no
        // work recorded on it, one that is paused. A lead technician is simply
        // refused; an administrator may go ahead, but only by saying why. The
        // rules used to be asked on the technician's route and nowhere else,
        // so from this page they were not applied at all.
        $blockers = app(ProjectPolicy::class)->blockersFor($project);
        $overrideReason = trim((string) ($validated['completion_override_reason'] ?? ''));

        if ($blockers !== [] && $overrideReason === '') {
            return redirect()
                ->route('super-admin.projects.show', $id)
                ->withInput()
                ->with('error', sprintf(
                    'This project is not ready to be completed. %s Give a reason to complete it anyway.',
                    implode(' ', $blockers)
                ));
        }

        try {
            DB::transaction(function () use ($validated, $project, $request, $completion, $blockers): void {
                $completion->requestCompletion(
                    $project,
                    $validated,
                    $request->file('completion_photos'),
                    $request->user(),
                    $blockers
                );
            });

            $this->announceCompletionRequest($project, $completion, $blockers, $overrideReason);

            return redirect()
                ->route('super-admin.projects')
                ->with('success', sprintf(
                    'Completion recorded. %s completes automatically in %d days unless the client replies.',
                    $project->reference_no ?? $project->name,
                    Project::COMPLETION_CONFIRMATION_DAYS
                ));
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.projects')
                ->with('error', $this->safeErrorMessage($e, 'Unable to record completion. Nothing was saved.'));
        }
    }

    /**
     * Put a project that is waiting on its client back to work.
     *
     * Admin and Super Admin only, and only from Awaiting Client Confirmation -
     * ProjectReopen refuses a Completed project by name, so the rule holds
     * whatever page the request came from.
     *
     * A reopen is a new schedule, not a status change: the dates released at
     * completion were free for other work from that moment, and the
     * administrator has to say when the remaining work actually happens.
     */
    public function reopen(Request $request, int $id)
    {
        $project = Project::with(['schedules', 'projectTechnicians'])->findOrFail($id);
        $back = redirect()->route('super-admin.projects.show', $id);

        if (! $project->canBeReopened()) {
            return $back->with('error', $project->isCompleted()
                ? 'Completed projects cannot be reopened - create a new project instead.'
                : sprintf('Only a project awaiting client confirmation can be reopened. This one is %s.', $project->statusLabel()));
        }

        $scheduleRules = app(ScheduleModeRules::class);

        $validated = $request->validate([
            'reopen_reason' => ['required', 'string', 'min:10', 'max:500'],
            ...$scheduleRules->rules(),
        ], [
            'reopen_reason.required' => 'Enter a reason for reopening.',
            'reopen_reason.min' => 'Describe the reason in at least 10 characters.',
            ...$scheduleRules->messages(),
        ]);

        // Interpreted by the same rules every other scheduling screen uses, so
        // a partial day means here exactly what it means on the calendar.
        $validator = Validator::make([], []);

        $entry = $scheduleRules->validateEntry(
            $validator,
            $request->only(['scheduling_mode', 'start_date', 'end_date', 'project_date', 'start_time', 'end_time']),
            '',
            $project->isResidential()
        );

        if (! $entry) {
            return $back->withInput()->with('error', $validator->errors()->first()
                ?: 'Unable to read that schedule. The project was not reopened.');
        }

        $reopen = app(ProjectReopen::class);
        $reason = trim($validated['reopen_reason']);
        $previousStatus = $project->statusLabel();
        $schedule = null;

        try {
            DB::transaction(function () use ($reopen, $project, $entry, $reason, $request, &$schedule): void {
                $schedule = $reopen->reopen($project, $entry, $reason, $request->user());
            });
        } catch (Throwable $e) {
            // Nothing was written: the schedule and the status change share one
            // transaction, so the project cannot be left Ongoing without dates.
            return $back->withInput()->with('error', $this->safeErrorMessage($e, 'Unable to reopen project. Nothing was saved.'));
        }

        $ranges = $schedule->describe();

        $this->activityLogger->record(
            ActivityLog::PROJECT_REOPENED,
            null,
            sprintf(
                "Reopened project '%s' (%s -> Ongoing) and scheduled it for %s. Reason: %s",
                $project->reference_no ?? $project->name,
                $previousStatus,
                $ranges,
                $reason
            ),
            $project
        );

        $this->notifications->projectReopened($project, $reason);
        $this->notifications->projectReopenedSchedule($project, $ranges);
        $this->clientEmails->projectReopened($project->refresh());

        return $back->with('success', sprintf(
            'Project reopened and scheduled for %s.',
            $ranges
        ));
    }

    /**
     * The days the Reopen dialog's date pickers must grey out.
     *
     * Two refusals stand between a reopen and its new dates, and this is both
     * of them drawn on a calendar rather than discovered after pressing the
     * button:
     *
     *   ProjectReopen::assertTeamAvailable()  every technician on the team has
     *       to be free for the whole of the new range. Asked of the same
     *       service the reopen itself asks, with this project's own bookings
     *       left out exactly as they are there - so the project can never read
     *       as its own blocker.
     *
     *   ProjectReopen::assertNoSelfOverlap()  the new dates may not land on
     *       the days the project kept. Those are days the crew actually
     *       worked, and they are excluded from the availability answer above,
     *       so they are added back here. A whole-day booking of its own takes
     *       every hour of the days it covers and so blocks an hours-only
     *       reopen too; a partial-day one leaves the rest of that day open.
     *
     * The picker is a convenience either way: reopen() re-runs both checks on
     * whatever arrives, so a date typed past a greyed-out one is still
     * refused.
     *
     * @return array{whole_day: array<int, string>, partial_day: array<int, string>}
     */
    private function reopenBlockedDates(Project $project): array
    {
        $from = Schedule::businessToday();
        $to = $from->addMonths(self::REOPEN_PICKER_HORIZON_MONTHS);

        $technicianIds = $project->projectTechnicians
            ->pluck('technician_id')
            ->filter()
            ->unique()
            ->values();

        $blocked = $technicianIds->isEmpty()
            ? ['whole_day' => [], 'partial_day' => []]
            : app(TechnicianAvailabilityService::class)->blockedDatesInWindow(
                $technicianIds,
                $from,
                $to,
                (int) $project->project_id
            );

        $wholeDay = array_flip($blocked['whole_day']);
        $partialDay = array_flip($blocked['partial_day']);

        foreach ($project->schedules as $schedule) {
            $start = $schedule->startsOn();
            $end = $schedule->endsOn();

            for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                if ($day->lt($from) || $day->gt($to)) {
                    continue;
                }

                $date = $day->toDateString();
                $wholeDay[$date] = true;

                if (! $schedule->isPartialDay()) {
                    $partialDay[$date] = true;
                }
            }
        }

        $wholeDay = array_keys($wholeDay);
        $partialDay = array_keys($partialDay);

        sort($wholeDay);
        sort($partialDay);

        return ['whole_day' => $wholeDay, 'partial_day' => $partialDay];
    }

    /**
     * Everything that happens because completion was requested, as opposed to
     * everything that records it.
     *
     * Kept apart from complete() and unable to fail it, for the same reason
     * announceNewProject() is: the transaction has already committed, and a
     * mail server being down is not a reason to tell an administrator that the
     * completion they just recorded did not happen.
     */
    private function announceCompletionRequest(
        Project $project,
        ProjectCompletion $completion,
        array $overriddenBlockers = [],
        string $overrideReason = ''
    ): void {
        try {
            $this->activityLogger->record(
                ActivityLog::PROJECT_COMPLETION_REQUESTED,
                null,
                sprintf(
                    "Marked project '%s' complete as of %s and sent it for client confirmation "
                        .'(Ongoing -> Awaiting Client Confirmation). Its schedule now holds %s.',
                    $project->reference_no ?? $project->name,
                    $project->completed_at?->format('F j, Y') ?? 'today',
                    $completion->describeRemainingSchedule($project)
                ),
                $project
            );

            // An entry of its own, so "which projects were closed with work
            // still open, and on whose say-so?" is a question the log can be
            // filtered for rather than read for.
            if ($overriddenBlockers !== [] && $overrideReason !== '') {
                $this->activityLogger->record(
                    ActivityLog::PROJECT_COMPLETION_OVERRIDDEN,
                    null,
                    sprintf(
                        "Completed project '%s' over the completion rules. Objections: %s Reason given: %s",
                        $project->reference_no ?? $project->name,
                        implode(' ', $overriddenBlockers),
                        $overrideReason
                    ),
                    $project
                );

                // The people who run the system are told, because an override
                // is the sort of thing somebody should be able to notice
                // without going looking for it.
                $this->notifications->projectCompletionOverridden(
                    $project,
                    $overriddenBlockers,
                    $overrideReason
                );
            }

            $this->notifications->projectAwaitingClientConfirmation($project);
            $this->clientEmails->projectAwaitingConfirmation($project->refresh());
        } catch (Throwable $exception) {
            report($exception);
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

        // Work that is finished cannot be cancelled, on either side of the
        // client's reply: cancelling a job that has already been carried out
        // would deny it happened. A project awaiting confirmation that should
        // not have been completed is reopened instead.
        if ($project->isWorkFinished()) {
            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('error', $project->isCompleted()
                    ? 'A completed project cannot be cancelled.'
                    : 'This project is awaiting client confirmation. Reopen it first if the work is not finished.');
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
                ->with('success', 'Project cancelled.');
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('error', $this->safeErrorMessage($e, 'Unable to cancel project. Nothing was changed.'));
        }
    }

    /**
     * Archive a project: take it out of the active list without taking it
     * apart.
     *
     * Archiving used to delete the schedule rows, the schedule-technician
     * links and the project-technician assignments, and blank the dates on
     * every unfinished task. The project came back from the archive as an
     * empty shell - no dates, no crew, no way to tell what it had been - so
     * the archive preserved a record that was missing the half of it anybody
     * would want to look up.
     *
     * Nothing is deleted now. The only columns that move are the archive's
     * own, plus the status - which has to read `archived` because that is what
     * every guard, every listing and every availability query already asks
     * for. What the status was is recorded in pre_archive_status so that
     * restoring can put it back rather than guess.
     *
     * Keeping the schedule does not keep the technicians booked: the
     * availability checker counts only Pending and Ongoing work that is not
     * archived - see Project::ACTIVE_PROJECT_STATUSES and
     * TechnicianAvailabilityService - so an archived project's dates stop
     * occupying anybody the moment it is archived, exactly as a cancelled
     * project's already do. That is why cancel() has never deleted anything
     * either, and archive now matches it.
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
                    // What to come back as. Read before the update, which is
                    // the only moment it is still the project's own status.
                    'pre_archive_status' => $project->status,
                    'archived_at' => now(),
                    'archived_by' => $request->user()?->id,
                ]);

                // Schedule rows, technician assignments and task dates are all
                // left exactly as they are. A hold is left alone too: it is
                // somebody's decision about the work, not archive metadata,
                // and a project archived while paused comes back paused.
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
                ->with('success', 'Project archived.');
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.projects')
                ->with('error', $this->safeErrorMessage($e, 'Unable to archive project. Nothing was changed.'));
        }
    }

    /**
     * Restore an archived project to the state it was archived in.
     *
     * Restore is not Reopen and does not borrow any of its behaviour. Reopen
     * takes a project that is waiting on its client and books it onto NEW
     * dates, because the dates it had were released and given away. Restore
     * takes a project that was only ever put to one side and puts it back:
     * same status, same schedule, same crew, same tasks. Nothing is created
     * and nothing is cleared.
     *
     * A restored Completed project is Completed. A restored Cancelled project
     * is Cancelled. Neither becomes active or scheduled merely by coming back,
     * and neither is turned into Unscheduled - which is what this used to do
     * to every project it touched, because there was nothing left to restore
     * to by the time it ran.
     *
     * The one thing that can refuse it is the calendar. An archived project's
     * dates stop occupying its technicians while it sits in the archive, so
     * another project may have booked those people over them in the meantime.
     * Putting the project back into force would then double-book somebody with
     * nobody told, the clash invisible on both projects because each one only
     * ever shows its own dates. So the same question Resume asks is asked
     * here, of the same service, before anything is written - see
     * restoreConflictMessage().
     *
     * Only a project whose dates would actually come back into force has to be
     * asked. Completed, cancelled and paused work holds nobody either way, so
     * there is nothing for it to collide with.
     */
    public function restore(int $id)
    {
        $project = Project::with(['schedules', 'projectTechnicians'])->findOrFail($id);

        if (! $project->isArchived()) {
            return redirect()
                ->route('super-admin.projects.archived')
                ->with('error', 'Only archived projects can be restored.');
        }

        // Null for anything archived by the old flow, which left no schedule
        // and no team behind: Unscheduled is then not a downgrade, it is the
        // truth about a project with no dates.
        $restoredStatus = $project->statusToRestore() ?? 'unscheduled';

        if ($project->restoreWouldClaimDates() && ($clash = $this->restoreConflictMessage($project))) {
            // Nothing is written and nothing is offered instead. Moving the
            // other work or taking somebody off this team are both decisions
            // with consequences for a person's week, and neither is a restore's
            // to make - the same reason Resume refuses rather than resolves.
            return redirect()
                ->route('super-admin.projects.archived')
                ->with('error', $clash);
        }

        try {
            DB::transaction(function () use ($project, $restoredStatus): void {
                $project->update([
                    'status' => $restoredStatus,
                    'is_archived' => false,
                    'archived_at' => null,
                    'archived_by' => null,
                    'pre_archive_status' => null,
                ]);

                // Schedules, technician assignments and tasks are untouched:
                // they were never taken away, so there is nothing to put back.
                //
                // The one adjustment is to the three statuses the calendar
                // owns. A project archived as Pending whose first booked day
                // has arrived in the meantime is Ongoing now, and this is the
                // same rule the projects listing applies to every other row.
                // ProjectStatusRules leaves Completed, Cancelled, Awaiting and
                // held work alone, so a decision somebody made is never
                // overwritten by one the dates imply.
                $this->syncStatusWithSchedule($project);
            });

            $this->activityLogger->record(
                ActivityLog::PROJECT_RESTORED,
                null,
                sprintf(
                    "Restored project '%s' from the archive as %s, with its schedule and team intact.",
                    $project->reference_no,
                    $project->refresh()->statusLabel()
                ),
                $project
            );

            $this->notifications->projectRestored($project);

            return redirect()
                ->route('super-admin.projects')
                ->with('success', sprintf(
                    'Project restored as %s, with its original schedule and team.',
                    $project->statusLabel()
                ));
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.projects.archived')
                ->with('error', $this->safeErrorMessage($e, 'Unable to restore project. Nothing was changed.'));
        }
    }

    /**
     * Why this project's preserved schedule cannot come back into force, or
     * null when it can.
     *
     * The same question, the same service and the same wording as
     * resumeConflictMessage(), because it is the same situation: dates that
     * were not occupying anybody are about to start occupying them again, and
     * the only thing that can have changed underneath is other work.
     *
     * The project's own bookings are excluded, which is what makes the
     * question answerable at all - every day being checked is one it holds
     * itself, so without the exclusion a project would always report itself as
     * its own blocker. A date reported here therefore always belongs to
     * somebody else's work.
     */
    private function restoreConflictMessage(Project $project): ?string
    {
        // Read from the table rather than from a loaded relation: the rows are
        // what the restore is about, and a relation loaded earlier in the
        // request would be a picture rather than the record.
        $schedules = Schedule::query()
            ->where('project_id', $project->project_id)
            ->get();

        $technicianIds = ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->pluck('technician_id')
            ->map(fn ($technicianId): int => (int) $technicianId)
            ->unique()
            ->values();

        if ($schedules->isEmpty() || $technicianIds->isEmpty()) {
            return null;
        }

        $availability = app(TechnicianAvailabilityService::class);

        $conflicts = $availability->findConflicts(
            $technicianIds,
            $schedules->map(fn (Schedule $schedule): array => $schedule->toAvailabilityRange())->all(),
            (int) $project->project_id
        );

        if ($conflicts->isEmpty()) {
            return null;
        }

        return 'Unable to restore - the dates this project still holds are now booked elsewhere. '
            .$availability->conflictMessage(
                $conflicts,
                ' Reschedule that work or remove them from this team, then restore it again.'
            );
    }

    /**
     * Why this project cannot be resumed yet, or null when it can.
     *
     * A held project blocks nobody. Its status is not one the availability
     * checker counts - see Project::ACTIVE_PROJECT_STATUSES - so the days the
     * cutoff kept read as free the moment the hold is placed, and another
     * project may be booked over them. That is the point: a pause that went on
     * holding the calendar would be a pause in name only.
     *
     * The price is that resuming is not a private matter. It puts those days
     * back into force, and if the crew was promised elsewhere on one of them
     * the project comes back double-booked with nobody told - the clash is
     * invisible on both projects, because each one only ever shows its own
     * dates. So the resume asks the same question a reschedule asks, of the
     * same service, before it lifts anything: are these people still free on
     * the days this project is about to claim again?
     *
     * The project's own bookings are excluded, which is what makes the
     * question answerable at all - every day being checked is one it holds
     * itself. A date reported here therefore always belongs to other work.
     *
     * Refusing rather than resuming-and-warning is deliberate. The two ways
     * out - move the other project, or take the technician off this one - are
     * both decisions with consequences for somebody's day, and neither is the
     * resume's to make.
     */
    private function resumeConflictMessage(Project $project): ?string
    {
        // Read fresh: the cutoff deleted rows when the hold was placed, and a
        // relation loaded before that would be measured instead of what the
        // project actually still holds.
        $schedules = Schedule::query()
            ->where('project_id', $project->project_id)
            ->get();

        $technicianIds = ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->pluck('technician_id')
            ->map(fn ($technicianId): int => (int) $technicianId)
            ->unique()
            ->values();

        if ($schedules->isEmpty() || $technicianIds->isEmpty()) {
            return null;
        }

        $availability = app(TechnicianAvailabilityService::class);

        $conflicts = $availability->findConflicts(
            $technicianIds,
            $schedules->map(fn (Schedule $schedule): array => $schedule->toAvailabilityRange())->all(),
            (int) $project->project_id
        );

        if ($conflicts->isEmpty()) {
            return null;
        }

        return 'Unable to resume - the days this project still holds are now booked elsewhere. '
            .$availability->conflictMessage(
                $conflicts,
                ' Reschedule that work or remove them from this team.'
            );
    }

    /**
     * Give a paused project's remaining dates back without breaking up its
     * crew.
     *
     * A hold is a pause, not an ending: the work is expected to resume, and
     * the same people are expected to do it. So the team stays exactly as
     * assigned, ready for the project to be rescheduled rather than rebuilt,
     * while the days still to come are handed back - those were promises about
     * dates that are no longer promised, and the crew must read as free for
     * other work on them.
     *
     * What the hold does NOT touch is the record of days already worked.
     * ScheduleHoldCutoff draws that line at today and keeps everything on the
     * near side of it, the day of the hold included.
     *
     * Tasks keep their technician for the same reason the team does. What they
     * keep of their dates is decided by the days the cutoff left behind: a task
     * sitting inside those days is untouched, and one whose start or deadline
     * fell on a day the hold released goes back to Unassigned, because it is
     * now pointing at a date nobody is booked on.
     *
     * A hold used to blank the dates of EVERY open task, which threw away
     * perfectly good dates inside the days it had just kept. The rule applied
     * here is TaskScheduleRules', the same one the task forms validate against
     * and the same one a reschedule applies - so a date cleared by a hold is
     * exactly a date the form would now refuse.
     *
     * @return array{kept: int, shortened: int, released: int, tasks_unassigned: int}
     */
    private function releaseScheduleOnly(Project $project): array
    {
        $summary = $this->holdCutoff->apply($project);

        // After the cutoff, never before it: the tasks have to be measured
        // against the days the project is actually left holding.
        $summary['tasks_unassigned'] = app(TaskScheduleRules::class)
            ->unassignStrandedDates((int) $project->project_id)
            ->count();

        return $summary;
    }

    /**
     * What the cutoff did, as a sentence for the audit trail.
     *
     * @param  array{kept: int, shortened: int, released: int, tasks_unassigned?: int}  $summary
     */
    private function describeHoldCutoff(array $summary): string
    {
        $parts = [];

        if ($summary['kept'] > 0) {
            $parts[] = sprintf(
                '%d past %s kept',
                $summary['kept'],
                $summary['kept'] === 1 ? 'schedule was' : 'schedules were'
            );
        }

        if ($summary['shortened'] > 0) {
            $parts[] = sprintf(
                '%d %s shortened to today',
                $summary['shortened'],
                $summary['shortened'] === 1 ? 'schedule was' : 'schedules were'
            );
        }

        if ($summary['released'] > 0) {
            $parts[] = sprintf(
                '%d future %s released',
                $summary['released'],
                $summary['released'] === 1 ? 'schedule was' : 'schedules were'
            );
        }

        $sentence = $parts === []
            ? 'It held no schedules.'
            : ucfirst(implode(', ', $parts)).'.';

        // Only mentioned when something actually happened to a task: a hold
        // that stranded nothing should not report a figure of zero.
        $unassigned = (int) ($summary['tasks_unassigned'] ?? 0);

        if ($unassigned > 0) {
            $sentence .= sprintf(
                ' %d task%s no longer fell on a booked day, so %s date%s were unassigned.',
                $unassigned,
                $unassigned === 1 ? '' : 's',
                $unassigned === 1 ? 'its' : 'their',
                $unassigned === 1 ? '' : 's'
            );
        }

        return $sentence;
    }

    /*
     * releaseScheduleAndTechnicians() lived here and deleted a project's
     * schedule rows, its technician assignments and its task dates. Archive
     * and restore were its only callers - cancel() deliberately never used it,
     * because a cancelled project keeps its record and stops occupying
     * technicians by virtue of its status alone. Archive and restore now work
     * the same way, so nothing was left to call it.
     */

    /**
     * Bring a project's status into line with the dates it holds.
     *
     * Delegates to ProjectStatusRules so the wizard, the schedules page, the
     * projects listing and Resume all reach the same answer - each of these
     * used to work it out for itself, and the copies had already drifted.
     */
    private function syncStatusWithSchedule(Project $project): void
    {
        app(ProjectStatusRules::class)->apply($project);
    }

    /**
     * Bring every project's status into line with its dates.
     *
     * Runs when the projects listing is drawn, which is the only place a
     * change of date would otherwise go unnoticed until somebody edited the
     * project. Two things keep it honest: a project on hold, completed,
     * cancelled or archived is not the calendar's to decide and
     * ProjectStatusRules leaves it alone, and a row is only written when the
     * answer actually differs from what is stored.
     */
    public function updateStatus($projects = null)
    {
        $rules = app(ProjectStatusRules::class);

        $projects = $projects instanceof Collection
            ? $projects
            : Project::query()->with('schedules')->get();

        foreach ($projects as $project) {
            $rules->apply($project);
        }
    }

    public function updateAssignedTeam(Request $request, int $id)
    {
        $project = Project::with(['schedules', 'projectTechnicians'])->findOrFail($id);

        if ($project->isReadOnly()) {
            return back()->with('error', 'This project is '.$project->status.' and its team can no longer be edited.');
        }

        // A paused project takes no changes to who is on it. The crew is kept
        // through a hold precisely so the project can be resumed rather than
        // rebuilt, and rearranging it while nobody is working is a decision
        // that belongs after the resume, not before it.
        if ($project->on_hold) {
            return back()->with('error', 'This project is on hold. Resume it before changing its assigned technicians.');
        }

        $validator = Validator::make($request->all(), [
            'lead_tech' => ['required', 'integer', 'exists:tbl_technicians,technician_id'],
            'technicians' => ['nullable', 'array'],
            'technicians.*' => ['integer', 'exists:tbl_technicians,technician_id'],
        ], [
            'lead_tech.required' => 'A lead technician is required.',
        ]);

        // The same three rules the wizard applies - a real Lead Technician,
        // only one of them, and nobody whose account has been switched off.
        // The crew already on the project is passed in so an existing member
        // whose account was disabled after they were assigned does not make
        // the form unsaveable: they can be kept or removed, but not re-added
        // once gone.
        $validator->after(fn (\Illuminate\Validation\Validator $validator) => app(ProjectTeamRules::class)->validate(
            $validator,
            $request->input('lead_tech'),
            (array) $request->input('technicians', []),
            $project->projectTechnicians
                ->pluck('technician_id')
                ->map(fn ($technicianId): int => (int) $technicianId)
                ->all()
        ));

        $validated = $validator->validate();

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

        // Both halves go through ProjectTeam, so somebody added here is booked
        // onto the project's existing dates exactly as they are when they are
        // added from the technician's own schedule page. Written any other way
        // they would sit on the team while still reading as free for those
        // dates, and could be booked onto a second project over them.
        // technician_id => [name, tasks], gathered inside the transaction and
        // told to people after it commits.
        $unassignedWork = [];

        DB::transaction(function () use ($project, $technicianIds, &$unassignedWork): void {
            $project->projectTechnicians()
                ->with('technician.account')
                ->whereNotIn('technician_id', $technicianIds->all())
                ->get()
                ->each(function (ProjectTechnician $assignment) use ($project, &$unassignedWork): void {
                    $released = $this->projectTeam->detach($project, $assignment);

                    if ($released->isNotEmpty()) {
                        $unassignedWork[] = [
                            'name' => $assignment->technician?->name ?? 'A technician',
                            'tasks' => $released,
                        ];
                    }
                });

            $technicianIds->each(function (int $technicianId) use ($project): void {
                $this->projectTeam->attach($project, $technicianId);
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

        // Work the departing technicians were holding does not leave with
        // them, so somebody has to be told it is waiting for an owner.
        foreach ($unassignedWork as $released) {
            $this->notifications->tasksUnassignedByTeamChange(
                $project,
                $released['name'],
                $released['tasks']
            );
        }

        return back()->with('success', 'Assigned team updated.');
    }
}
