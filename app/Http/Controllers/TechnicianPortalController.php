<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\TaskImage;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\TechnicianReportImage;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\ProjectCompletion;
use App\Services\ProjectEmails;
use App\Services\ProjectMaterialLedger;
use App\Services\ProjectMaterialRules;
use App\Services\ProjectPhaseProgress;
use App\Services\ProjectPhaseRules;
use App\Services\TaskAssignmentGaps;
use App\Services\TaskAssignmentRules;
use App\Services\TaskBoard;
use App\Services\TaskPhaseRules;
use App\Services\TaskScheduleRules;
use App\Services\TechnicianTaskLoad;
use App\Support\BusinessTime;
use App\Support\ServerTable;
use App\Support\UploadStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The technician portal, shared by both technician roles.
 *
 * One set of pages serves them: a calendar beside a detail panel, and
 * DataTables everywhere else, laid out the way the Super Admin portal lays its
 * own out. What differs is reach, not layout.
 *
 *   - A technician reads their assignments and completes their own tasks.
 *     Nothing else on these pages is theirs to touch.
 *   - A lead additionally runs the task board, files reports and closes
 *     projects, and gets a Reports page of their own.
 *
 * That difference is decided by ProjectPolicy / TaskPolicy, which the views
 * consult, rather than by giving the two roles separate screens - so there is
 * one place to read the rules from and one page to keep working.
 *
 * Every query is anchored to the signed-in account's technician record, so a
 * technician can only ever reach their own work.
 */
class TechnicianPortalController extends Controller
{
    /**
     * Statuses that keep a project off the portal entirely: finished and
     * abandoned work is not an assignment anyone still owes anything on.
     *
     * @var array<int, string>
     */
    private const HIDDEN_STATUSES = ['cancelled', 'archived'];

    /**
     * Why a lead's report is refused on a day the project is not booked for,
     * and the note the pages show in place of the button - see
     * ProjectPolicy::submitReport().
     */
    public const REPORT_NOT_SCHEDULED_TODAY = 'Reports only on scheduled days.';

    public function __construct(
        private TaskScheduleRules $scheduleRules,
        private readonly TaskAssignmentRules $assignmentRules,
        private ProjectPolicy $projectPolicy,
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
    ) {}

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    /**
     * My Schedule: a calendar of every date this technician was booked for,
     * with the clicked project's details loaded into the panel beside it.
     *
     * Built from the bookings rather than from the current team, and that
     * distinction is the whole point of this page. My Projects answers "what
     * am I on?" and drops a project the moment somebody takes you off it. This
     * answers "where was I, and where am I going to be?", and the days you
     * worked in July are still days you worked whatever happened in August.
     * Reading both from the same source is what made them unable to disagree.
     *
     * A booking that outlives the membership is drawn as a record - see
     * calendarEvent() - and its panel says so rather than opening the project.
     */
    public function schedule(Request $request)
    {
        $technician = $this->technician($request);
        $projects = $this->assignedProjects($technician);

        // One bar per span of theirs on a range: somebody taken off a project
        // part-way through a booking and put back on before it ended worked
        // two stretches of it, and each is drawn as the days it covers.
        //
        // A closed span is only a record once they are off the project for
        // good. Somebody on days off, with a return booked, is still on the
        // team: their earlier stretch keeps the project's colour and still
        // opens the project.
        $stillOnTeam = $projects->pluck('project_id')->map(fn ($id): int => (int) $id)->all();

        $events = $this->bookedSchedules($technician)
            ->flatMap(fn (Schedule $schedule): Collection => $this->membershipsFor($schedule, $technician)
                ->map(fn (ProjectTechnician $membership): ?array => $this->calendarEvent(
                    $schedule->project,
                    $schedule,
                    $membership,
                    in_array((int) $schedule->project_id, $stillOnTeam, true)
                ))
                ->filter())
            ->values();

        $today = BusinessTime::today();

        return view('technician.schedule', [
            'events' => $events,
            'activeCount' => $this->countProjects(
                $projects,
                fn (CarbonImmutable $start, CarbonImmutable $end): bool => $start->lte($today) && $end->gte($today)
            ),
            'upcomingCount' => $this->countProjects(
                $projects,
                fn (CarbonImmutable $start, CarbonImmutable $end): bool => $start->gt($today)
            ),
        ]);
    }

    /**
     * My Projects: every assignment as a DataTable, each row opening the
     * read-only project view.
     */
    public function projects(Request $request)
    {
        // Cancelled work is included here and nowhere else in the portal: the
        // status tabs offer it, and a lead should be able to look back at a
        // job that was called off.
        $projects = $this->assignedProjects($this->technician($request), ['archived']);

        // The rows themselves arrive a page at a time from projectsTable().
        // Completion blockers are deliberately not rendered at all: the view
        // modal can complete a task, which changes them, so the confirmation
        // dialog fetches them when it opens instead.
        return view('technician.projects', [
            'assignedCount' => $projects->count(),
            // The same tabs and the same counts the administrative projects
            // table draws, from the same method - so "3 Ongoing" here means
            // exactly what it means there.
            'statusTabs' => Project::statusTabs($projects),

            // Urgent Actions, for the one role in this portal that can do
            // something about it. A lead has no dashboard, so the projects
            // whose phases nobody has settled are named here - see
            // x-phase-setup-alert. Empty for a plain technician, who cannot
            // set a structure up and would only be shown work they cannot do.
            'phaseSetupProjects' => $request->user()->isLeadTechnician()
                ? $projects->filter(fn (Project $project): bool => $project->needsPhaseSetup()
                    && ! $project->isReadOnly()
                    && ! $project->isArchived())->values()
                : collect(),
        ]);
    }

    /**
     * One page of My Projects, for serverTable.js.
     *
     * Paged as a collection for the reason the administrative table is - see
     * ProjectController::table(): the tabs are Project::tabKey(), a model
     * method, and the counts above the table are taken from it too.
     */
    public function projectsTable(Request $request)
    {
        $table = ServerTable::fromRequest($request);
        $tab = (string) $request->query('tab', 'all');

        $projects = $this->assignedProjects($this->technician($request), ['archived'])
            ->load('projectTypes')
            ->when($tab !== 'all', fn (Collection $projects): Collection => $projects->filter(
                fn (Project $project): bool => $project->tabKey() === $tab
            ));

        $page = $table->paginateCollection(
            $projects,
            sortable: [
                0 => fn (Project $project): ?string => $project->reference_no,
                1 => fn (Project $project): ?string => $project->client?->primaryName(),
                2 => fn (Project $project): ?string => $project->client?->client_type,
                3 => fn (Project $project): string => $project->projectTypes->pluck('type_name')->implode(', '),
                // By tab, in the order the tabs are read - see tabRank().
                4 => fn (Project $project): int => $project->tabRank(),
            ],
            search: fn (Project $project, string $word): bool => ServerTable::matchesAny(
                $word,
                $project->reference_no,
                $project->name,
                $project->client?->primaryName(),
                $project->client?->client_type,
                $project->projectTypes->pluck('type_name')->implode(' '),
                $project->statusLabel(),
            ),
            defaultOrder: [[4, 'asc'], [0, 'desc']],
        );

        $user = $request->user();

        return $table->respond(
            $page,
            fn (Project $project): string => view('technician.partials.project-row', [
                'project' => $project,
                // Closing a project is a lead's call; a technician only reads.
                'canCloseProjects' => $user->isLeadTechnician(),
                // A deactivated account keeps whatever it was booked on - see
                // Project::inactiveCrew() - so the crew it leaves short has to
                // be told rather than left to find out on the day. The
                // administrative projects table already flags this; the lead
                // running the job is the other person who needs to know, and
                // the flag is theirs alone: a technician cannot act on
                // somebody else's account and has no tasks to move.
                'flagsInactiveCrew' => $user->isLeadTechnician(),
            ])->render(),
        );
    }

    /**
     * One project, in full.
     *
     * The Super Admin project details page as a lead sees it: the same
     * information in the same order, with every control that edits the
     * project record itself left out. What remains is the task board and the
     * report log, which are the lead's to run.
     */
    public function showProject(Request $request, Project $project)
    {
        $this->authorize('viewAssigned', $project);

        $project->load([
            'client',
            'documents',
            'schedules',
            'projectTypes',
            'completionPhotos',
            'projectTechnicians.technician.account',
            // The Assigned Team panel lists each technician's approved
            // specialties beside their name.
            'projectTechnicians.technician.skills',
            // Who is scheduled to join, for the panel's Upcoming list and the
            // Assign To picker - work can be dated for a first week before it
            // arrives.
            'upcomingTechnicians.technician.account',
            'rosterTechnicians.technician.account',
        ]);

        // The lead first, matching how the Super Admin page orders the team.
        $project->setRelation(
            'projectTechnicians',
            $project->projectTechnicians
                ->sortByDesc(fn (ProjectTechnician $assignment): bool => optional($assignment->technician?->account)->role === 'lead_technician')
                ->values()
        );

        // Archived reports are off this list, exactly as they are off the
        // administrator's copy of the page: archiving one from either portal
        // takes it out of both.
        $reports = TechnicianReport::query()
            ->active()
            ->with(['images', 'technician.account', 'submitter', 'project.client'])
            ->where('project_id', $project->project_id)
            ->when(
                $request->filled('report_type'),
                fn ($query) => $query->where('report_type', $request->string('report_type'))
            )
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->get();

        // The lead leads the Assign To picker, matching the administrator's
        // copy of this page and the Create Task dialog.
        $technicians = $project->rosterTechnicians
            ->pluck('technician')
            ->filter()
            ->unique('technician_id')
            ->sortBy(fn ($technician): string => sprintf(
                '%d %s',
                optional($technician->account)->role === 'lead_technician' ? 0 : 1,
                mb_strtolower((string) $technician->name)
            ))
            ->values();

        // This project's load, not the whole system's - see TechnicianTaskLoad.
        $technicianActiveTaskCounts = app(TechnicianTaskLoad::class)
            ->forProject($project->project_id);

        $user = $request->user();
        $canRecordMaterials = app(ProjectMaterialRules::class)->canRecord($user, $project);

        return view('technician.projectDetails', [
            'project' => $project,
            'reports' => $reports,
            'technicians' => $technicians,
            // Every span each of them holds here, so the task dialogs can grey
            // out the days a chosen technician is not assigned for.
            'technicianPeriods' => $this->assignmentRules->periodsFor(
                (int) $project->project_id,
                $technicians->pluck('technician_id')
            ),
            'technicianActiveTaskCounts' => $technicianActiveTaskCounts,
            'technicianId' => $this->technician($request)->technician_id,
            'scheduleRanges' => collect($this->scheduleRules->ranges($project->project_id)),
            'canManageTasks' => $this->projectPolicy->manageTasks($user, $project),
            'canSubmitReport' => $this->projectPolicy->submitReport($user, $project),
            // A lead who files this project's reports, on a day it is not
            // booked for: the button is shown disabled, saying why.
            'reportWaitsForScheduledDay' => $this->projectPolicy->reportsOn($user, $project)
                && ! $this->projectPolicy->submitReport($user, $project),
            // Whether this project is even in a state to be closed out. A
            // Pending, Unscheduled or paused project is not, so the button is
            // not drawn at all rather than opening a dialog that can only
            // refuse - see ProjectPolicy::offersCompletion().
            'canCloseProject' => $this->projectPolicy->offersCompletion($user, $project),
            // Same flag My Projects carries, for the same reason: the lead is
            // told their crew is short, and here they can see which of them it
            // is and move the work off them.
            'flagsInactiveCrew' => $user->isLeadTechnician(),
            // The overdue banner is a lead's notice, not the crew's. It asks
            // the reader to close the project off or to go and have the
            // schedule extended, and a plain technician can do neither - so to
            // them it is a warning about somebody else's decision, on a page
            // whose only remaining purpose is the work they still owe. The
            // status badge still reads Overdue for everyone; what is withheld
            // is the instruction, not the fact.
            'showsOverdueNotice' => ! $user->isTechnician(),
            'completionBlockers' => $this->projectPolicy->blockerDetailsFor($project),
            'reportTypes' => TechnicianReport::TYPES,

            // Project Phases. The same two states the administrative page
            // draws, from the same two services - a lead sees the structure
            // and the progress exactly as the office does. What they do not
            // get is Override Phase Structure, which the component is simply
            // not told about here.
            'phaseSummary' => $project->needsPhaseSetup()
                ? null
                : app(ProjectPhaseProgress::class)->summary($project),
            'canSetUpPhases' => app(ProjectPhaseRules::class)->canSetUp($user, $project),
            'canCompletePhase' => app(ProjectPhaseRules::class)->canCompletePhase($user, $project),
            'selectablePhases' => app(ProjectPhaseProgress::class)->selectablePhases($project),

            // The Materials tab, read by the whole crew and recorded by the
            // lead - see ProjectMaterialRules, which the endpoints ask again.
            'materialSummary' => app(ProjectMaterialLedger::class)->summary($project),
            'canRecordMaterials' => $canRecordMaterials,
            'materialQuickAdd' => $canRecordMaterials
                ? ProjectMaterialController::quickAddOptions()
                : ['types' => [], 'units' => []],
        ]);
    }

    /**
     * One page of the Tasks tab on a project, for serverTable.js - the rows
     * and the dialogs behind them.
     *
     * Narrowed to what the reader is allowed to see: a lead runs the whole
     * board, a technician reads their own work and nothing else. Done in SQL
     * so a colleague's task never reaches the page to be hidden - see
     * Task::scopeVisibleTo.
     */
    public function projectTasksTable(Request $request, Project $project, TaskBoard $board)
    {
        $this->authorize('viewAssigned', $project);

        $user = $request->user();

        [$table, $page] = $board->paginate(
            $request,
            Task::query()->visibleTo($user)->where('project_id', $project->project_id),
            defaultOrder: [[4, 'asc']],
        );

        $project->load('rosterTechnicians.technician.account');

        // The lead leads the Assign To picker, matching the administrator's
        // copy of this page and the Create Task dialog.
        $technicians = $project->rosterTechnicians
            ->pluck('technician')
            ->filter()
            ->unique('technician_id')
            ->sortBy(fn ($technician): string => sprintf(
                '%d %s',
                optional($technician->account)->role === 'lead_technician' ? 0 : 1,
                mb_strtolower((string) $technician->name)
            ))
            ->values();

        $canManageTasks = $this->projectPolicy->manageTasks($user, $project);
        $technicianId = $this->technician($request)->technician_id;

        return $table->respond(
            $page,
            fn (Task $task): string => view('technician.partials.project-task-row', [
                'task' => $task,
                'canManageTasks' => $canManageTasks,
                'technicianId' => $technicianId,
            ])->render(),
            view('technician.partials.project-task-dialogs', [
                'tasks' => $page->items,
                'canManageTasks' => $canManageTasks,
                'technicians' => $technicians,
                'technicianPeriods' => $this->assignmentRules->periodsFor(
                    (int) $project->project_id,
                    $technicians->pluck('technician_id')
                ),
                // This project's load, not the whole system's - see
                // TechnicianTaskLoad.
                'technicianActiveTaskCounts' => app(TechnicianTaskLoad::class)->forProject($project->project_id),
                'scheduleRanges' => collect($this->scheduleRules->ranges((int) $project->project_id)),
                'selectablePhases' => app(ProjectPhaseProgress::class)->selectablePhases($project),
            ])->render(),
        );
    }

    /**
     * Tasks: the whole board for every assigned project, grouped by project
     * rather than flattened into one undifferentiated list.
     */
    public function tasks(Request $request, TaskBoard $board)
    {
        $technician = $this->technician($request);
        $user = $request->user();

        $projects = $this->boardProjects($technician);
        $projectIds = $projects->pluck('project_id');

        // Narrowed to what the reader is allowed to see: a lead runs the whole
        // board, a technician reads their own work and nothing else - see
        // Task::scopeVisibleTo.
        $visible = fn (): Builder => Task::query()->visibleTo($user);

        $manageable = $projects->mapWithKeys(fn (Project $project): array => [
            $project->project_id => $this->projectPolicy->manageTasks($user, $project),
        ]);

        // A task notification links here with ?task=, and that task's dialog
        // has to be on the page before its card has loaded to open it.
        $linkedTask = $visible()
            ->withHolderCoverage()
            ->with(['technician.account', 'images', 'completedBy', 'phase'])
            ->whereIn('project_id', $projectIds)
            ->find((int) $request->query('task'));

        $linkedProject = $linkedTask ? $projects->firstWhere('project_id', $linkedTask->project_id) : null;

        // Work on this board that cannot proceed: nobody holds it, it has no
        // dates, or neither.
        //
        // A lead has no dashboard of their own and is not getting one for
        // this, so the alert lives at the top of the page they already run the
        // board from. The scope handed over is deliberately theirs and not the
        // whole board - visibleTo() plus the projects they are actually on -
        // so a lead is never told about a stuck task on somebody else's job.
        // What counts as stuck is not theirs to decide: that is
        // Task::scopeNeedsAssignment(), the same rule the Super Admin
        // dashboard counts by.
        //
        // Offered to leads only. A plain technician's board is their own work,
        // an unheld task is never on it, and they may not assign anybody - so
        // for them this would be an alert about nothing they can reach.
        $attentionSummary = ['total' => 0, 'counts' => [], 'lines' => []];
        $attentionReadOnly = null;

        if ($user->isLeadTechnician()) {
            $mine = fn (): Builder => $visible()->whereIn('project_id', $projectIds);

            $attentionSummary = app(TaskAssignmentGaps::class)->summarise($mine());

            // Which projects the stuck tasks are actually on, so the note
            // below is about this backlog rather than about the page.
            $affected = $mine()->needsAssignment()->distinct()->pluck('project_id');

            // A lead only runs the board on a live, scheduled project of their
            // own (see ProjectPolicy::manageTasks). Where they cannot, the row
            // opens read-only - the task dialog is handed no technician list
            // and no form action - so the alert says who to ask rather than
            // implying a control that is not there.
            $canResolveAny = $affected->contains(
                fn ($projectId): bool => (bool) ($manageable[$projectId] ?? false)
            );

            if ($attentionSummary['total'] > 0 && ! $canResolveAny) {
                $attentionReadOnly = 'An administrator must fix these.';
            }
        }

        // The cards and their figures. The tasks themselves arrive a page at
        // a time, per card, from taskBoardTable().
        return view('technician.tasks', [
            'projects' => $projects,
            'taskCounts' => $board->taskCounts($visible(), $projectIds),
            'gapCounts' => $board->gapCounts($visible(), $projectIds),
            'linkedTaskDialogs' => $linkedProject
                ? $board->dialogs($linkedProject, collect([$linkedTask]), $this->boardOptions($request, $linkedProject))
                : '',
            'attentionSummary' => $attentionSummary,
            'attentionReadOnly' => $attentionReadOnly,
            // Only projects that can actually take a new task are offered in
            // the Add Task dialog.
            // A project whose phases are not finalized is left off as well:
            // there is nothing to file the work under yet.
            'creatableProjects' => $projects->filter(
                fn (Project $project): bool => $manageable[$project->project_id]
                    && $project->phasesAreFinalized()
            )->values(),
        ]);
    }

    /**
     * One page of one project's board, for serverTable.js - only a project
     * on this reader's board, and only the tasks they may see on it.
     */
    public function taskBoardTable(Request $request, Project $project, TaskBoard $board)
    {
        $project = $this->boardProjects($this->technician($request))
            ->firstWhere('project_id', $project->project_id);

        abort_if($project === null, 404);

        return $board->respond(
            $request,
            $project,
            Task::query()->visibleTo($request->user())->where('project_id', $project->project_id),
            $this->boardOptions($request, $project)
        );
    }

    /**
     * The projects on this technician's task board. A finished project has
     * no board left to run, so it drops off entirely - unlike My Projects,
     * which keeps it for the record.
     *
     * @return Collection<int, Project>
     */
    private function boardProjects(Technician $technician): Collection
    {
        return $this->assignedProjects($technician, ['cancelled', 'archived', 'completed']);
    }

    /**
     * How a project's board is drawn for this reader: whether they run it -
     * see ProjectPolicy::manageTasks() - and the portal's own endpoints.
     *
     * @return array{canManage: bool, viewerTechnicianId: int, updateRoute: string, completeRoute: string, deleteRoute: string, updateMethod: string, completeMethod: string}
     */
    private function boardOptions(Request $request, Project $project): array
    {
        return [
            'canManage' => $this->projectPolicy->manageTasks($request->user(), $project),
            'viewerTechnicianId' => (int) $this->technician($request)->technician_id,
            'updateRoute' => 'technician.tasks.update',
            'completeRoute' => 'technician.tasks.complete',
            'deleteRoute' => 'technician.tasks.destroy',
            'updateMethod' => 'POST',
            'completeMethod' => 'POST',
        ];
    }

    /**
     * Reports: everything this lead has filed themselves, plus the form to
     * file more.
     *
     * "Filed themselves" is the account that pressed Submit, not the
     * technician the report is filed against. The two are usually the same
     * person, but not always: an administrator filing a report from the
     * Reports page credits it to the project's lead when the form does not say
     * otherwise (see TechnicianReportController::defaultTechnicianId), and a
     * report somebody else wrote has no business in this lead's own list.
     *
     * Reports written before submitted_by existed carry no account at all, so
     * they fall back to the technician the report is about - which for those
     * rows is who filed them.
     *
     * This narrowing is deliberately confined to this page. Opening a project
     * still shows every report on it whoever filed it: the question there is
     * "what has happened on this job", not "what have I written".
     */
    public function reports(Request $request)
    {
        $technician = $this->technician($request);

        // Which projects this lead has filed on, for the Project filter - so it
        // can never offer a filter that matches nothing. One query for the ids,
        // one for the projects, however long the log is.
        $filterProjects = Project::query()
            ->whereIn('project_id', $this->ownReports($request)->active()->select('project_id'))
            ->orderBy('reference_no')
            ->get();

        // The rows themselves arrive a page at a time from reportsTable().
        return view('technician.reports', [
            'reportCount' => $this->ownReports($request)->active()->count(),
            'filterProjects' => $filterProjects,
            'reportableProjects' => $this->assignedProjects($technician)
                ->filter(fn (Project $project): bool => $this->projectPolicy->submitReport($request->user(), $project))
                ->values(),
            'reportTypes' => TechnicianReport::TYPES,
        ]);
    }

    /**
     * One page of the lead's report log, for serverTable.js.
     *
     * The four narrowings the filter bar offers - project, report type, date
     * and the search box - are applied here, in SQL, the same way the Super
     * Admin Reports page applies its own. The viewer reads each report from
     * `meta.payloads`, the page of reports on screen in full.
     */
    public function reportsTable(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'project_id' => ['nullable', 'integer'],
            'report_type' => ['nullable', 'string'],
            'date_filter' => ['nullable', Rule::in(['all', 'today', 'week', 'month', 'custom'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $filters = $validator->validated();
        $table = ServerTable::fromRequest($request);

        $query = $this->ownReports($request)
            ->active()
            ->when(! empty($filters['project_id']), fn ($q) => $q->where('project_id', $filters['project_id']))
            ->when(
                ! empty($filters['report_type']) && $filters['report_type'] !== 'all',
                fn ($q) => $q->where('report_type', $filters['report_type'])
            );

        [$from, $to] = $this->reportDateWindow(
            $filters['date_filter'] ?? 'all',
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null
        );

        // whereDate, not whereBetween: report_date is written with a midnight
        // time component, which a bare string range would miss.
        $query->when($from, fn ($q) => $q->whereDate('report_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('report_date', '<=', $to));

        $page = $table->paginate(
            $query,
            sortable: [
                0 => 'id',
                1 => fn ($query, string $dir) => $query->orderByProjectReference($dir),
                2 => fn ($query, string $dir) => $query->orderByClientName($dir),
                3 => 'report_type',
                4 => fn ($query, string $dir) => $query->orderBySubmitter($dir),
                5 => 'report_date',
            ],
            search: fn ($query, string $word) => $query->matchingListingWord($word),
            defaultOrder: [[5, 'desc']],
        );

        $reports = $page->items->load(['project.client', 'images', 'technician.account', 'submitter']);
        $user = $request->user();

        return $table->respond(
            $page,
            fn (TechnicianReport $report): string => view('technician.partials.report-row', [
                'report' => $report,
            ])->render(),
            meta: [
                'payloads' => $reports->mapWithKeys(fn (TechnicianReport $report): array => [
                    $report->id => $this->reportPayload($report, $user),
                ])->all(),
            ],
        );
    }

    /**
     * The archive behind the Reports page: the reports this lead filed away
     * themselves. Its rows arrive a page at a time from
     * archivedReportsTable().
     */
    public function archivedReports()
    {
        return view('technician.archivedReports');
    }

    /**
     * One page of the lead's archive, for serverTable.js.
     *
     * Narrowed exactly as the active log is, and for the same reason - a lead
     * reads what they wrote, not what the office archived on some other
     * project. Restoring is offered per row and re-checked by
     * TechnicianReportPolicy on the way in, so the list and the endpoint agree
     * about what is theirs.
     *
     * Nothing here is a stub: an archived report keeps its pictures, its
     * project and the day it was filed, and the viewer shows all of it.
     */
    public function archivedReportsTable(Request $request)
    {
        $table = ServerTable::fromRequest($request);

        $page = $table->paginate(
            $this->ownReports($request)->archived(),
            sortable: [
                0 => 'id',
                1 => fn ($query, string $dir) => $query->orderByProjectReference($dir),
                2 => fn ($query, string $dir) => $query->orderByClientName($dir),
                3 => 'report_type',
                4 => 'report_date',
                5 => fn ($query, string $dir) => $query->orderByArchivedAt($dir),
            ],
            search: fn ($query, string $word) => $query->matchingListingWord($word),
            defaultOrder: [[5, 'desc']],
        );

        $reports = $page->items->load(['project.client', 'images', 'technician.account', 'submitter', 'archiver']);
        $user = $request->user();

        return $table->respond(
            $page,
            fn (TechnicianReport $report): string => view('technician.partials.archived-report-row', [
                'report' => $report,
            ])->render(),
            view('technician.partials.archived-report-dialogs', [
                'reports' => $reports,
            ])->render(),
            [
                'payloads' => $reports->mapWithKeys(fn (TechnicianReport $report): array => [
                    $report->id => $this->reportPayload($report, $user),
                ])->all(),
            ],
        );
    }

    /**
     * The reports this account filed, active and archived alike - see
     * scopeToOwnReports().
     *
     * @return Builder<TechnicianReport>
     */
    private function ownReports(Request $request): Builder
    {
        $technicianId = $this->technician($request)->technician_id;
        $accountId = $request->user()->id;

        return TechnicianReport::query()
            ->where(fn ($mine) => $this->scopeToOwnReports($mine, $accountId, $technicianId));
    }

    /**
     * The [from, to] dates the Date filter stands for, either of which may be
     * open. Weeks run Monday to Sunday and months are calendar months, in the
     * office's own today - the same reading the Super Admin Reports page
     * gives the same options.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function reportDateWindow(string $filter, ?string $start, ?string $end): array
    {
        $today = BusinessTime::today();

        return match ($filter) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'week' => [$today->startOfWeek()->toDateString(), $today->endOfWeek()->toDateString()],
            'month' => [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()],
            'custom' => [
                $start ? CarbonImmutable::parse($start)->toDateString() : null,
                $end ? CarbonImmutable::parse($end)->toDateString() : null,
            ],
            default => [null, null],
        };
    }

    /**
     * "Reports this account filed", as a query condition.
     *
     * The account that pressed Submit, or - for reports written before
     * submitted_by existed, which carry no account at all - the technician the
     * report was filed under, who for those rows is who wrote it. Shared by the
     * active log and its archive so the two can never disagree about whose
     * report it is.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|Builder<TechnicianReport>  $query
     */
    private function scopeToOwnReports($query, int $accountId, int $technicianId): void
    {
        $query->where('submitted_by', $accountId)
            ->orWhere(fn ($legacy) => $legacy
                ->whereNull('submitted_by')
                ->where('technician_id', $technicianId));
    }

    // ------------------------------------------------------------------
    // JSON: reading a project
    // ------------------------------------------------------------------

    /**
     * Everything the schedule panel and the View Project modal render.
     *
     * One payload serves both so the two screens can never disagree about a
     * project. `mine_only` trims the task list to this lead's own work, which
     * is what the schedule panel shows.
     */
    public function projectDetails(Request $request, Project $project): JsonResponse
    {
        $this->authorize('viewAssigned', $project);

        $technician = $this->technician($request);
        $mineOnly = $request->boolean('mine_only');

        $project->load([
            'client',
            'schedules',
            'projectTechnicians.technician.account',
            'tasks' => fn ($query) => $query
                ->visibleTo($request->user())
                ->when($mineOnly, fn ($q) => $q->where('technician_id', $technician->technician_id))
                ->orderByRaw("case when status = 'ongoing' then 0 when status = 'pending' then 1 when status = 'unassigned' then 2 else 3 end")
                ->orderByRaw('due_date is null')
                ->orderBy('due_date'),
            'tasks.technician.account',
            'tasks.images',
            'tasks.completedBy',
            // The task's own phase, and the project's whole set - the panel
            // prints both the stage each task belongs to and the stage the
            // project is on, and neither should cost a query per row.
            'tasks.phase',
            'phases',
        ]);

        $reports = TechnicianReport::query()
            ->active()
            ->with(['images', 'technician.account', 'submitter', 'project.client'])
            ->where('project_id', $project->project_id)
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->get();

        $user = $request->user();

        return response()->json([
            'project' => $this->projectPayload($project),
            // The reader's own days and changes, apart from the project's
            // schedule - see Project::bookedDaysFor().
            'my_schedule' => [
                'days' => $project->bookedDaysFor((int) $technician->technician_id),
                'changes' => collect($project->scheduledChangesFor((int) $technician->technician_id))
                    ->map(fn (array $change): array => [
                        'title' => $change['title'],
                        'when' => $change['when'],
                        'icon' => $change['icon'],
                    ])
                    ->all(),
            ],
            'tasks' => $project->tasks->map(fn (Task $task): array => $this->taskPayload($task, $technician))->all(),
            'reports' => $reports->map(fn (TechnicianReport $report): array => $this->reportPayload($report, $user))->all(),
            'permissions' => [
                'manage_tasks' => $this->projectPolicy->manageTasks($user, $project),
                'submit_report' => $this->projectPolicy->submitReport($user, $project),
                'complete_project' => $this->projectPolicy->complete($user, $project),
            ],
            // With the link to whatever fixes each one: the completion dialog
            // on My Projects renders these, and a refusal a lead cannot act on
            // is a dead end.
            'completion_blockers' => $this->projectPolicy->blockerDetailsFor($project),
            'task_form' => $this->taskFormData_($project),
        ]);
    }

    // ------------------------------------------------------------------
    // JSON: writing
    // ------------------------------------------------------------------

    /**
     * Create a task for somebody already on the project.
     *
     * Same shape as the Super Admin form, same validation: a technician who
     * is not on the project is rejected, and the dates have to sit inside one
     * of the project's schedule ranges.
     */
    public function storeTask(Request $request, Project $project)
    {
        $this->authorize('manageTasks', $project);

        $phaseRules = app(TaskPhaseRules::class);

        // A project whose phases have not been finalized takes no tasks. The
        // lead is told to go and set them up rather than being allowed to
        // create work that belongs to no stage - see TaskPhaseRules.
        if ($blocked = $phaseRules->blockReason($project)) {
            return $this->failed($request, $blocked);
        }

        $ranges = $this->scheduleRules->ranges($project->project_id);

        if ($ranges === []) {
            return $this->failed($request, 'This project has no schedule yet, so tasks cannot be dated.');
        }

        $validator = Validator::make($request->all(), [
            'task_title' => ['required', 'string', 'max:255'],
            'task_description' => ['required', 'string'],
            'phase_id' => $phaseRules->rules($project),
            'technician_id' => ['required', 'integer', 'exists:tbl_technicians,technician_id'],
            'start_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], $phaseRules->messages() + [
            'technician_id.*' => 'Pick a technician who is assigned to this project.',
        ]);

        $this->scheduleRules->attach($validator, $ranges);
        // New work, so there is no current owner to make an exception for.
        $this->assignmentRules->attach($validator);
        // Assigned to this project for every day of the task.
        $this->assignmentRules->attachPeriodRule($validator, $project);

        if ($validator->fails()) {
            return $this->failed($request, $validator->errors()->first());
        }

        $validated = $validator->validated();

        // The Super Admin board treats an identical open task as a duplicate,
        // and so does this one - the same job twice is a scheduling mistake.
        $duplicate = Task::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $validated['technician_id'])
            ->whereIn('status', Task::OPEN_STATUSES)
            ->whereRaw('lower(task_title) = ?', [mb_strtolower($validated['task_title'])])
            ->exists();

        if ($duplicate) {
            return $this->failed(
                $request,
                'That technician already has an open task with this title on this project.'
            );
        }

        $task = Task::create($validated + [
            'project_id' => $project->project_id,
            'status' => 'pending',
        ]);

        $this->activityLogger->record(
            ActivityLog::TASK_CREATED,
            null,
            sprintf("Created task '%s' on %s.", $task->task_title, $project->reference_no),
            $task
        );

        $this->notifications->taskAssigned($task);

        return $this->succeeded($request, 'Task created.', fn (): array => [
            'task' => $this->taskPayload(
                $task->load(['technician.account', 'images', 'completedBy', 'phase']),
                $this->technician($request)
            ),
        ], 201);
    }

    /**
     * Edit an existing task on a project this lead runs.
     */
    public function updateTask(Request $request, Task $task)
    {
        $this->authorize('update', $task);

        $project = $task->project;
        $ranges = $this->scheduleRules->ranges($project->project_id);

        if ($ranges === []) {
            return $this->failed($request, 'This project has no schedule yet.');
        }

        $validator = Validator::make($request->all(), [
            'task_title' => ['required', 'string', 'max:255'],
            'task_description' => ['required', 'string'],
            // Moving work between stages is board work, not a structural
            // change, so it stays with whoever may edit the task. A completed
            // phase takes no work, except from a task already on one - which
            // may stay where it is.
            'phase_id' => app(TaskPhaseRules::class)->rules($project, $task->phase_id),
            'technician_id' => ['required', 'integer', 'exists:tbl_technicians,technician_id'],
            'start_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], app(TaskPhaseRules::class)->messages());

        $this->scheduleRules->attach($validator, $ranges);
        // Whoever holds the task may keep it: editing its wording or its dates
        // re-submits the owner, and refusing that would make an inactive
        // technician's work uneditable - including the handover off them.
        $this->assignmentRules->attach($validator, (int) $task->technician_id);
        // Assigned for every day of it, unless neither the holder nor the dates
        // are changing.
        $this->assignmentRules->attachPeriodRule($validator, $project, $task);

        if ($validator->fails()) {
            return $this->failed($request, $validator->errors()->first());
        }

        $validated = $validator->validated();

        // Matches TaskController: giving an orphaned task an owner puts it
        // back in the queue rather than leaving it unassigned.
        if ($task->status === 'unassigned') {
            $validated['status'] = 'pending';
        }

        $task->update($validated);

        $this->activityLogger->record(
            ActivityLog::TASK_UPDATED,
            null,
            sprintf("Updated task '%s'.", $task->task_title),
            $task
        );

        return $this->succeeded($request, 'Task updated.', fn (): array => [
            'task' => $this->taskPayload(
                $task->fresh(['technician.account', 'images', 'completedBy', 'phase']),
                $this->technician($request)
            ),
        ]);
    }

    /**
     * Close a task.
     *
     * Your own work is closed with an account of it. A lead closing somebody
     * else's has no first-hand account to give, so the notes and photos are
     * optional there and the completion panel records who closed it instead.
     */
    public function completeTask(Request $request, Task $task)
    {
        // Reach first, then the calendar, then the completion rule. Asked in
        // that order so a task that is nothing to do with this technician is
        // still a flat 403 rather than a sentence about its start date, and so
        // the one refusal a technician can do something about - come back on
        // the day - arrives as a message instead of a bare 403.
        $this->authorize('view', $task);

        // Read off the task as it stands in the database, not off whatever the
        // page was drawn from: the button may have been enabled when it was
        // rendered, or never have been drawn at all.
        if ($task->waitsForStartDate($request->user())) {
            return $this->failed($request, Task::NOT_STARTED_REFUSAL);
        }

        $this->authorize('complete', $task);

        $user = $request->user();
        $mustDescribe = app(TaskPolicy::class)->mustDescribeCompletion($user, $task);

        $needsImage = $task->requiresCompletionImage($user);

        $validator = Validator::make($request->all(), [
            'completion_notes' => [$mustDescribe ? 'required' : 'nullable', 'string'],
            'images' => [$needsImage ? 'required' : 'nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'completion_notes.required' => 'Describe what was done before completing the task.',
            'images.required' => Task::IMAGE_REQUIRED_REFUSAL,
        ]);

        if ($validator->fails()) {
            return $this->failed($request, $validator->errors()->first());
        }

        try {
            DB::transaction(function () use ($request, $task, $validator, $user): void {
                $task->update([
                    'status' => 'completed',
                    'completion_notes' => $validator->validated()['completion_notes'] ?? null,
                    'completed_at' => now(),
                    'completed_by' => $user->id,
                ]);

                foreach ($request->file('images') ?? [] as $image) {
                    TaskImage::create([
                        'task_id' => $task->task_id,
                        'image_path' => UploadStore::put($image, 'task_images'),
                    ]);
                }
            });
        } catch (Throwable $e) {
            return $this->failed($request, $this->safeErrorMessage($e, 'Unable to save. Nothing was changed.'));
        }

        $this->activityLogger->record(
            ActivityLog::TASK_COMPLETED,
            null,
            sprintf("Marked task '%s' as completed.", $task->task_title),
            $task
        );

        $this->notifications->taskCompleted($task, count($request->file('images') ?? []) > 0);

        return $this->succeeded($request, 'Task completed.', fn (): array => [
            'task' => $this->taskPayload(
                $task->fresh(['technician.account', 'images', 'completedBy', 'phase']),
                $this->technician($request)
            ),
        ]);
    }

    /**
     * Remove a task from a project this lead runs.
     *
     * A task raised in error is still an error after somebody ticks it off, so
     * completed ones can go too - along with whatever completion photos they
     * carried, which the confirmation dialog says outright.
     */
    public function destroyTask(Request $request, Task $task)
    {
        $this->authorize('delete', $task);

        try {
            DB::transaction(function () use ($task): void {
                foreach ($task->images as $image) {
                    UploadStore::remove($image->image_path);
                    $image->delete();
                }

                $task->delete();
            });
        } catch (Throwable $e) {
            return $this->failed($request, $this->safeErrorMessage($e, 'Unable to save. Nothing was changed.'));
        }

        $this->activityLogger->record(
            ActivityLog::TASK_ARCHIVED,
            null,
            sprintf("Deleted task '%s'.", $task->task_title),
            $task
        );

        $this->notifications->taskCancelled($task);

        return $this->succeeded($request, 'Task deleted.');
    }

    /**
     * What the Add Task form needs once a project is picked.
     *
     * Deliberately the same shape TaskController@projectFormData answers with,
     * so one script drives the dialog in both portals.
     */
    public function taskFormData(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manageTasks', $project);

        $project->load('projectTechnicians.technician.account');

        if ($blocked = app(TaskPhaseRules::class)->blockReason($project)) {
            return response()->json(['error' => $blocked], 422);
        }

        $formData = $this->taskFormData_($project);

        if ($formData['ranges'] === []) {
            return response()->json([
                'error' => 'This project has no schedule yet, so tasks cannot be dated.',
            ], 422);
        }

        return response()->json($formData);
    }

    /**
     * File a technician report against a project this lead is on.
     *
     * Deliberately not routed through TechnicianReportController: that action
     * trusts whichever technician_id the form sends, which is right for an
     * administrator filing on someone's behalf and wrong here. A lead's
     * report is always filed under the lead.
     */
    public function storeReport(Request $request, Project $project)
    {
        // Said in words rather than as a bare 403: the lead does run this
        // project, it is just not one of its scheduled days.
        if ($this->projectPolicy->reportsOn($request->user(), $project)
            && ! $project->isScheduledOn(BusinessTime::today()->toDateString())) {
            return $this->failed($request, self::REPORT_NOT_SCHEDULED_TODAY);
        }

        $this->authorize('submitReport', $project);

        $validator = Validator::make($request->all(), [
            'report_type' => ['required', 'in:'.implode(',', array_keys(TechnicianReport::TYPES))],
            'report_title' => ['required', 'string', 'max:255'],
            'report_description' => ['required', 'string'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return $this->failed($request, $validator->errors()->first());
        }

        $validated = $validator->validated();
        $technician = $this->technician($request);
        $report = null;

        try {
            DB::transaction(function () use ($request, $project, $technician, $validated, &$report): void {
                $report = TechnicianReport::create([
                    'project_id' => $project->project_id,
                    'technician_id' => $technician->technician_id,
                    'submitted_by' => $request->user()->id,
                    'report_type' => $validated['report_type'],
                    'report_title' => $validated['report_title'],
                    'report_description' => $validated['report_description'],
                    'report_date' => BusinessTime::today()->toDateString(),
                ]);

                foreach ($request->file('images') ?? [] as $image) {
                    TechnicianReportImage::create([
                        'technician_report_id' => $report->id,
                        'image_path' => UploadStore::put($image, 'report_images'),
                    ]);
                }
            });
        } catch (Throwable $e) {
            return $this->failed($request, $this->safeErrorMessage($e, 'Unable to save. Nothing was changed.'));
        }

        $this->activityLogger->record(
            ActivityLog::REPORT_GENERATED,
            null,
            sprintf('Filed a %s on %s.', strtolower($report->typeLabel()), $project->reference_no),
            $report
        );

        $this->notifications->technicianReportFiled($project, strtolower($report->typeLabel()));

        return $this->succeeded($request, 'Report submitted.', fn (): array => [
            'report' => $this->reportPayload($report->load(['project', 'images']), $request->user()),
        ], 201);
    }

    /**
     * Hand a finished project over to its client for confirmation.
     *
     * The policy decides whether it can; blockersFor() says why not, and the
     * confirmation dialog shows that list rather than a bare refusal.
     *
     * A lead no longer closes a project outright. Pressing this records the
     * completion report, releases the dates booked past the completion date,
     * and asks the client to confirm - after which the project is out of the
     * lead's hands either way.
     */
    public function completeProject(Request $request, Project $project)
    {
        $this->authorize('viewAssigned', $project);

        $blockers = $this->projectPolicy->blockerDetailsFor($project);

        if ($blockers !== [] || ! $this->projectPolicy->complete($request->user(), $project)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'This project cannot be completed yet.',
                    'blockers' => $blockers,
                ], 422);
            }

            // The full-page route back is the project's own page, which draws
            // the same blockers with the same links, so the flash message only
            // has to say what happened.
            return back()->with(
                'error',
                'This project cannot be completed yet. '
                    .implode(' ', array_column($blockers, 'message'))
            );
        }

        // The same completion report the Super Admin portal collects, from the
        // same rule set, with one difference: a lead is on site, so the
        // photographs are the evidence and are required rather than optional.
        $completion = app(ProjectCompletion::class);

        $validator = Validator::make(
            $request->all(),
            $completion->rules(photosRequired: true),
            $completion->messages()
        );

        if ($validator->fails()) {
            return $this->failed($request, $validator->errors()->first());
        }

        $validated = $validator->validated();

        try {
            DB::transaction(function () use ($request, $project, $validated, $completion): void {
                $completion->requestCompletion(
                    $project,
                    $validated,
                    $request->file('completion_photos'),
                    $request->user()
                );
            });
        } catch (Throwable $e) {
            return $this->failed($request, $this->safeErrorMessage($e, 'Unable to save. Nothing was changed.'));
        }

        // Recorded from this portal exactly as it is from the other one. The
        // lead closing out their own project is the commonest route into
        // completion, and it used to leave no audit entry at all.
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

        $this->notifications->projectAwaitingClientConfirmation($project);

        // Completed from the portal is still completed, so the client hears
        // about it in exactly the same words as when an administrator does it.
        app(ProjectEmails::class)->projectAwaitingConfirmation($project->refresh());

        $message = sprintf(
            'Sent. Auto-completes in %d days.',
            Project::completionConfirmationDays()
        );

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        // The project is view only now, so there is nothing left to stay on.
        return redirect()
            ->route('technician.projects')
            ->with('success', $message);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function technician(Request $request): Technician
    {
        return $request->user()->technicianRecord();
    }

    /**
     * Why a task's Complete button is not offered, when the answer is "not
     * yet" rather than "not yours". Null in every other case, including a task
     * this account could never have closed anyway.
     */
    private function startDateRefusal(Task $task): ?string
    {
        $user = request()->user();

        return $user !== null && app(TaskPolicy::class)->blockedByStartDate($user, $task)
            ? Task::NOT_STARTED_REFUSAL
            : null;
    }

    /**
     * Answer whichever way the caller asked.
     *
     * The task dialogs are plain Blade forms shared with the Super Admin
     * portal, so they post and expect to land back on the page with a flash
     * message. The schedule panel calls the same endpoints over fetch and
     * expects JSON, because reloading would throw away the project it has
     * open.
     *
     * @param  (\Closure(): array<string, mixed>)|null  $payload
     */
    private function succeeded(Request $request, string $message, ?\Closure $payload = null, int $status = 200)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message] + ($payload ? $payload() : []), $status);
        }

        return back()->with('success', $message);
    }

    private function failed(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => $message], 422);
        }

        return back()->with('error', $message);
    }

    /**
     * Every project this technician is assigned to, with the relations the
     * portal reads, loaded once.
     *
     * `$hide` says which statuses to leave out, because the pages disagree:
     * My Projects shows everything bar archived, the task board drops finished
     * work too, and the calendar drops cancelled work.
     *
     * @param  array<int, string>  $hide
     * @return Collection<int, Project>
     */
    private function assignedProjects(Technician $technician, array $hide = self::HIDDEN_STATUSES): Collection
    {
        return Project::query()
            ->with(['client', 'schedules', 'projectTechnicians.technician.account', 'rosterTechnicians.technician.account'])
            // How far through its phases each project is, for the "3/4" chip
            // on the My Projects rows - see Project::phaseProgress(), which
            // reads these rather than asking per row.
            ->withCount([
                'phases',
                'phases as completed_phases_count' => fn ($query) => $query->whereNotNull('completed_at'),
            ])
            ->where('is_archived', false)
            ->whereNotIn('status', $hide)
            // Including a project they are scheduled to join: they may open it
            // before their first day - see ProjectPolicy::viewAssigned().
            ->whereHas(
                'rosterTechnicians',
                fn ($query) => $query->where('technician_id', $technician->technician_id)
            )
            ->orderByDesc('project_id')
            ->get();
    }

    /**
     * @param  \Closure(CarbonImmutable, CarbonImmutable): bool  $matches
     * @param  Collection<int, Project>  $projects
     */
    private function countProjects(Collection $projects, \Closure $matches): int
    {
        return $projects
            ->filter(fn (Project $project): bool => $project->schedules->contains(
                fn (Schedule $schedule): bool => $matches(
                    CarbonImmutable::parse($schedule->start_datetime)->startOfDay(),
                    CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->startOfDay()
                )
            ))
            ->count();
    }

    /**
     * Every schedule this technician holds a booking on.
     *
     * The booking is the record: tbl_schedule_technicians says which ranges a
     * person was actually put on, which is not the same as every range their
     * project happens to hold. Somebody who joined a job in week three was
     * never on weeks one and two - ProjectTeam refuses to write those links
     * for exactly that reason - and somebody taken off in week five keeps the
     * weeks they worked, because removal only releases the dates still ahead.
     *
     * Read through the membership rather than the current team, so a closed
     * membership still carries its dates onto this calendar. That is the one
     * thing this page needs that My Projects must not do.
     *
     * @return Collection<int, Schedule>
     */
    private function bookedSchedules(Technician $technician): Collection
    {
        return Schedule::query()
            ->whereHas('project', function ($query): void {
                $query->where('is_archived', false)
                    ->whereNotIn('status', self::HIDDEN_STATUSES);
            })
            ->whereHas('scheduleTechnicians.projectTechnician', function ($query) use ($technician): void {
                $query->where('technician_id', $technician->technician_id);
            })
            // project.schedules is needed because isOverdue() inspects every
            // range; without it each event would fire its own query.
            ->with([
                'project.client',
                'project.schedules',
                'scheduleTechnicians.projectTechnician',
            ])
            ->orderBy('start_datetime')
            ->get()
            // HIDDEN_STATUSES above already keeps cancelled work off this
            // calendar - the administrative ones draw it, a technician's own
            // does not - so in practice this only drops archived work today.
            // The cutoff is asked anyway, here and in calendarEvent(), so all
            // three calendars run the same rule and letting cancelled work
            // through would be a one-line change rather than a rewrite.
            ->filter(fn (Schedule $schedule): bool => ($schedule->project?->showsOnCalendar() ?? false)
                && $schedule->startsOnOrBefore($schedule->project->calendarCutoff()))
            ->values();
    }

    /**
     * This technician's membership of the project the schedule belongs to,
     * taken from the booking already loaded against it.
     *
     * Read off the link rather than queried again: the schedule is only here
     * because one of their memberships is booked on it, so the row is in hand.
     *
     * A range can carry two of them - taken off part-way through it and put
     * back on before it ended - and each is its own stretch of the range.
     *
     * @return Collection<int, ProjectTechnician>
     */
    private function membershipsFor(Schedule $schedule, Technician $technician): Collection
    {
        return $schedule->scheduleTechnicians
            ->map(fn (ScheduleTechnician $link): ?ProjectTechnician => $link->projectTechnician)
            ->filter(fn (?ProjectTechnician $assignment): bool => $assignment !== null
                && (int) $assignment->technician_id === (int) $technician->technician_id)
            ->unique(fn (ProjectTechnician $assignment): int => (int) $assignment->project_technician_id)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function calendarEvent(
        Project $project,
        Schedule $schedule,
        ?ProjectTechnician $membership = null,
        bool $stillOnTeam = false
    ): ?array {
        $isFormer = ! $stillOnTeam && ($membership?->hasEnded() ?? false);

        // Only the days this span holds - see Schedule::toCalendarTimesForSpan().
        $times = $membership
            ? $schedule->toCalendarTimesForSpan($project->calendarCutoff(), $membership)
            : $schedule->toCalendarTimesThrough($project->calendarCutoff());

        if ($times === null) {
            return null;
        }

        return [
            'id' => $schedule->schedule_id,
            'title' => $project->reference_no,
            // A partial day comes back as a timed event, so the bar carries
            // its hours instead of reading as a whole day.
            ...$times,
            ...$project->calendarEventColors($schedule->isDateBased()),
            // Drawn flat and grey, so days that are a record of where you were
            // do not read as work you are still expected at.
            ...($isFormer ? ['classNames' => ['fc-event-former']] : []),
            'extendedProps' => [
                'projectId' => $project->project_id,
                'referenceNo' => $project->reference_no,
                'projectName' => $project->name,
                'client' => $this->clientName($project),
                'statusLabel' => $project->statusLabel(),
                'rangeLabel' => $schedule->describe(),
                // Everything the panel needs to explain a former booking is
                // here, and deliberately nothing more. A technician who has
                // been taken off a project should not get its client contact
                // details, its task board or its current state back by
                // clicking an old date - so the browser renders the note from
                // what it already holds and never asks the server for the
                // project at all. See the schedule page's eventClick.
                'isFormer' => $isFormer,
                'removedOn' => $isFormer
                    ? CarbonImmutable::parse($membership->endDate())->format(BusinessTime::DATE)
                    : null,
            ],
        ];
    }

    /**
     * What the Add Task form needs once a project is known: who can be
     * assigned, and which days are selectable.
     *
     * @return array<string, mixed>
     */
    private function taskFormData_(Project $project): array
    {
        $activeTaskCounts = app(TechnicianTaskLoad::class)->forProject($project->project_id);

        $ranges = $this->scheduleRules->ranges($project->project_id);

        $project->loadMissing('rosterTechnicians.technician.account');

        $periods = $this->assignmentRules->periodsFor(
            (int) $project->project_id,
            $project->rosterTechnicians->pluck('technician_id')
        );

        return [
            'ranges' => $ranges,
            'ranges_label' => $this->scheduleRules->describe($ranges),
            // The Phase select, offering this project's finalized structure.
            // Empty while setup is pending, which taskFormData() turns away
            // before the dialog ever gets this far.
            'phases' => app(ProjectPhaseProgress::class)->selectablePhases($project)
                ->map(fn ($phase): array => [
                    'phase_id' => $phase->phase_id,
                    'label' => $phase->label(),
                ])
                ->values()
                ->all(),
            'technicians' => $project->rosterTechnicians
                ->unique('technician_id')
                ->map(fn (ProjectTechnician $assignment): ?array => $assignment->technician ? [
                    'technician_id' => $assignment->technician->technician_id,
                    'name' => $assignment->technician->name,
                    'periods' => $periods[(int) $assignment->technician_id] ?? [],
                    'role' => optional($assignment->technician->account)->role,
                    'is_lead' => optional($assignment->technician->account)->role === 'lead_technician',
                    // Somebody whose account has been switched off stays on the
                    // list, because they are still on the team - but the card is
                    // rendered unselectable. See TaskAssignmentRules, which
                    // refuses the same choice on the way back in.
                    'can_receive_work' => $assignment->technician->isAssignable(),
                    // Their own picture, or the default avatar - the same
                    // source the Blade-rendered assign cards draw from, so the
                    // two never show the same person differently.
                    'avatar_url' => $assignment->technician->account?->avatarUrl()
                        ?? asset('img/default-avatar.svg'),
                    'active_task_count' => (int) ($activeTaskCounts[$assignment->technician->technician_id] ?? 0),
                ] : null)
                ->filter()
                // The lead comes first, then everyone else alphabetically.
                ->sortBy(fn (array $technician): string => sprintf(
                    '%d %s',
                    $technician['is_lead'] ? 0 : 1,
                    mb_strtolower((string) $technician['name'])
                ))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayload(Project $project): array
    {
        $schedules = $project->schedules;
        $start = $schedules->min('start_datetime');
        $end = $schedules->max('end_datetime');
        $client = $project->client;

        return [
            'project_id' => $project->project_id,
            'reference_no' => $project->reference_no,
            'name' => $project->name,
            'client' => $this->clientName($project),
            'client_type' => $client?->client_type,
            'client_contact' => $client?->contact_number,
            'client_email' => $client?->email_address,
            'address' => $project->address,
            'description' => $project->description,
            'status' => $project->status,
            'status_label' => $project->statusLabel(),
            'status_badge_class' => $project->statusBadgeClass(),
            // Which stage of the job this is, worded exactly as the client's
            // phase line and the administrative panel word it - see
            // Project::phasePosition(). Null while nobody has settled the
            // structure, which the panel prints as "Not set up yet" rather
            // than inventing a position.
            'phase_position' => $project->phasePosition(),
            'start_date' => $start ? CarbonImmutable::parse($start)->format(BusinessTime::DATE) : null,
            'end_date' => $end ? CarbonImmutable::parse($end)->format(BusinessTime::DATE) : null,
            'ranges' => $schedules->map(fn (Schedule $schedule): array => [
                // The shared formatter, so a technician sees the hours they
                // are expected on site rather than a date twice over.
                'label' => $schedule->describe(),
                'is_partial_day' => $schedule->isPartialDay(),
                // Whether this booking is already behind them, so the panel
                // can grey it out and leave the colour for the days they are
                // still expected on site. The line is Schedule::lockState()'s,
                // not a second opinion about when a range has ended.
                'is_past' => $schedule->isLocked(),
            ])->values()->all(),
            'technicians' => $project->projectTechnicians
                ->map(fn (ProjectTechnician $assignment): ?array => $assignment->technician ? [
                    'technician_id' => $assignment->technician->technician_id,
                    'name' => $assignment->technician->name,
                    'is_lead' => $project->isLeadMember($assignment),
                ] : null)
                ->filter()
                ->sortByDesc('is_lead')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayload(Task $task, Technician $viewer): array
    {
        return [
            'task_id' => $task->task_id,
            'project_id' => $task->project_id,
            'title' => $task->task_title,
            'description' => $task->task_description,
            'technician_id' => $task->technician_id,
            'technician' => $task->technician?->name ?? 'Unassigned',
            // status, status_key, status_label and status_badge_class, all
            // from the one derivation - see TaskStatus.
            ...$task->statusPayload(),
            'start_date' => $task->start_date ? CarbonImmutable::parse($task->start_date)->toDateString() : null,
            'due_date' => $task->due_date ? CarbonImmutable::parse($task->due_date)->toDateString() : null,
            'start_date_label' => $task->start_date ? CarbonImmutable::parse($task->start_date)->format(BusinessTime::DATE) : '—',
            'due_date_label' => $task->due_date ? CarbonImmutable::parse($task->due_date)->format(BusinessTime::DATE) : '—',
            'is_mine' => (int) $task->technician_id === (int) $viewer->technician_id,
            // A lead may close anything on a project they run, so this asks
            // the policy rather than re-deriving the rule.
            'can_complete' => request()->user()?->can('complete', $task) ?? false,
            // Set only where the button would otherwise have been drawn and
            // the start date is what stopped it, so the panel can say why the
            // control is missing instead of leaving a gap - see
            // TaskPolicy::blockedByStartDate().
            'completion_blocked_reason' => $this->startDateRefusal($task),
            'completion_notes' => $task->completion_notes,
            // Through BusinessTime, not formatted off the stored instant: the
            // column is UTC, so a task closed at 7 AM in Manila is stored on
            // the previous UTC day and printed as the wrong date. The task
            // modal has always read it this way; this payload had not.
            'completed_at_label' => $task->completed_at
                ? BusinessTime::format($task->completed_at)
                : null,
            // Where this task sits in the project, so a technician reading
            // one off their schedule knows which stage they are being asked
            // to work on. Same two fields x-task-phase-cell prints on the
            // boards.
            'phase' => $task->phase ? [
                'sequence' => $task->phase->sequence,
                'title' => $task->phase->title,
                'label' => $task->phase->label(),
            ] : null,
            'closed_on_behalf' => $task->wasClosedOnBehalf(),
            'completed_by' => $task->completedBy?->fullName(),
            'images' => $task->relationLoaded('images')
                ? $task->images->map(fn (TaskImage $image): array => [
                    'url' => $image->url(),
                ])->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportPayload(TechnicianReport $report, ?User $user = null): array
    {
        return [
            'id' => $report->id,
            'display_code' => $report->displayCode(),
            'project_id' => $report->project_id,
            'project_name' => $report->project?->name ?? 'Project removed',
            'reference_no' => $report->project?->reference_no ?? '—',
            'title' => $report->report_title,
            'description' => $report->report_description,
            'client' => $this->reportClientName($report),
            'type' => $report->report_type,
            'type_label' => $report->typeLabel(),
            'type_badge_class' => $report->typeBadgeClass(),
            'type_accent_class' => $report->typeAccentClass(),
            'submitted_by' => $report->submitterName(),
            'submitted_by_avatar' => $report->submitterAvatarUrl(),
            'date' => $report->report_date?->toDateString(),
            'date_label' => $report->report_date?->format(BusinessTime::DATE) ?? '—',
            // The sort key the table's Date column reads, matching the
            // data-order the Blade rows carry.
            'date_order' => $report->report_date?->timestamp ?? 0,
            'images' => $report->images->map(fn (TechnicianReportImage $image): array => [
                'url' => $image->url(),
            ])->all(),
            'is_archived' => $report->isArchived(),
            'archived_at_label' => BusinessTime::at($report->archived_at)?->format(BusinessTime::DATE) ?? '—',
            'archived_by' => $report->archiver?->fullName() ?? '—',
            // Whether this account may act on the report. The endpoint asks the
            // same policy again, so these only decide whether a button is
            // drawn - a lead sees Archive on their own reports and on no
            // others, whatever the page does with the payload.
            'can_archive' => (bool) $user?->can('archive', $report),
            'can_restore' => (bool) $user?->can('restore', $report),
        ];
    }

    private function clientName(Project $project): ?string
    {
        $client = $project->client;

        return $client?->company_name ?: $client?->fullname;
    }

    /**
     * The client a report's project belongs to, for the reports table.
     */
    private function reportClientName(TechnicianReport $report): string
    {
        $project = $report->project;

        if (! $project) {
            return '—';
        }

        $project->loadMissing('client');

        return $this->clientName($project) ?: '—';
    }
}
