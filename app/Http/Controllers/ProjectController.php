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
use App\Rules\NotAnEmployeeEmail;
use App\Services\ActivityLogger;
use App\Services\ClientProjects;
use App\Services\CompletionConfirmability;
use App\Services\ImportableTeamSources;
use App\Services\NotificationService;
use App\Services\ProjectCompletion;
use App\Services\ProjectEmails;
use App\Services\ProjectRegisteredUser;
use App\Services\ProjectReopen;
use App\Services\ProjectScheduleRecovery;
use App\Services\ProjectStatusRules;
use App\Services\ProjectTeam;
use App\Services\ProjectTeamCandidates;
use App\Services\ProjectTeamRules;
use App\Services\ScheduleConsolidation;
use App\Services\ScheduleHoldCutoff;
use App\Services\ScheduleModeRules;
use App\Services\TaskScheduleRules;
use App\Services\TechnicianAvailabilityService;
use App\Services\TechnicianTaskLoad;
use App\Support\BusinessTime;
use App\Support\PersonName;
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
        private readonly ScheduleHoldCutoff $holdCutoff,
        private readonly ProjectRegisteredUser $registeredUsers,
        private readonly CompletionConfirmability $confirmability
    ) {}

    public function index(Request $request)
    {
        $this->updateStatus(); // Call the function to update project statuses

        // `schedules` is eager loaded because isOverdue() reads every range;
        // without it the status column would fire a query per row.
        $projects = Project::query()
            // `technician.account` is loaded because every row now asks
            // needsRecrew(), which reads each assigned technician's account to
            // decide whether they can still sign in. Without it that is two
            // queries per row on the busiest page in the portal.
            // `clients.account` so the confirmability of a project awaiting its
            // client is read off a loaded relation rather than queried per row
            // - see CompletionConfirmability, which the awaiting rows below ask.
            ->with(['clients.account', 'documents', 'schedule', 'schedules', 'projectTypes', 'projectTechnicians.technician.account'])
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
                $project->project_id => $project->isReadOnly() ? [] : $policy->blockersFor($project, $request->user()),
            ])
            ->all();

        // Whether anybody can confirm each project, for the two places the
        // table needs it: the badge beside a project already waiting on its
        // client, and the notice in the completion dialog of one about to be.
        // Asked of every row rather than of the awaiting ones alone, because
        // the dialog has to say so BEFORE the project starts waiting.
        $confirmability = $this->confirmability->statesFor($projects);

        return view('super-admin.projects', compact('projects', 'statusTabs', 'completionBlockers', 'confirmability'));
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
        // hours exist without the list being written out twice. The bounds go
        // with them for the wizard's own availability narrowing, which works in
        // hours rather than in options - one setting, read once.
        $workingHours = Schedule::workingHourOptions();
        $partialDayHours = Schedule::partialDayHourBounds();

        return view('super-admin.createProject', compact(
            'projectTypes',
            'technicians',
            'suggestedTechnicians',
            'otherTechnicians',
            'technicianSchedules',
            'workingHours',
            'partialDayHours'
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
            // Screened against the destination's work still to come. A range
            // it has already finished cannot be staffed differently now, so
            // letting one refuse a technician only hid crews that are in fact
            // free for the dates the project has left. Every remaining range
            // is asked about - see Schedule::upcomingAvailabilityRanges().
            $ranges = Schedule::upcomingAvailabilityRanges($destination->schedules);
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

                $createdBy = $request->user()?->id;

                $selectedTechnicianIds->each(function (int $technicianId) use ($project, $createdBy): void {
                    $this->projectTeam->attach($project, $technicianId, $createdBy);
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

    /**
     * How each recorded action is coloured in the history dialog.
     *
     * Three meanings, not seven: something was gained, something was given up,
     * or something moved. A reader scanning the list is looking for the shape
     * of the change, and a palette with one colour per action name would carry
     * no more information than the action names already do.
     *
     * @var array<string, string>
     */
    private const ENTRY_KINDS = [
        ActivityLog::TECHNICIAN_ASSIGNED => 'added',
        ActivityLog::LEAD_TECHNICIAN_ASSIGNED => 'added',
        ActivityLog::TECHNICIAN_REMOVED => 'removed',
        ActivityLog::PROJECT_RESCHEDULED => 'changed',
        // A hold takes dates away and a resume does not give them back, so
        // both read as a loss rather than as a move.
        ActivityLog::PROJECT_PUT_ON_HOLD => 'removed',
        ActivityLog::PROJECT_RESUMED => 'changed',
    ];

    /**
     * One line saying who joined a project's team and who left it.
     *
     * Names rather than counts. "Added Ana Mendoza; removed Juan Dela Cruz"
     * is the whole of what somebody reading a team history wants; "4
     * technician(s), 1 newly added" - what this used to say - is a
     * description of the form that was submitted rather than of the change it
     * made.
     *
     * A save that changed nothing still records a line: somebody opened the
     * team editor and pressed save, and a trail that silently drops that is a
     * trail with a hole where an action was.
     *
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     */
    private function describeTeamChange(Project $project, array $added, array $removed): string
    {
        $label = $project->reference_no ?: $project->name;

        $parts = [];

        if ($added !== []) {
            $parts[] = 'added '.$this->nameList($added);
        }

        if ($removed !== []) {
            $parts[] = 'removed '.$this->nameList($removed);
        }

        if ($parts === []) {
            return sprintf("Saved the assigned team on '%s' with no change.", $label);
        }

        return sprintf("On '%s': %s.", $label, ucfirst(implode('; ', $parts)));
    }

    /**
     * "Ana Mendoza", "Ana Mendoza and Kevin Lopez", "Ana, Kevin and Juan".
     *
     * @param  array<int, string>  $names
     */
    private function nameList(array $names): string
    {
        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    /**
     * What has changed about a project's team, or about its dates.
     *
     * Two questions the project details page could not answer at all. The
     * panels there show the state of things now - who is on the team, which
     * ranges the project holds - and a state tells you nothing about how it
     * got that way. "Why is this technician not on this any more?" and "who
     * moved these dates?" were both answerable only by reading the whole
     * system activity log and filtering it by eye.
     *
     * Two sources, deliberately:
     *
     *   - the activity log, which records the ACTIONS people took, with who
     *     took them and when;
     *   - for the team, the membership timeline itself (see ProjectTechnician),
     *     which records the SPANS those actions produced.
     *
     * The log says "Michael removed 2 technicians on 14 August"; the timeline
     * says "Juan was on this project from 3 July to 27 August". They answer
     * different halves of the same question and neither is derivable from the
     * other - the log's descriptions are prose written for a human, and the
     * timeline has no actor or reason on it.
     */
    public function history(Request $request, int $id, string $section)
    {
        $project = Project::query()
            ->with([
                'teamHistory.technician.account',
                'teamHistory.joinedBy',
                'teamHistory.removedBy',
            ])
            ->findOrFail($id);

        // The team reads its changes off the spans, which name people for the
        // whole of a project's life; the schedule has no equivalent record and
        // reads the activity log. See membershipEntries().
        $entries = $section === 'team'
            ? $this->membershipEntries($project)
            : $this->activityEntries($project, $section);

        return response()->json([
            'section' => $section,
            'project' => [
                'reference_no' => $project->reference_no,
                'name' => $project->name,
            ],
            'entries' => $entries,
            // Only the team has a second source. A schedule range keeps no
            // record of its own past - it is edited in place - so the log is
            // the whole of what can be said about it.
            'memberships' => $section === 'team'
                ? $this->membershipTimeline($project)
                : [],
        ]);
    }

    /**
     * The actions recorded against this project that belong to one section.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activityEntries(Project $project, string $section): array
    {
        $actions = $section === 'team'
            ? [
                ActivityLog::TECHNICIAN_ASSIGNED,
                ActivityLog::TECHNICIAN_REMOVED,
                ActivityLog::LEAD_TECHNICIAN_ASSIGNED,
            ]
            : [
                ActivityLog::PROJECT_RESCHEDULED,
                // A hold cuts the schedule off at the day it was placed and a
                // resume does not put the released dates back, so both belong
                // in a history of the dates - see ScheduleHoldCutoff.
                ActivityLog::PROJECT_PUT_ON_HOLD,
                ActivityLog::PROJECT_RESUMED,
            ];

        return ActivityLog::query()
            ->where('record_type', 'Project')
            ->where('record_id', $project->project_id)
            ->whereIn('action', $actions)
            ->orderByDesc('created_at')
            ->orderByDesc('activity_log_id')
            ->limit(100)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'kind' => self::ENTRY_KINDS[$log->action] ?? 'changed',
                'action' => $log->action,
                'description' => $log->description,
                'actor' => $log->actor_name ?: 'System',
                'at' => CarbonImmutable::parse($log->created_at)->format(BusinessTime::DATE_TIME),
                'at_iso' => CarbonImmutable::parse($log->created_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The team's changes, taken from the membership spans rather than from the
     * activity log.
     *
     * The log records one line per SAVE - "on PRJ-0019: added Ana Mendoza;
     * removed Juan Dela Cruz" - which is what happened, and it only names
     * people for saves made since that wording was fixed. Everything older
     * says "4 technician(s), 1 newly added", which names nobody at all.
     *
     * The spans have no such gap. Every membership carries when it opened and
     * when it closed, backfilled for rows that predate the columns, so one
     * arrival and one departure per person can be stated exactly however long
     * ago it happened. That is what a reader wants from this panel: not which
     * form was submitted, but who came and went.
     *
     * Both ends of a span become their own entry, so somebody who joined in
     * June and left in August appears twice, in the right two places down the
     * list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function membershipEntries(Project $project): array
    {
        $entries = [];

        foreach ($project->teamHistory as $assignment) {
            $name = $assignment->technician?->name;

            if ($name === null) {
                continue;
            }

            if ($assignment->joined_at !== null) {
                $entries[] = $this->membershipEntry(
                    'added',
                    'Technician Added',
                    $name.' was added to the team.',
                    $assignment->joinedBy?->name,
                    $assignment->joined_at
                );
            }

            if ($assignment->removed_at !== null) {
                $entries[] = $this->membershipEntry(
                    'removed',
                    'Technician Removed',
                    $name.' was removed from the team.',
                    $assignment->removedBy?->name,
                    $assignment->removed_at
                );
            }
        }

        // Newest first, matching the activity entries beside it.
        usort($entries, fn (array $a, array $b): int => strcmp($b['at_iso'], $a['at_iso']));

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipEntry(
        string $kind,
        string $action,
        string $description,
        ?string $actor,
        mixed $at
    ): array {
        $moment = CarbonImmutable::parse($at);

        return [
            'kind' => $kind,
            'action' => $action,
            'description' => $description,
            // Nothing recorded who made the change before joined_by and
            // removed_by existed, and a backfill would have had to guess at an
            // administrator. Said plainly instead.
            'actor' => $actor ?? 'Not recorded',
            'at' => $moment->format(BusinessTime::DATE_TIME),
            'at_iso' => $moment->toIso8601String(),
        ];
    }

    /**
     * Every membership this project has held, as spans.
     *
     * Ordered by who is still here first, then most recently joined - so the
     * current team reads at the top and the people who have left fall below
     * it in the order they arrived.
     *
     * @return array<int, array<string, mixed>>
     */
    private function membershipTimeline(Project $project): array
    {
        return $project->teamHistory
            ->filter(fn (ProjectTechnician $assignment): bool => $assignment->technician !== null)
            ->sortBy([
                fn (ProjectTechnician $a, ProjectTechnician $b): int => ($a->isRemoved() ? 1 : 0) <=> ($b->isRemoved() ? 1 : 0),
                fn (ProjectTechnician $a, ProjectTechnician $b): int => ($b->joined_at?->timestamp ?? 0) <=> ($a->joined_at?->timestamp ?? 0),
            ])
            ->map(fn (ProjectTechnician $assignment): array => [
                'name' => $assignment->technician->name,
                'is_lead' => optional($assignment->technician->account)->role === 'lead_technician',
                'joined_on' => $assignment->joined_at
                    ? CarbonImmutable::parse($assignment->joined_at)->format(BusinessTime::DATE)
                    : null,
                'removed_on' => $assignment->removed_at
                    ? CarbonImmutable::parse($assignment->removed_at)->format(BusinessTime::DATE)
                    : null,
                'added_by' => $assignment->joinedBy?->name,
                'removed_by' => $assignment->removedBy?->name,
                'is_current' => ! $assignment->isRemoved(),
            ])
            ->values()
            ->all();
    }

    /**
     * Connect this project to a Registered User account, or move it to a
     * different one.
     *
     * Administrators only, and stated here as well as on the route: the route
     * group admits Admin and Super Admin, and an endpoint that changes who can
     * read a project's whole history should not depend on a group declaration
     * to say so.
     *
     * The project's client details are not touched. Whoever the job is booked
     * for stays exactly as it is written on the project; this decides which
     * account follows the work on the public website.
     */
    public function updateRegisteredUser(Request $request, int $id)
    {
        $this->guardRegisteredUserManagement($request);

        $project = Project::findOrFail($id);

        $validated = Validator::make($request->all(), [
            'registered_user_id' => ['required', 'integer', 'exists:users,id'],
        ], [
            'registered_user_id.required' => 'Choose a Registered User to assign.',
            'registered_user_id.exists' => 'That account no longer exists.',
        ])->validate();

        $account = User::find($validated['registered_user_id']);

        try {
            $changed = DB::transaction(fn (): bool => $this->registeredUsers->assign($project, $account));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            return back()->with('error', $this->safeErrorMessage($e, 'Unable to save the Registered User. Nothing was changed.'));
        }

        return back()->with('success', $changed
            ? sprintf('%s is now the Registered User on this project.', $account->fullName())
            : sprintf('%s was already the Registered User on this project.', $account->fullName()));
    }

    /**
     * Take the Registered User off this project.
     *
     * Nothing is deleted: the account keeps its details and its other
     * projects, and the project keeps everything it has. Only the connection
     * between the two ends.
     */
    public function removeRegisteredUser(Request $request, int $id)
    {
        $this->guardRegisteredUserManagement($request);

        $project = Project::findOrFail($id);

        try {
            $removed = DB::transaction(fn (): bool => $this->registeredUsers->remove($project));
        } catch (Throwable $e) {
            return back()->with('error', $this->safeErrorMessage($e, 'Unable to remove the Registered User. Nothing was changed.'));
        }

        return back()->with($removed ? 'success' : 'error', $removed
            ? 'Registered User removed. The account and the project were both kept.'
            : 'This project has no Registered User assigned.');
    }

    /**
     * Only an Admin or a Super Admin manages these assignments. A Registered
     * User never reaches this controller at all - the portal is closed to
     * them - and a technician who does may read a project without deciding who
     * else can.
     */
    private function guardRegisteredUserManagement(Request $request): void
    {
        $this->guardAdministratorAction($request);
    }

    /**
     * The administrators' door, asked here as well as on the route.
     *
     * The route group already admits exactly these two roles. It is asked
     * again because these endpoints decide things about a project that nobody
     * else may decide - who follows it, what address it is reachable at, and
     * whether its client has signed it off - and a permission that depends on
     * a group declaration somewhere else is one refactor away from not being a
     * permission at all.
     */
    private function guardAdministratorAction(Request $request): void
    {
        abort_unless(
            in_array($request->user()?->role, User::ADMINISTRATOR_ROLES, true),
            403
        );
    }

    /**
     * Record a confirmation the client gave off the website.
     *
     * Clients confirm by telephone, in person and on paper, and until this
     * existed none of it could be written down: a project whose client had
     * already said the work was finished still sat out the confirmation window
     * as though nobody had answered, and the fact that they had answered was
     * recorded nowhere at all.
     *
     * Nothing here is a second way of completing a project. The confirmation
     * goes through ProjectCompletion::confirm() exactly as the client's own
     * does, ends the project in the same state, and sends the same messages;
     * what differs is only the recorded method, and the channel, note and
     * administrator written beside it.
     */
    public function recordClientConfirmation(Request $request, int $id)
    {
        $this->guardAdministratorAction($request);

        $project = Project::findOrFail($id);
        $back = redirect()->route('super-admin.projects.show', $id);

        // Re-read rather than trusted from the page, for the reason the
        // client's own route re-reads it: the window is days long, and the
        // project may have been auto-completed or reopened in another tab
        // since this form was drawn.
        if (! $project->isAwaitingClientConfirmation()) {
            return $back->with('error', $project->isCompleted()
                ? 'This project is already complete.'
                : sprintf('Only a project awaiting client confirmation can be confirmed. This one is %s.', $project->statusLabel()));
        }

        $completion = app(ProjectCompletion::class);

        $validated = $request->validate(
            $completion->adminConfirmationRules($project),
            $completion->adminConfirmationMessages()
        );

        $administrator = $request->user();

        try {
            DB::transaction(function () use ($completion, $project, $validated, $administrator): void {
                $completion->recordAdminConfirmation($project, $validated, $administrator);
            });
        } catch (Throwable $e) {
            return $back->with('error', $this->safeErrorMessage($e, 'Unable to record the confirmation. Nothing was changed.'));
        }

        $this->announceAdminConfirmation($project, $validated);

        return $back->with('success', sprintf(
            '%s is now complete, recorded as confirmed by the client %s.',
            $project->reference_no ?? $project->name,
            mb_strtolower(Project::CLIENT_CONFIRMATION_CHANNELS[$validated['client_confirmation_channel']])
        ));
    }

    /**
     * The trail and the messages that follow an off-site confirmation.
     *
     * Outside the transaction and inside its own try, exactly as
     * announceCompletionRequest() is: the project is closed by this point, and
     * a mail server that is down must not undo it.
     *
     * @param  array<string, mixed>  $validated
     */
    private function announceAdminConfirmation(Project $project, array $validated): void
    {
        try {
            $this->activityLogger->record(
                ActivityLog::PROJECT_COMPLETION_RECORDED_BY_ADMIN,
                null,
                sprintf(
                    "Recorded the client's confirmation of project '%s', given %s on %s "
                        .'(Awaiting Client Confirmation -> Completed, confirmed by an administrator on the client\'s behalf). '
                        .'Reason given: %s',
                    $project->reference_no ?? $project->name,
                    mb_strtolower(Project::CLIENT_CONFIRMATION_CHANNELS[$validated['client_confirmation_channel']]),
                    CarbonImmutable::parse($validated['client_confirmation_date'])->format(BusinessTime::DATE),
                    $validated['client_confirmation_note']
                ),
                $project
            );

            // The same pair the client's own confirmation sends. A
            // confirmation is a confirmation however it reached the company,
            // and the people watching the project should not have to know
            // which door it came through to hear that the work is signed off.
            $this->notifications->clientConfirmedCompletion($project);
            $this->clientEmails->completionConfirmed($project->refresh());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Put this project's contact address onto the one its Registered User
     * signs in with.
     *
     * Deliberately an action rather than a synchronisation. The two addresses
     * are different facts and are allowed to stay different - a project booked
     * to a company mailbox and followed by a person is the ordinary case - so
     * nothing moves on its own and nothing moves the other way. This is an
     * administrator saying "the project's address is wrong, use the account's",
     * and it changes the project only.
     */
    public function useAccountEmail(Request $request, int $id)
    {
        $this->guardAdministratorAction($request);

        $project = Project::findOrFail($id);

        try {
            $changed = DB::transaction(fn (): ?array => $this->registeredUsers->useAccountEmail($project));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            return back()->with('error', $this->safeErrorMessage($e, 'Unable to update the contact email. Nothing was changed.'));
        }

        if ($changed === null) {
            return back()->with('error', 'This project already uses the account\'s email address.');
        }

        return back()->with('success', sprintf(
            'Project contact email changed from %s to %s. The account was not changed.',
            $changed['previous'],
            $changed['current']
        ));
    }

    public function show(Request $request, int $id)
    {
        $project = Project::with([
            // `clients.account` so the Registered User panel names the account
            // the project is connected to without a query of its own.
            'clients.account',
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

            // The completion cycles this project has already been through, for
            // View Previous Completion Reports. Loaded with everything the
            // dialog prints so a project reopened several times is still one
            // query rather than one per cycle. This is history only - the
            // CURRENT report, if there is one, is on the project row itself.
            'completionReports.photos',
            'completionReports.completionRequestedByUser',
            'completionReports.clientConfirmedByUser',
            'completionReports.completionOverriddenByUser',
            'completionReports.supersededByUser',

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
            : app(ProjectPolicy::class)->blockersFor($project, $request->user());

        // Archiving belongs to the Super Admin, and only to a project the
        // archive will accept - Project::isArchivable() is the same question
        // the endpoint asks, so the button cannot offer what the route refuses.
        $canArchive = (bool) $request->user()?->isSuperAdmin() && $project->isArchivable();

        // Who the project is connected to on the public website, and who it
        // could be connected to instead. Both are administrators' business
        // only - see guardRegisteredUserManagement, which is what the two
        // endpoints behind these controls ask.
        $canManageRegisteredUser = in_array($request->user()?->role, User::ADMINISTRATOR_ROLES, true);
        $assignedRegisteredUser = $project->registeredUserAccount();
        $registeredUserOptions = $canManageRegisteredUser
            ? app(ProjectRegisteredUser::class)->candidates()
            : collect();

        // Whether anybody can confirm this project's completion as things
        // stand - which is not the same question as whether an account is
        // assigned, and is not a decision about the project: see
        // CompletionConfirmability. Asked on every read because the answer
        // changes the moment the client registers.
        $confirmabilityState = $this->confirmability->state($project);
        $confirmabilityHint = $this->confirmability->hint($project);

        // Recording a confirmation the client gave elsewhere. Administrators
        // only and only while the project is actually waiting on one - the
        // endpoint asks both questions again.
        $canRecordClientConfirmation = $canManageRegisteredUser
            && $project->isAwaitingClientConfirmation();

        // The project's contact address and the account's are separate facts
        // and may legitimately differ. This only says that they do, so an
        // administrator is never surprised by which of the two a message went
        // to; nothing is changed unless they ask for it.
        $accountEmailDiffers = $assignedRegisteredUser !== null
            && $this->registeredUsers->accountEmailDiffers($project);

        // Every completion cycle this project has been through, already
        // loaded. Never the current report: that one is read off the project.
        $previousCompletionReports = $project->completionReports;

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
            'completionBlockers',
            'canArchive',
            'previousCompletionReports',
            'canManageRegisteredUser',
            'assignedRegisteredUser',
            'registeredUserOptions',
            'confirmabilityState',
            'confirmabilityHint',
            'canRecordClientConfirmation',
            'accountEmailDiffers'
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
            // An initial, not a name - see PersonName.
            'middle_initial' => PersonName::middleInitialRules(),
            'last_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'contact_number' => ['required', 'regex:/^09\d{9}$/'],
            // The same rule the wizard applies: a project's client may
            // not be one of the staff, whose address is the key their
            // own portal is keyed on.
            'email_address' => ['required', 'email', 'max:255', new NotAnEmployeeEmail],
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
            ...PersonName::middleInitialMessages('middle_initial'),
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
                    // Unscheduled is what releases the crew. It is not one of
                    // Project::ACTIVE_PROJECT_STATUSES, so none of this
                    // project's bookings count against anybody's availability
                    // while the hold stands - which is exactly why the days
                    // still to come can be preserved rather than deleted, and
                    // exactly why resuming has to be screened. See
                    // ScheduleHoldCutoff and ProjectScheduleRecovery.
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
                    Schedule::businessToday()->format(BusinessTime::DATE),
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

    /**
     * Lift the pause on a held project, onto the schedule it kept.
     *
     * A hold no longer throws the remaining dates away - it preserves them as
     * the project's proposed schedule, and releases the crew from them by
     * taking the project out of the statuses availability counts. So resuming
     * is a question rather than a formality: are these people still free for
     * the days this project is about to claim again?
     *
     * It is asked of ProjectScheduleRecovery, the same service Restore asks,
     * which is what lets a clash be resolved here in the same dialog and on
     * the same terms - including a Residential project being put onto a
     * partial day. Nothing is written until it comes back clean: a conflicting
     * range is never silently overwritten, and the two ways out that are not
     * this project's to take - move the other work, or take the technician off
     * this team - stay the administrator's decision.
     */
    public function resume(Request $request, int $id)
    {
        $project = Project::findOrFail($id);

        // Asked here as well as on the route: resuming re-books people's weeks,
        // and a permission enforced by only one of the two layers is one that
        // can be posted straight past.
        if (! $this->mayResume($request)) {
            return $this->resumeRefusal($request, $id, 'Only an administrator can resume a project.');
        }

        if ($project->isReadOnly()) {
            return $this->resumeRefusal(
                $request,
                $id,
                'This project is '.$project->status.' and cannot be resumed.'
            );
        }

        // Resuming something that was never paused is not a no-op: it emails
        // the client that their project has resumed and tells the whole crew.
        if (! $project->on_hold) {
            return $this->resumeRefusal($request, $id, 'This project is not on hold.');
        }

        // Asked on every attempt, after whatever was changed in the dialog, so
        // a resolution worked out against availability read a minute ago is
        // re-tested against availability now.
        $conflicts = app(ProjectScheduleRecovery::class)
            ->report($project, ProjectScheduleRecovery::FLOW_RESUME);

        if ($conflicts['blocked']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => $conflicts['message'],
                    'conflicts' => $conflicts,
                ], 409);
            }

            return redirect()
                ->route('super-admin.projects', $id)
                ->with('error', $conflicts['message']);
        }

        // Everything below is the reactivation, and it happens in one
        // transaction: a resume that stopped half way would leave a project
        // out of its hold with a schedule nobody is booked on, or booked on a
        // schedule whose status still says it is paused.
        DB::transaction(function () use ($project): void {
            // The hold is lifted first, then the schedule is put back into
            // force, and only then is the status worked out - from the dates
            // that are actually there, never assumed.
            $project->update(['on_hold' => false]);
            $project->unsetRelation('schedules');

            // A booking the hold split at the day it was placed is one booking
            // again once the two halves are back in force together: Aug 15-16
            // worked and Aug 17-18 planned is Aug 15-18, and leaving it as two
            // rows would have every screen describe a break between the 16th
            // and the 17th that never happened. The same rule every other
            // writer of a schedule applies - see ScheduleConsolidation.
            app(ScheduleConsolidation::class)->consolidate($project);
            $project->unsetRelation('schedules');

            // Put the crew back onto the ranges that have just come back into
            // force. Asked as "what is missing?" rather than assumed to be
            // nothing, so the step is idempotent and repairs a project whose
            // links had drifted - it is the same question project-team:audit
            // asks and the same answer project-team:repair writes. See
            // ProjectTeam::restoreScheduleLinks().
            $this->projectTeam->restoreScheduleLinks($project);

            // Last, because it reads the schedule this has just settled. A
            // project resumed with nothing ahead of it must not read as work
            // in progress just because the hold kept a record of days already
            // worked - ProjectStatusRules is what tells those apart.
            app(ProjectStatusRules::class)->apply($project);
        });

        $project->refresh();

        $this->notifications->projectResumed($project);
        $this->clientEmails->projectResumed($project);

        $this->activityLogger->record(
            ActivityLog::PROJECT_RESUMED,
            null,
            sprintf("Resumed project '%s'. It is now %s.", $project->reference_no, $project->statusLabel()),
            $project
        );

        $success = sprintf(
            'Project resumed - %s.%s',
            $project->statusLabel(),
            $project->status === 'unscheduled' ? ' Schedule it again.' : ''
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $success,
                'redirect' => route('super-admin.projects.show', $id),
            ]);
        }

        return redirect()
            ->route('super-admin.projects', $id)
            ->with('success', $success);
    }

    /**
     * The state of the calendar for one held project, for the Schedule
     * Conflict dialog.
     *
     * Read when the dialog opens and again on every Recheck, exactly as the
     * archived one is: availability changes underneath a dialog that stays
     * open, so a dialog that trusted what it loaded once would offer a Resume
     * the server then refuses.
     */
    public function resumeConflicts(Request $request, int $id)
    {
        $project = Project::with(['schedules', 'projectTechnicians'])->findOrFail($id);

        if (! $this->mayResume($request)) {
            return response()->json(['error' => 'Only an administrator can resume a project.'], 403);
        }

        if (! $project->on_hold) {
            return response()->json(['error' => 'This project is not on hold.'], 422);
        }

        return response()->json(
            app(ProjectScheduleRecovery::class)->report($project, ProjectScheduleRecovery::FLOW_RESUME)
        );
    }

    /**
     * A resume that cannot go ahead, said the way the caller asked for it.
     *
     * The Resume button posts with fetch so a clash can open the dialog rather
     * than bouncing the page and reducing it to a toast; a browser running no
     * script submits the same form and reads the same flash.
     */
    /**
     * Whether this reader may resume a project, asked here as well as on the
     * route - the same reasoning archive() is guarded by, since hiding a
     * button is not a permission.
     */
    private function mayResume(Request $request): bool
    {
        return (bool) $request->user()?->isEmployee()
            && in_array($request->user()?->role, User::ADMINISTRATOR_ROLES, true);
    }

    private function resumeRefusal(Request $request, int $id, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => $message], 422);
        }

        return redirect()
            ->route('super-admin.projects', $id)
            ->with('error', $message);
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
        $policy = app(ProjectPolicy::class);
        $blockerDetails = $policy->blockerDetailsFor($project, $request->user());
        $blockers = array_column($blockerDetails, 'message');
        // The same objections in a few words each, for the notification's
        // title - see ProjectPolicy::blockerSummariesFor().
        $blockerSummaries = array_column($blockerDetails, 'summary');
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

            $this->announceCompletionRequest(
                $project,
                $completion,
                $blockers,
                $overrideReason,
                $blockerSummaries
            );

            return redirect()
                ->route('super-admin.projects')
                ->with('success', sprintf(
                    'Completion recorded. %s completes automatically in %d days unless the client replies.',
                    $project->reference_no ?? $project->name,
                    Project::completionConfirmationDays()
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
     *
     * The completion report of the cycle being ended is filed as history on
     * the way through - see ProjectCompletionHistory - so the project comes
     * back with no current completion report and nothing is lost.
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
     *       to be free for the whole of the new range, with this project's own
     *       bookings left out exactly as they are there - so the project can
     *       never read as its own blocker.
     *
     *   ProjectReopen::assertNoSelfOverlap()  the new dates may not land on
     *       the days the project kept.
     *
     * Both come from ProjectScheduleRecovery, the same service the Restore and
     * Resume dialogs read, so the three flows cannot disagree about which days
     * are open or about what a partial day may sit on.
     *
     * The picker is a convenience either way: reopen() re-runs both checks on
     * whatever arrives, so a date typed past a greyed-out one is still
     * refused.
     *
     * @return array{whole_day: array<int, string>, partial_day: array<int, string>}
     */
    private function reopenBlockedDates(Project $project): array
    {
        return app(ProjectScheduleRecovery::class)
            ->blockedDatesForNewRange($project, self::REOPEN_PICKER_HORIZON_MONTHS);
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
        string $overrideReason = '',
        array $blockerSummaries = []
    ): void {
        try {
            $this->activityLogger->record(
                ActivityLog::PROJECT_COMPLETION_REQUESTED,
                null,
                sprintf(
                    "Marked project '%s' complete as of %s and sent it for client confirmation "
                        .'(Ongoing -> Awaiting Client Confirmation). Its schedule now holds %s.',
                    $project->reference_no ?? $project->name,
                    $project->completed_at?->format(BusinessTime::DATE) ?? 'today',
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
                    $overrideReason,
                    $blockerSummaries
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

        // Super Admin only. The route already carries `role:super_admin`, and
        // this asks again: the button is drawn from the same question, and a
        // permission that only one of the two layers enforces is a permission
        // that can be posted straight past.
        if (! $request->user()?->isSuperAdmin()) {
            return redirect()
                ->route('super-admin.projects')
                ->with('error', 'Only the Super Admin can archive a project.');
        }

        if ($project->isArchived()) {
            return redirect()
                ->route('super-admin.projects')
                ->with('error', 'This project is already archived.');
        }

        // The same rule the Archive button is drawn by - see
        // Project::isArchivable(). A project still waiting on its client is
        // the one thing it refuses: the answer and the seven-day clock are
        // both still outstanding.
        if (! $project->isArchivable()) {
            return redirect()
                ->route('super-admin.projects')
                ->with('error', sprintf('A %s project cannot be archived.', $project->statusLabel()));
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
     * ProjectScheduleRecovery, the one service all three recovery flows -
     * restore, reopen and resume - ask.
     *
     * Only a project whose dates would actually come back into force has to be
     * asked. Completed, cancelled and paused work holds nobody either way, so
     * there is nothing for it to collide with.
     *
     * The refusal is no longer only a sentence. A caller that asked for JSON -
     * the Archived Projects page does - gets the whole clash back instead:
     * which technician, which other project, which days of each, and which
     * days the two share, so the Schedule Conflict dialog can draw it and
     * offer the two ways out. What it may NOT do is proceed: this check runs
     * on every attempt, after whatever was changed in that dialog, so a
     * resolution worked out against availability read a minute ago is
     * re-tested against availability now. The dialog is a convenience; this is
     * the decision.
     */
    public function restore(Request $request, int $id)
    {
        $project = Project::with(['schedules', 'projectTechnicians'])->findOrFail($id);

        // Super Admin only, asked here as well as on the route. Restoring
        // re-books people's weeks, and a permission enforced by only one of
        // the two layers is a permission that can be posted straight past -
        // the same reasoning archive() is guarded by.
        if (! $request->user()?->isSuperAdmin()) {
            return $this->restoreRefusal(
                $request,
                'Only the Super Admin can restore a project.'
            );
        }

        if (! $project->isArchived()) {
            return $this->restoreRefusal(
                $request,
                'Only archived projects can be restored.'
            );
        }

        // Null for anything archived by the old flow, which left no schedule
        // and no team behind: Unscheduled is then not a downgrade, it is the
        // truth about a project with no dates.
        $restoredStatus = $project->statusToRestore() ?? 'unscheduled';

        // Asked of the same service the Schedule Conflict dialog reads, on
        // every attempt, so the last word belongs to the server and not to
        // whatever the dialog was showing when the button was pressed.
        $conflicts = app(ProjectScheduleRecovery::class)
            ->report($project, ProjectScheduleRecovery::FLOW_RESTORE);

        if ($conflicts['blocked']) {
            // Nothing is written, and nothing is resolved on anybody's behalf.
            // Moving the other work or taking somebody off that team are both
            // decisions with consequences for a person's week, and neither is
            // a restore's to make - the same reason Resume refuses rather than
            // resolves. What is different is that the refusal now hands back
            // everything needed to make either of those decisions.
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => $conflicts['message'],
                    'conflicts' => $conflicts,
                ], 409);
            }

            return redirect()
                ->route('super-admin.projects.archived')
                ->with('error', $conflicts['message']);
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

            $success = sprintf(
                'Project restored as %s, with its original schedule and team.',
                $project->statusLabel()
            );

            // A toast is exactly right for this one: it is the good news, and
            // there is nothing to act on. The page it belongs on is the active
            // Projects list, which is where the project now is.
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $success,
                    'redirect' => route('super-admin.projects'),
                ]);
            }

            return redirect()
                ->route('super-admin.projects')
                ->with('success', $success);
        } catch (Throwable $e) {
            return $this->restoreRefusal(
                $request,
                $this->safeErrorMessage($e, 'Unable to restore project. Nothing was changed.')
            );
        }
    }

    /**
     * A restore that cannot go ahead for a reason that is not a clash.
     *
     * The archive page drives its Restore button with fetch, so a refusal has
     * to arrive as JSON there and as the flash the rest of the portal uses
     * everywhere else.
     */
    private function restoreRefusal(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => $message], 422);
        }

        return redirect()
            ->route('super-admin.projects.archived')
            ->with('error', $message);
    }

    /**
     * The current state of the calendar for one archived project, for the
     * Schedule Conflict dialog.
     *
     * Read twice by that dialog: once when it opens, and again every time
     * somebody presses Recheck after moving a booking or taking a technician
     * off the other project. Availability changes underneath a dialog that
     * stays open - somebody else may book the same person while it is - so a
     * dialog that trusted what it loaded once would offer a Restore that the
     * server then refuses. This is the same report restore() itself runs, so
     * the two can never disagree about what is in the way.
     */
    public function restoreConflicts(Request $request, int $id)
    {
        $project = Project::with(['schedules', 'projectTechnicians'])->findOrFail($id);

        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(['error' => 'Only the Super Admin can restore a project.'], 403);
        }

        if (! $project->isArchived()) {
            return response()->json(['error' => 'Only archived projects can be restored.'], 422);
        }

        return response()->json(
            app(ProjectScheduleRecovery::class)->report($project, ProjectScheduleRecovery::FLOW_RESTORE)
        );
    }

    /**
     * Move or drop one range of an archived project's schedule, from the
     * Schedule Conflict dialog.
     *
     * The archived project's own range is what moves here - see
     * ProjectScheduleRecovery::resolveRange(), which owns every rule that
     * decides whether the new range is allowed and is the same code the Resume
     * dialog's endpoint runs.
     *
     * The schedules page cannot do this: an archived project is read-only
     * there, and rightly so - it is not on the calendar.
     */
    public function updateRestoreSchedule(Request $request, int $id)
    {
        $project = Project::with(['schedules', 'projectTechnicians'])->findOrFail($id);

        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(['error' => 'Only the Super Admin can restore a project.'], 403);
        }

        if (! $project->isArchived()) {
            return response()->json([
                'error' => "Only an archived project's schedule can be changed from here.",
            ], 422);
        }

        return $this->applyRecoveryRange(
            $request,
            $project,
            ProjectScheduleRecovery::FLOW_RESTORE,
            'while resolving its restore'
        );
    }

    /**
     * Move or drop one range of a held project's proposed schedule, from the
     * Schedule Conflict dialog the Resume button opens.
     *
     * The same endpoint as the archived one in everything but which project it
     * will accept: both hand the request straight to
     * ProjectScheduleRecovery::resolveRange(), so a Residential project may be
     * put onto a partial day here on exactly the terms it may there, and a
     * Commercial one may not on either.
     */
    public function updateResumeSchedule(Request $request, int $id)
    {
        $project = Project::with(['schedules', 'projectTechnicians'])->findOrFail($id);

        if (! $this->mayResume($request)) {
            return response()->json(['error' => 'Only an administrator can resume a project.'], 403);
        }

        if (! $project->on_hold) {
            return response()->json(['error' => 'This project is not on hold.'], 422);
        }

        if ($project->isReadOnly()) {
            return response()->json([
                'error' => 'This project is '.$project->statusLabel().' and its schedule cannot be changed.',
            ], 422);
        }

        return $this->applyRecoveryRange(
            $request,
            $project,
            ProjectScheduleRecovery::FLOW_RESUME,
            'while resolving its resume'
        );
    }

    /**
     * One range of a recovery's schedule, changed or dropped, then the whole
     * schedule screened again.
     *
     * Shared by Restore and Resume rather than written twice: the two differ
     * only in who may call them and which project they will accept, and every
     * rule about what a range may become is one service's answer - see
     * ProjectScheduleRecovery. The recheck at the end is why nothing here
     * assumes a save cleared anything: an edit can resolve one range and walk
     * another into trouble, and the dialog has to be told about the schedule
     * rather than about the range it just saved.
     */
    private function applyRecoveryRange(
        Request $request,
        Project $project,
        string $flow,
        string $context
    ) {
        $recovery = app(ProjectScheduleRecovery::class);
        $scheduleRules = app(ScheduleModeRules::class);

        $validated = $request->validate([
            'schedule_id' => ['required', 'integer'],
            'action' => ['nullable', 'in:update,remove'],
            ...$scheduleRules->rules(),
        ], $scheduleRules->messages());

        try {
            DB::transaction(function () use ($recovery, $project, $validated, $context): void {
                $outcome = $recovery->resolveRange($project, $validated);

                $this->activityLogger->record(
                    ActivityLog::PROJECT_RESCHEDULED,
                    null,
                    $outcome['action'] === 'remove'
                        ? sprintf(
                            "Removed the %s range from project '%s' %s.",
                            $outcome['before'],
                            $project->reference_no ?? $project->name,
                            $context
                        )
                        : sprintf(
                            "Changed a schedule range on project '%s' from %s to %s %s.",
                            $project->reference_no ?? $project->name,
                            $outcome['before'],
                            $outcome['after'],
                            $context
                        ),
                    $project
                );

                // Tasks are deliberately left where they are. This endpoint
                // resolves a clash on the calendar; what a task points at is
                // settled when the project actually comes back.
            });
        } catch (Throwable $e) {
            return response()->json([
                'error' => $this->safeErrorMessage($e, 'Unable to change that schedule range. Nothing was changed.'),
            ], 422);
        }

        return response()->json(
            $recovery->report($project->fresh(['schedules', 'projectTechnicians']), $flow)
        );
    }

    /**
     * Divide a paused project's schedule at the day of the hold, without
     * breaking up its crew.
     *
     * A hold is a pause, not an ending: the work is expected to resume, and
     * the same people are expected to do it. So the team stays exactly as
     * assigned, and the days still to come are PRESERVED rather than thrown
     * away - they are what the project intends to do next, and Resume is what
     * asks whether the calendar can still honour them.
     *
     * Preserving them does not hold anybody to them. The crew is released by
     * the status change the hold makes, not by deleting rows: a held project
     * is Unscheduled, which is not one of Project::ACTIVE_PROJECT_STATUSES, so
     * its bookings stop counting against availability the moment the hold is
     * placed and those days are free for other work. See ScheduleHoldCutoff.
     *
     * What the hold does NOT touch is the record of days already worked. The
     * cutoff draws that line at today and leaves everything on the near side
     * of it exactly as it stands, the day of the hold included.
     *
     * Tasks keep their technician for the same reason the team does, and now
     * keep their dates too: the days those dates point at are still on the
     * project. The rule applied here is TaskScheduleRules', the same one the
     * task forms validate against - so a date cleared by a hold is exactly a
     * date the form would now refuse, which after the change above is only a
     * date that was already stranded.
     *
     * @return array{kept: int, shortened: int, preserved: int, tasks_unassigned: int}
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
     * @param  array{kept: int, shortened: int, preserved: int, tasks_unassigned?: int}  $summary
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
                '%d %s split at today',
                $summary['shortened'],
                $summary['shortened'] === 1 ? 'schedule was' : 'schedules were'
            );
        }

        if ($summary['preserved'] > 0) {
            $parts[] = sprintf(
                '%d future %s preserved as the proposed schedule',
                $summary['preserved'],
                $summary['preserved'] === 1 ? 'schedule was' : 'schedules were'
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

        // Every one of the project's schedules that has not finished has to be
        // checked, not just the first one and not just the overall span, and
        // every day inside each range - a technician who is free on the
        // endpoints but booked mid-range must still be rejected. A partial-day
        // schedule only asks about its own hours, so joining a team booked for a
        // morning does not require the whole day to be free.
        //
        // The ranges that have ended are left out, and only those. Adding
        // somebody to a team is a decision about the work still to come, so a
        // week this project spent in August has no say in who may be put on it
        // for September - screening against it refused technicians over dates
        // that cannot be staffed differently now. A range that started before
        // today keeps the days it has left, because those are still a real
        // claim. The picker draws on the same rule through
        // ProjectTeamCandidates, so what it offers is what this accepts.
        $ranges = Schedule::upcomingAvailabilityRanges($project->schedules);

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

        $removedBy = $request->user()?->id;

        // Who actually came off and who actually went on, by name, gathered as
        // it happens so the audit line can say so. It used to read "4
        // technician(s), 1 newly added", which is a count of the outcome
        // rather than a record of the change: it named nobody, so the one
        // question a team history is opened to answer - who left, and when -
        // could not be answered from it at all.
        $removedNames = [];

        DB::transaction(function () use ($project, $technicianIds, $removedBy, &$unassignedWork, &$removedNames): void {
            $project->projectTechnicians()
                ->with('technician.account')
                ->whereNotIn('technician_id', $technicianIds->all())
                ->get()
                ->each(function (ProjectTechnician $assignment) use ($project, $removedBy, &$unassignedWork, &$removedNames): void {
                    $released = $this->projectTeam->detach($project, $assignment, $removedBy);

                    $removedNames[] = $assignment->technician?->name ?? 'A technician';

                    if ($released->isNotEmpty()) {
                        $unassignedWork[] = [
                            'name' => $assignment->technician?->name ?? 'A technician',
                            'tasks' => $released,
                        ];
                    }
                });

            $technicianIds->each(function (int $technicianId) use ($project, $removedBy): void {
                // Same actor as the removals above: one save, one person
                // behind every change in it.
                $this->projectTeam->attach($project, $technicianId, $removedBy);
            });
        });

        // Fetched as models and mapped rather than plucked: Technician::$name
        // is an accessor over the linked account (see getNameAttribute), not a
        // column, so pluck('name') selects a field the table does not have.
        $addedNames = Technician::query()
            ->with('account')
            ->whereIn('technician_id', $newlyAddedIds->all())
            ->orderBy('technician_id')
            ->get()
            ->map(fn (Technician $technician): string => $technician->name)
            ->all();

        $this->activityLogger->record(
            ActivityLog::TECHNICIAN_ASSIGNED,
            null,
            $this->describeTeamChange($project, $addedNames, $removedNames),
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
