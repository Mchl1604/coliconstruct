<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Skill;
use App\Models\User;
use App\Services\CredentialDelivery;
use App\Services\UserAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
     * A contact number as this system accepts it: digits, with the spacing,
     * dashes, brackets and leading + people actually type. Between 7 and 15
     * digits covers local and international numbers alike (ITU E.164 caps at
     * 15), without rejecting a valid number for its formatting.
     */
    private const CONTACT_NUMBER_RULE = 'regex:/^\+?[0-9][0-9\s\-().]{5,20}$/';

    public function __construct(
        private readonly UserAccountService $accounts,
        private readonly CredentialDelivery $credentials,
    ) {}

    public function index()
    {
        return view('super-admin.configuration', [
            // The filter lists every role an employee can hold; the form only
            // offers the ones it may actually assign.
            'employeeRoles' => collect(User::EMPLOYEE_ROLES)
                ->mapWithKeys(fn (string $role): array => [$role => User::ROLES[$role]])
                ->all(),
            'assignableRoles' => collect(User::ASSIGNABLE_ROLES)
                ->mapWithKeys(fn (string $role): array => [$role => User::ROLES[$role]])
                ->all(),
            'technicianRoles' => User::TECHNICIAN_ROLES,
            // The Activity Logs filters. Every role, not only the employee
            // ones, because a client's own actions are logged too.
            'logRoles' => User::ROLES,
            'logModules' => ActivityLog::MODULES,
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
     * The client table, on the same terms as the employee one.
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
     * The audit trail: searched, filtered, sorted and paginated in SQL.
     *
     * Every narrowing happens in the database. The table grows without bound,
     * so nothing here may load more than one page into memory.
     */
    public function activityLogs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', Rule::in(array_merge(['all'], array_keys(User::ROLES)))],
            'module' => ['nullable', 'string', Rule::in(array_merge(['all'], ActivityLog::MODULES))],
            'range' => ['nullable', 'string', Rule::in(['all', 'today', 'week', 'month', 'custom'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::LOG_SORTS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $filters = $validator->validated();

        $query = ActivityLog::query()
            // Who may read whose trail is decided in one place, on the model.
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
            );

        $this->applySearch($query, $filters['search'] ?? null, ['actor_name', 'action', 'description']);

        // Newest first unless asked otherwise, and always with the id as a
        // tie-breaker so paging cannot show the same row twice.
        $column = self::LOG_SORTS[$filters['sort'] ?? 'date'];
        $direction = $filters['direction'] ?? 'desc';

        $page = $query
            ->orderBy($column, $direction)
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
                array_map('intval', $data['skill_ids'] ?? []),
                $request->file('profile_photo')
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'The employee account could not be created.');
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
            $result = $this->accounts->createClient($data, $request->file('profile_photo'));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'The client account could not be created.');
        }

        return response()->json([
            'account' => $this->accountDetail($result['user']),
            'password' => $result['password'],
            'emailed' => $this->deliverCredentials($result['user'], $result['password']),
            'message' => 'Client account created.',
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
                array_map('intval', $data['skill_ids'] ?? []),
                $request->file('profile_photo')
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'The employee account could not be updated.');
        }

        return response()->json([
            'account' => $this->accountDetail($updated->load('technician.skills')),
            'message' => 'Employee account updated.',
        ]);
    }

    public function updateClient(Request $request, User $user)
    {
        if (! $user->isClient()) {
            return response()->json(['error' => 'That account is not a client.'], 422);
        }

        // The email is missing on purpose: it is the client's login
        // credential, and nothing in this module moves it.
        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:32', self::CONTACT_NUMBER_RULE],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], $this->messages());

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $updated = $this->accounts->updateClient(
                $user,
                $validator->validated(),
                $request->file('profile_photo')
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'The client account could not be updated.');
        }

        return response()->json([
            'account' => $this->accountDetail($updated),
            'message' => 'Client account updated.',
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
            return $this->failure($exception, 'The password could not be reset.');
        }

        return response()->json([
            'password' => $password,
            'emailed' => $this->deliverCredentials($user, $password, true),
            'message' => 'Password reset. The account must choose a new one at next sign-in.',
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
            return $this->failure($exception, 'The account status could not be changed.');
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
            return $this->failure($exception, 'The account could not be archived.');
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
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'contact_number' => ['required', 'string', 'max:32', self::CONTACT_NUMBER_RULE],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            // A Super Admin being edited keeps their own role, so the form
            // need not - and must not - be able to send it.
            'role' => $user?->isSuperAdmin()
                ? ['nullable', 'string']
                : ['required', 'string', Rule::in(User::ASSIGNABLE_ROLES)],
            'skill_ids' => ['nullable', 'array'],
            'skill_ids.*' => ['integer', 'exists:tbl_skills,skill_id'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
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
            'contact_number' => ['required', 'string', 'max:32', self::CONTACT_NUMBER_RULE],
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
            'contact_number.regex' => 'Enter a valid contact number.',
            'password.min' => 'The password must be at least 8 characters.',
            'profile_photo.image' => 'The profile picture must be an image.',
            'profile_photo.max' => 'The profile picture must be 5 MB or smaller.',
            'skill_ids.*.exists' => 'One of the selected specialties no longer exists.',
        ];
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
            'photo_url' => $user->profilePhotoUrl(),
            'initials' => $user->initials(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientRow(User $user): array
    {
        return [
            'id' => $user->id,
            'user_code' => $user->user_code,
            'full_name' => $user->fullName(),
            'company_name' => $user->company_name ?: '—',
            'email' => $user->email,
            'status' => $user->status,
            'status_label' => $user->statusLabel(),
            'status_badge_class' => $user->statusBadgeClass(),
            'is_active' => $user->isActive(),
            'photo_url' => $user->profilePhotoUrl(),
            'initials' => $user->initials(),
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
            'position' => $user->position,
            'company_address' => $user->company_address,
            'is_client' => $user->isClient(),
            'is_archived' => $user->isArchivedAccount(),
            'skill_ids' => $user->technician?->skills->pluck('skill_id')->all() ?? [],
            'skill_names' => $user->technician?->skills->pluck('skill_name')->all() ?? [],
            'registered_at' => $user->created_at?->format('M j, Y g:i A'),
            'created_by' => $user->creator?->fullName() ?? 'System',
            'last_login_at' => $user->last_login_at?->format('M j, Y g:i A') ?? 'Never',
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
            'logged_at' => $log->created_at?->format('M j, Y g:i A'),
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
     * @return array<string, mixed>
     */
    private function paginationMeta($page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ];
    }
}
