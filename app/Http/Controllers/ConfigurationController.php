<?php

namespace App\Http\Controllers;

use App\Exceptions\RoleChangeBlocked;
use App\Models\ActivityLog;
use App\Models\Inquiry;
use App\Models\Project;
use App\Models\Skill;
use App\Models\SystemContent;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CredentialDelivery;
use App\Services\UserAccountService;
use App\Support\AccountAge;
use App\Support\BusinessTime;
use App\Support\CompanyBranding;
use App\Support\PersonName;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Configuration - the centralised administration area.
 *
 * Tab 1, User Management, is implemented here: every employee and client
 * account, their creation, editing, status and archiving. Tabs 2 and 3 are
 * placeholders rendered by the same view.
 *
 * The two tables are paginated and filtered in SQL rather than in the browser,
 * so the page costs the same with ten accounts or ten thousand. Every write
 * goes through UserAccountService, which owns the transactions and the audit
 * trail.
 */
class ConfigurationController extends Controller
{
    /** Rows per page in both tables. */
    private const PER_PAGE = 10;

    /**
     * The columns the Activity Logs table may be sorted by, mapped from the
     * name the interface uses. An allow-list, so no request can order by an
     * arbitrary column.
     *
     * @var array<string, string>
     */
    private const LOG_SORTS = [
        'date' => 'created_at',
        'name' => 'actor_name',
        'role' => 'actor_role',
        'module' => 'module',
        'action' => 'action',
    ];

    /**
     * The columns an exported Activity Logs document carries, and how wide
     * each one is printed: exactly what the Activity Logs table shows.
     *
     * The address and device an entry was made from are deliberately left
     * off. They are kept on the row and they are on screen in the details a
     * row expands to, but they are forensic detail rather than the record of
     * what happened - and seven columns across a page leave the Details
     * column wide enough to actually read.
     *
     * Widths total 100 and are set here rather than in the template so the
     * headings and the columns beneath them cannot fall out of step.
     *
     * @var array<string, string>
     */
    private const LOG_EXPORT_COLUMNS = [
        'Log ID' => '5%',
        // Wide enough for the longest a timestamp gets - "Aug 26, 2026 12:04
        // PM" - because it is printed on one line and a fixed layout would
        // otherwise run it into the column beside it.
        'Date & Time' => '17%',
        'Name' => '14%',
        'Role' => '11%',
        'Module' => '11%',
        'Action' => '16%',
        'Details' => '26%',
    ];

    /**
     * How many entries one exported document may carry.
     *
     * The trail has no ceiling and a PDF does: rendering is what runs out of
     * room, not the query. dompdf lays out every cell as its own box and the
     * cost climbs faster than the row count - measured on this template, 500
     * rows is about thirteen seconds and 175 MB, and 1,000 is four times that.
     *
     * Past this the newest entries are printed and the document says so on its
     * face, rather than a truncated file going out looking complete.
     * Narrowing the dates or naming a user is the answer, and both are on the
     * dialog that asked for it.
     */
    private const EXPORT_ROW_LIMIT = 500;

    /**
     * A contact number as this system accepts it: digits, with the spacing,
     * dashes, brackets and leading + people actually type. Between 7 and 15
     * digits covers local and international numbers alike (ITU E.164 caps at
     * 15), without rejecting a valid number for its formatting.
     */
    private const CONTACT_NUMBER_RULE = User::CONTACT_NUMBER_RULE;

    public function __construct(
        private readonly UserAccountService $accounts,
        private readonly CredentialDelivery $credentials,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index()
    {
        return view('super-admin.configuration', [
            // The filter lists every role an employee can hold; the form only
            // offers the ones it may actually assign.
            'employeeRoles' => collect(User::EMPLOYEE_ROLES)
                ->mapWithKeys(fn (string $role): array => [$role => User::ROLES[$role]])
                ->all(),
            // What THIS administrator may assign: a Super Admin may create an
            // Admin, an Admin may not.
            'assignableRoles' => collect(request()->user()->assignableRoles())
                ->mapWithKeys(fn (string $role): array => [$role => User::ROLES[$role]])
                ->all(),
            'technicianRoles' => User::TECHNICIAN_ROLES,
            // The Activity Logs filters. Every role, not only the employee
            // ones, because a client's own actions are logged too.
            'logRoles' => User::ROLES,
            'logModules' => ActivityLog::MODULES,
            // Who the export dialog may narrow to: every account that has
            // actually done something this administrator is allowed to read
            // about. Drawn through the same visibleTo() scope the table and
            // the export are, so an Admin is not offered a Super Admin whose
            // entries they would then get none of.
            //
            // Shaped for the dialog's search box rather than a select: it is
            // typed into, so each entry carries the text worth matching on -
            // the name, the account code and the address - alongside what is
            // shown once one is picked.
            'logActors' => User::query()
                ->whereIn('id', ActivityLog::query()
                    ->visibleTo(request()->user())
                    ->whereNotNull('actor_id')
                    ->select('actor_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'user_code', 'email', 'role'])
                ->map(fn (User $actor): array => [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'role' => $actor->roleLabel(),
                    'code' => $actor->user_code,
                    'email' => $actor->email,
                ])
                ->values(),
            // The Inquiries filter and the status picker inside its details
            // dialog, both from the one list on the model.
            'inquiryStatuses' => Inquiry::STATUSES,
            // The two editors on the System Settings tab: the public website's
            // sections, and the operational ones beneath them. Two lists rather
            // than one because they are two cards - see SystemContent.
            'contentSections' => SystemContent::SECTIONS,
            'settingsSections' => SystemContent::SETTINGS_SECTIONS,
            'skills' => Skill::query()->orderBy('skill_name')->get(['skill_id', 'skill_name']),
            // Whether the interface may promise that credentials were emailed.
            'mailEnabled' => $this->credentials->isDeliverable(),
        ]);
    }

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    /**
     * The employee table: searched, filtered and paginated in SQL.
     */
    public function employees(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', Rule::in(array_merge(['all'], User::EMPLOYEE_ROLES))],
            'status' => ['nullable', 'string', Rule::in(['all', User::STATUS_ACTIVE, User::STATUS_DEACTIVATED])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $filters = $validator->validated();

        $query = User::query()
            ->employees()
            ->notArchived()
            // Only the technician roles ever have specialties; eager loading
            // here is what keeps the listing to a fixed number of queries.
            ->with(['technician.skills'])
            ->when(
                ! empty($filters['role']) && $filters['role'] !== 'all',
                fn (Builder $q) => $q->where('role', $filters['role'])
            )
            ->when(
                ! empty($filters['status']) && $filters['status'] !== 'all',
                fn (Builder $q) => $q->where('status', $filters['status'])
            );

        $this->applySearch($query, $filters['search'] ?? null, ['user_code', 'name', 'email']);

        $page = $query->orderBy('id')->paginate(self::PER_PAGE)->withQueryString();

        return response()->json([
            'rows' => collect($page->items())
                ->map(fn (User $user): array => $this->employeeRow($user))
                ->all(),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    /**
     * The Registered User table, on the same terms as the employee one.
     */
    public function clients(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(['all', User::STATUS_ACTIVE, User::STATUS_DEACTIVATED])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $filters = $validator->validated();

        $query = User::query()
            ->clients()
            ->notArchived()
            ->when(
                ! empty($filters['status']) && $filters['status'] !== 'all',
                fn (Builder $q) => $q->where('status', $filters['status'])
            );

        $this->applySearch($query, $filters['search'] ?? null, ['user_code', 'name', 'company_name', 'email']);

        $page = $query->orderBy('id')->paginate(self::PER_PAGE)->withQueryString();

        return response()->json([
            'rows' => collect($page->items())
                ->map(fn (User $user): array => $this->clientRow($user))
                ->all(),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    /**
     * The projects one Registered User is connected to, behind View Projects.
     *
     * The relationship already exists in both directions - a project names its
     * account on the contact row, and User::assignedProjects() reads the same
     * link the other way round - so nothing here derives ownership of its own.
     *
     * Archived projects are included and said to be archived. This answers
     * "what is connected to this account", and a project taken out of the
     * active system is still connected to it; leaving it out would make the
     * list disagree with the project's own page.
     */
    public function userProjects(User $user)
    {
        if (! $user->isClient()) {
            return response()->json(['error' => 'That account is not a Registered User.'], 422);
        }

        $projects = $user->assignedProjects()
            ->with(['schedules', 'projectTypes'])
            ->orderByDesc('tbl_projects.project_id')
            ->get()
            // A project carrying two contact rows for the same account would
            // otherwise be listed twice.
            ->unique('project_id')
            ->values();

        return response()->json([
            'account' => [
                'id' => $user->id,
                'user_code' => $user->user_code,
                'full_name' => $user->fullName(),
                'email' => $user->email,
            ],
            'rows' => $projects
                ->map(fn (Project $project): array => $this->registeredUserProjectRow($project))
                ->all(),
        ]);
    }

    /**
     * One row of that list: what the projects table itself shows, minus the
     * columns that only mean something next to the others.
     *
     * @return array<string, mixed>
     */
    private function registeredUserProjectRow(Project $project): array
    {
        $start = $project->schedules->min('start_datetime');
        $end = $project->schedules->max('end_datetime');

        return [
            'id' => $project->project_id,
            'code' => $project->displayCode(),
            'reference_no' => $project->reference_no,
            'name' => $project->name,
            'address' => $project->address,
            'status_label' => $project->statusLabel(),
            'status_badge_class' => $project->statusBadgeClass(),
            'is_archived' => (bool) $project->is_archived,
            'types' => $project->projectTypes->pluck('type_name')->all(),
            'dates' => $start && $end
                ? BusinessTime::format($start, BusinessTime::DATE).' - '.BusinessTime::format($end, BusinessTime::DATE)
                : 'Not scheduled',
            'url' => route('super-admin.projects.show', $project->project_id),
        ];
    }

    /**
     * The audit trail: searched, filtered, sorted and paginated in SQL.
     *
     * Every narrowing happens in the database. The table grows without bound,
     * so nothing here may load more than one page into memory.
     */
    public function activityLogs(Request $request)
    {
        $validator = $this->activityLogFilters($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $filters = $validator->validated();

        $page = $this->activityLogQuery($request, $filters)
            ->orderBy(...$this->activityLogOrder($filters))
            ->orderByDesc('activity_log_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'rows' => collect($page->items())
                ->map(fn (ActivityLog $log): array => $this->activityLogRow($log))
                ->all(),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    /**
     * The same trail, as a PDF.
     *
     * Deliberately built from the same query the table is drawn from - the
     * filters, the search, the sort and, above all, visibleTo() - so an export
     * can never contain a row the page it came from would have hidden. What
     * the dialog adds is a date range and one actor, and both are ordinary
     * filters rather than a second way of asking.
     *
     * The document is assembled entirely on the server, in the same way the
     * system reports are: the browser sends the filters and gets a file back,
     * so what the PDF says never depends on what happened to be drawn on
     * screen when the button was pressed.
     */
    public function exportActivityLogs(Request $request): Response|RedirectResponse
    {
        $validator = $this->activityLogFilters($request, datesRequired: true);

        if ($validator->fails()) {
            return redirect()
                ->route('super-admin.configuration.index')
                ->with('error', $validator->errors()->first());
        }

        // Both ends are here by the time this runs, so the window is the one
        // that was asked for rather than whichever named range the table
        // happened to be showing.
        $filters = $validator->validated();
        $filters['range'] = 'custom';

        $query = $this->activityLogQuery($request, $filters);

        // Counted before the limit is applied, so the document can say how
        // many entries matched as well as how many it is able to print.
        $matched = (clone $query)->count();

        $logs = $query
            ->orderBy(...$this->activityLogOrder($filters))
            ->orderByDesc('activity_log_id')
            ->limit(self::EXPORT_ROW_LIMIT)
            ->get()
            ->map(fn (ActivityLog $log): array => $this->activityLogExportRow($log));

        $appliedFilters = $this->describeLogFilters($filters);

        $this->activityLogger->record(
            ActivityLog::ACTIVITY_LOGS_EXPORTED,
            null,
            sprintf(
                'Exported %d of %d activity log %s as PDF (%s).',
                $logs->count(),
                $matched,
                $matched === 1 ? 'entry' : 'entries',
                $appliedFilters
            )
        );

        $pdf = Pdf::loadView('super-admin.activity-logs-pdf', [
            'columns' => self::LOG_EXPORT_COLUMNS,
            'rows' => $logs,
            'matched' => $matched,
            'limit' => self::EXPORT_ROW_LIMIT,
            'appliedFilters' => $appliedFilters,
            'generatedBy' => $request->user()?->fullName() ?? 'Administrator',
            'generatedAt' => BusinessTime::now(),
            'logoData' => CompanyBranding::logoDataUri(),
            'company' => CompanyBranding::letterhead(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('activity-logs-'.BusinessTime::now()->format('Ymd-His').'.pdf');
    }

    /**
     * What a valid Activity Logs request looks like, for the table and the
     * export alike. One list, so the two cannot come to disagree about which
     * filters exist or what they may hold.
     *
     * The one difference is the date range. The table treats it as optional -
     * "All Time" is a thing somebody reads on screen - while an export must be
     * asked for a period: an export with no period on it is the whole trail,
     * which is neither useful as a document nor something a PDF can hold. The
     * dialog marks both fields required and this is what enforces it.
     */
    private function activityLogFilters(Request $request, bool $datesRequired = false): ValidatorContract
    {
        // `after_or_equal` only where `from` is guaranteed to be there. On the
        // table it is optional, and a rule comparing against a field that was
        // never sent is a rule that fails for the wrong reason.
        $fromRules = [$datesRequired ? 'required' : 'nullable', 'date'];
        $toRules = $datesRequired
            ? ['required', 'date', 'after_or_equal:from']
            : ['nullable', 'date'];

        return Validator::make($request->all(), [
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', Rule::in(array_merge(['all'], array_keys(User::ROLES)))],
            'module' => ['nullable', 'string', Rule::in(array_merge(['all'], ActivityLog::MODULES))],
            'range' => ['nullable', 'string', Rule::in(['all', 'today', 'week', 'month', 'custom'])],
            'from' => $fromRules,
            'to' => $toRules,
            // One actor, by account. The export dialog offers it and the table
            // simply never sends it. An id that is not an account is refused
            // here rather than quietly matching nothing.
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::LOG_SORTS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'from.required' => 'Choose the date to export from.',
            'to.required' => 'Choose the date to export to.',
            'to.after_or_equal' => 'The To date cannot be before the From date.',
        ]);
    }

    /**
     * The filtered trail, before it is ordered.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<ActivityLog>
     */
    private function activityLogQuery(Request $request, array $filters): Builder
    {
        $query = ActivityLog::query()
            // Who may read whose trail is decided in one place, on the model.
            // It is applied here rather than at each call site, so no further
            // way of reading the trail can be added without it.
            ->visibleTo($request->user())
            ->withinRange(
                $filters['range'] ?? null,
                $filters['from'] ?? null,
                $filters['to'] ?? null
            )
            ->when(
                ! empty($filters['role']) && $filters['role'] !== 'all',
                fn (Builder $q) => $q->where('actor_role', $filters['role'])
            )
            ->when(
                ! empty($filters['module']) && $filters['module'] !== 'all',
                fn (Builder $q) => $q->where('module', $filters['module'])
            )
            ->when(
                ! empty($filters['actor_id']),
                fn (Builder $q) => $q->where('actor_id', (int) $filters['actor_id'])
            );

        $this->applySearch($query, $filters['search'] ?? null, ['actor_name', 'action', 'description']);

        return $query;
    }

    /**
     * Newest first unless asked otherwise. Callers add the id after it as a
     * tie-breaker, so paging cannot show the same row twice and an export
     * cannot repeat one between chunks.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: string}
     */
    private function activityLogOrder(array $filters): array
    {
        return [
            self::LOG_SORTS[$filters['sort'] ?? 'date'],
            $filters['direction'] ?? 'desc',
        ];
    }

    /**
     * What an export was narrowed to, as a sentence for the entry that records
     * it. An export nobody can tell the shape of is not much of an audit.
     *
     * @param  array<string, mixed>  $filters
     */
    private function describeLogFilters(array $filters): string
    {
        $from = ! empty($filters['from']) ? BusinessTime::format($filters['from']) : null;
        $to = ! empty($filters['to']) ? BusinessTime::format($filters['to']) : null;

        $parts = [match (true) {
            $from && $to => 'dates '.$from.' - '.$to,
            (bool) $from => 'dates from '.$from,
            (bool) $to => 'dates up to '.$to,
            default => 'all dates',
        }];

        $parts[] = ! empty($filters['actor_id'])
            ? 'user '.(User::find((int) $filters['actor_id'])?->fullName() ?? '#'.$filters['actor_id'])
            : 'all users';

        if (! empty($filters['role']) && $filters['role'] !== 'all') {
            $parts[] = 'role '.(User::ROLES[$filters['role']] ?? $filters['role']);
        }

        if (! empty($filters['module']) && $filters['module'] !== 'all') {
            $parts[] = 'module '.$filters['module'];
        }

        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $parts[] = 'search "'.trim((string) $filters['search']).'"';
        }

        return implode(', ', $parts);
    }

    /**
     * The Archived Accounts table: every account taken off the active lists,
     * most recently archived first.
     *
     * Super Admin only, enforced on the route - archiving and restoring are
     * theirs, and this is the other end of the same privilege.
     */
    public function archivedAccounts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', Rule::in(array_merge(['all'], array_keys(User::ROLES)))],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $filters = $validator->validated();

        $query = User::query()
            ->archived()
            ->with('archiver')
            ->when(
                ! empty($filters['role']) && $filters['role'] !== 'all',
                fn (Builder $q) => $q->where('role', $filters['role'])
            );

        $this->applySearch($query, $filters['search'] ?? null, ['user_code', 'name', 'email']);

        // Rows archived before the timestamp existed sort last rather than
        // first, which is what a null would otherwise do on some engines.
        $page = $query
            ->orderByRaw('archived_at IS NULL')
            ->orderByDesc('archived_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'rows' => collect($page->items())
                ->map(fn (User $user): array => $this->archivedRow($user))
                ->all(),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    /**
     * Put an archived account back. Nothing was deleted, so nothing has to be
     * rebuilt - see UserAccountService::restore().
     */
    public function restoreAccount(User $user)
    {
        try {
            $restored = $this->accounts->restore($user);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Unable to restore account.');
        }

        return response()->json([
            'account' => $this->accountDetail($restored),
            'message' => 'Account restored. It can sign in again.',
        ]);
    }

    /**
     * Everything the edit form needs for one account.
     */
    public function show(User $user)
    {
        $user->load(['technician.skills', 'creator']);

        return response()->json([
            'account' => $this->accountDetail($user),
        ]);
    }

    // ------------------------------------------------------------------
    // Creation
    // ------------------------------------------------------------------

    /**
     * A fresh temporary password for the Regenerate button, so the dialog
     * never has to invent one in the browser.
     */
    public function generatePassword()
    {
        return response()->json(['password' => $this->accounts->generateTemporaryPassword()]);
    }

    public function storeEmployee(Request $request)
    {
        $validator = Validator::make($request->all(), $this->employeeRules(), $this->messages());

        $this->requireSpecialtiesForTechnicians($validator, $request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        try {
            $result = $this->accounts->createEmployee(
                $data,
                array_map('intval', $data['skill_ids'] ?? [])
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Unable to create employee account.');
        }

        return response()->json([
            'account' => $this->accountDetail($result['user']->load('technician.skills')),
            'password' => $result['password'],
            'emailed' => $this->deliverCredentials($result['user'], $result['password']),
            'message' => 'Employee account created.',
        ], 201);
    }

    public function storeClient(Request $request)
    {
        $validator = Validator::make($request->all(), $this->clientRules(), $this->messages());

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        try {
            $result = $this->accounts->createClient($data);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Unable to create Registered User account.');
        }

        return response()->json([
            'account' => $this->accountDetail($result['user']),
            'password' => $result['password'],
            'emailed' => $this->deliverCredentials($result['user'], $result['password']),
            'message' => 'Registered User account created.',
        ], 201);
    }

    // ------------------------------------------------------------------
    // Editing
    // ------------------------------------------------------------------

    public function updateEmployee(Request $request, User $user)
    {
        if (! $user->isEmployee()) {
            return response()->json(['error' => 'That account is not an employee.'], 422);
        }

        $validator = Validator::make($request->all(), $this->employeeRules($user), $this->messages());

        $this->requireSpecialtiesForTechnicians($validator, $request, $user);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        try {
            $updated = $this->accounts->updateEmployee(
                $user,
                $data,
                array_map('intval', $data['skill_ids'] ?? [])
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Unable to update employee account.');
        }

        return response()->json([
            'account' => $this->accountDetail($updated->load('technician.skills')),
            'message' => 'Employee account updated.',
        ]);
    }

    public function updateClient(Request $request, User $user)
    {
        if (! $user->isClient()) {
            return response()->json(['error' => 'That account is not a Registered User.'], 422);
        }

        // The email is missing on purpose: it is the client's login
        // credential, and nothing in this module moves it. So is the picture:
        // clients do not have one.
        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:'.User::CONTACT_NUMBER_LENGTH, self::CONTACT_NUMBER_RULE],
            // Optional here for the same reason it is on the employee form:
            // an older account has none on file, but one that is supplied is
            // still held to the minimum age.
            'birthdate' => AccountAge::rules(required: false),
        ], $this->messages());

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $updated = $this->accounts->updateClient($user, $validator->validated());
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Unable to update Registered User account.');
        }

        return response()->json([
            'account' => $this->accountDetail($updated),
            'message' => 'Registered User account updated.',
        ]);
    }

    /**
     * Issue a new temporary password.
     *
     * An administrator can never read the existing one - it is hashed - so a
     * reset is the only way to restore access. The new value is returned once,
     * here, and emailed when a mailer is configured.
     */
    public function resetPassword(User $user)
    {
        try {
            $password = $this->accounts->resetPassword($user);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Unable to reset password.');
        }

        return response()->json([
            'password' => $password,
            'emailed' => $this->deliverCredentials($user, $password, true),
            'message' => 'Password reset. A new one is required at next sign-in.',
        ]);
    }

    // ------------------------------------------------------------------
    // Status and archiving
    // ------------------------------------------------------------------

    public function setStatus(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_DEACTIVATED])],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $updated = $this->accounts->setStatus(
                $user,
                $validator->validated()['status'] === User::STATUS_ACTIVE
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Unable to change account status.');
        }

        return response()->json([
            'account' => $this->accountDetail($updated),
            'message' => $updated->isActive() ? 'Account activated.' : 'Account deactivated.',
        ]);
    }

    /**
     * Archive rather than delete: the row stays, so project assignments,
     * quotations, reports, documents and the audit trail all keep resolving.
     */
    public function archive(User $user)
    {
        try {
            $this->accounts->archive($user);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Unable to archive account.');
        }

        return response()->json(['message' => 'Account archived.']);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * @return array<string, array<int, mixed>>
     */
    private function employeeRules(?User $user = null): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            // An initial, not a name - see PersonName, which every form
            // collecting one is held to.
            'middle_name' => PersonName::middleInitialRules(),
            'last_name' => ['required', 'string', 'max:100'],
            'contact_number' => ['required', 'string', 'max:'.User::CONTACT_NUMBER_LENGTH, self::CONTACT_NUMBER_RULE],
            // Demanded when the account is opened; optional when an existing
            // one is edited, because accounts predating this field have none
            // on file and the rest of the form must still be saveable.
            'birthdate' => AccountAge::rules(required: $user === null),
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            // A Super Admin being edited keeps their own role, so the form
            // need not - and must not - be able to send it. Everyone else is
            // held to what the signed-in administrator may actually grant,
            // which is narrower for an Admin than for a Super Admin.
            'role' => $user?->isSuperAdmin()
                ? ['nullable', 'string']
                : ['required', 'string', Rule::in(request()->user()->assignableRoles($user))],
            'skill_ids' => ['nullable', 'array'],
            'skill_ids.*' => ['integer', 'exists:tbl_skills,skill_id'],
            // No picture: an internal account starts on the default avatar and
            // its owner sets their own from their Profile page.
        ] + $this->passwordRule($user);
    }

    /**
     * Only a new account takes a password, and only when the administrator
     * chose to type one instead of using the generated value.
     *
     * @return array<string, array<int, mixed>>
     */
    private function passwordRule(?User $user): array
    {
        return $user ? [] : ['password' => ['nullable', 'string', 'min:8', 'max:72']];
    }

    /**
     * The company details and the picture are not asked for when the account
     * is opened; they are filled in later from the edit form.
     *
     * @return array<string, array<int, mixed>>
     */
    private function clientRules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:'.User::CONTACT_NUMBER_LENGTH, self::CONTACT_NUMBER_RULE],
            'birthdate' => AccountAge::rules(),
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
        ];
    }

    /**
     * A technician without a specialty cannot be matched to work, so the two
     * technician roles must carry at least one.
     */
    private function requireSpecialtiesForTechnicians(\Illuminate\Validation\Validator $validator, Request $request, ?User $user = null): void
    {
        // The role checked is the one the account ends up with: a Super Admin
        // keeps theirs whatever the form sends, so they are never asked.
        $role = $user?->isSuperAdmin() ? User::ROLE_SUPER_ADMIN : $request->input('role');

        $validator->after(function ($validator) use ($request, $role): void {
            if (! in_array($role, User::TECHNICIAN_ROLES, true)) {
                return;
            }

            if (count(array_filter((array) $request->input('skill_ids', []))) === 0) {
                $validator->errors()->add(
                    'skill_ids',
                    'Assign at least one specialty to a Technician or Lead Technician.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'email.unique' => 'Another account already uses that email address.',
            'contact_number.regex' => User::CONTACT_NUMBER_MESSAGE,
            'contact_number.max' => User::CONTACT_NUMBER_MESSAGE,
            'password.min' => 'The password must be at least 8 characters.',
            'skill_ids.*.exists' => 'One of the selected specialties no longer exists.',
        ] + PersonName::middleInitialMessages() + AccountAge::messages();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Match a term against the given columns.
     *
     * The columns are a fixed whitelist from the caller, never user input, so
     * they cannot be turned into an injection; the term itself is bound.
     *
     * @param  Builder<User>  $query
     * @param  array<int, string>  $columns
     */
    private function applySearch(Builder $query, ?string $search, array $columns): void
    {
        $term = trim((string) $search);

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';

        $query->where(function (Builder $outer) use ($columns, $like): void {
            foreach ($columns as $column) {
                $outer->orWhere($column, 'like', $like);
            }
        });
    }

    /**
     * Hand the credentials to the mailer, and report whether the account can
     * be told they were emailed.
     */
    private function deliverCredentials(User $user, string $password, bool $isReset = false): bool
    {
        if (! $this->credentials->isDeliverable()) {
            return false;
        }

        return $this->credentials->send($user, $password, $isReset);
    }

    /**
     * A rejected action the service refused is the administrator's problem to
     * fix, so it comes back as a 422 with its own message. Anything else is a
     * fault, and is reported without leaking its internals.
     */
    private function failure(Throwable $exception, string $fallback)
    {
        // A refused role change also carries the projects standing in the way,
        // so the page can list them and link to each team - see
        // TechnicianRoleChangeRules.
        if ($exception instanceof RoleChangeBlocked) {
            return response()->json([
                'error' => $exception->getMessage(),
                'role_change' => $exception->payload(),
            ], 422);
        }

        if ($exception instanceof RuntimeException) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        report($exception);

        return response()->json(['error' => $fallback], 500);
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeRow(User $user): array
    {
        return [
            'id' => $user->id,
            'user_code' => $user->user_code,
            'full_name' => $user->fullName(),
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            'email' => $user->email,
            'status' => $user->status,
            'status_label' => $user->statusLabel(),
            'status_badge_class' => $user->statusBadgeClass(),
            'is_active' => $user->isActive(),
            // Whether the administrator reading this row may act on it. A
            // Super Admin's account is a Super Admin's to manage - see
            // UserAccountService, which refuses the write either way. This is
            // what stops the interface offering buttons that can only fail.
            'manageable' => $this->mayManage($user),
            // Null for an account that never carries a picture, which is what
            // makes the listing fall back to initials for a client.
            'avatar_url' => $user->avatarUrl(),
            'initials' => $user->initials(),
        ];
    }

    /**
     * Whether the signed-in administrator outranks this account.
     *
     * Stated the same way the service states it, so a row that draws its
     * buttons is a row whose endpoints will actually answer.
     */
    private function mayManage(User $user): bool
    {
        return ! $user->isSuperAdmin() || (bool) request()->user()?->isSuperAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    private function clientRow(User $user): array
    {
        // No company name column: a client is listed by who they are and how
        // they sign in. It stays searchable, so somebody who knows only the
        // company can still find the person.
        return [
            'id' => $user->id,
            'user_code' => $user->user_code,
            'full_name' => $user->fullName(),
            'email' => $user->email,
            'status' => $user->status,
            'status_label' => $user->statusLabel(),
            'status_badge_class' => $user->statusBadgeClass(),
            'is_active' => $user->isActive(),
            'avatar_url' => $user->avatarUrl(),
            'initials' => $user->initials(),
        ];
    }

    /**
     * One row of the Archived Accounts table.
     *
     * @return array<string, mixed>
     */
    private function archivedRow(User $user): array
    {
        return [
            'id' => $user->id,
            'user_code' => $user->user_code,
            'full_name' => $user->fullName(),
            'email' => $user->email,
            'role_label' => $user->roleLabel(),
            'status_label' => $user->statusLabel(),
            'status_badge_class' => $user->statusBadgeClass(),
            'archived_at' => $user->archived_at?->format(BusinessTime::DATE_TIME) ?? '—',
            'archived_by' => $user->archiver?->fullName() ?? '—',
        ];
    }

    /**
     * The full record behind the edit form. Registration date, creator and
     * last sign-in are included for display only - no endpoint accepts them.
     *
     * @return array<string, mixed>
     */
    private function accountDetail(User $user): array
    {
        $base = $user->isClient() ? $this->clientRow($user) : $this->employeeRow($user);

        return $base + [
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name,
            'last_name' => $user->last_name,
            'contact_number' => $user->contact_number,
            // Y-m-d, which is what a date input expects back.
            'birthdate' => $user->birthdate?->toDateString(),
            'position' => $user->position,
            'company_address' => $user->company_address,
            'is_client' => $user->isClient(),
            'is_archived' => $user->isArchivedAccount(),
            'skill_ids' => $user->technician?->skills->pluck('skill_id')->all() ?? [],
            'skill_names' => $user->technician?->skills->pluck('skill_name')->all() ?? [],
            'registered_at' => $user->created_at?->format(BusinessTime::DATE_TIME),
            'created_by' => $user->creator?->fullName() ?? 'System',
            'last_login_at' => $user->last_login_at?->format(BusinessTime::DATE_TIME) ?? 'Never',
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, User>  $page
     * @return array<string, mixed>
     */
    /**
     * One row of the audit trail.
     *
     * Read entirely from the snapshot columns rather than the actor relation:
     * an entry has to keep naming who it was at the time, even after that
     * account is renamed, promoted or removed.
     *
     * @return array<string, mixed>
     */
    private function activityLogRow(ActivityLog $log): array
    {
        return [
            'id' => $log->activity_log_id,
            'logged_at' => $log->created_at?->format(BusinessTime::DATE_TIME),
            'logged_at_iso' => $log->created_at?->toIso8601String(),
            'actor_name' => $log->actor_name,
            'role_label' => $log->actorRoleLabel(),
            'role_badge_class' => $log->actorRoleBadgeClass(),
            'module' => $log->module ?? ActivityLog::moduleFor($log->action),
            'action' => $log->action,
            'description' => $log->description,
            'ip_address' => $log->ip_address,
            'browser' => $log->browser,
            'operating_system' => $log->operating_system,
        ];
    }

    /**
     * The same row as a line of the exported document, in LOG_EXPORT_COLUMNS
     * order. Read from the snapshot columns for the same reason the table is:
     * an entry has to keep naming who it was at the time.
     *
     * A dash where a value is missing, matching every other table in the
     * system - an empty cell on a printed page reads as a mistake.
     *
     * @return array<int, string>
     */
    private function activityLogExportRow(ActivityLog $log): array
    {
        return [
            (string) $log->activity_log_id,
            $log->created_at?->format(BusinessTime::DATE_TIME) ?? '—',
            $log->actor_name ?: '—',
            $log->actorRoleLabel(),
            $log->module ?: ActivityLog::moduleFor($log->action),
            $log->action ?: '—',
            $log->description ?: '—',
        ];
    }
}
