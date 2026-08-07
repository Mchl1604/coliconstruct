<?php

namespace App\Services;

use App\Mail\AccountStatusMail;
use App\Models\ActivityLog;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every write the User Management module performs.
 *
 * The controller validates and responds; this decides what an account
 * actually becomes. Keeping it here means the create, edit and status flows
 * cannot drift apart on how a display name is derived, how a technician
 * record is kept in step with a role, or what gets audited.
 *
 * Every public method that touches more than one table runs in a transaction,
 * so a half-made account can never reach the tables.
 */
class UserAccountService
{
    /** Length of a generated temporary password. */
    private const TEMPORARY_PASSWORD_LENGTH = 14;

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
        private readonly EmailService $email
    ) {}

    // ------------------------------------------------------------------
    // Creation
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $skillIds
     * @return array{user: User, password: string}
     */
    public function createEmployee(array $data, array $skillIds = []): array
    {
        // The administrator may type a password of their own; without one, a
        // generated value is used instead.
        $password = $data['password'] ?? $this->generateTemporaryPassword();

        $user = DB::transaction(function () use ($data, $skillIds, $password): User {
            $user = User::create([
                'user_code' => $this->nextUserCode('EMP'),
                'name' => $this->displayName($data),
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'contact_number' => $data['contact_number'],
                'email' => $data['email'],
                'role' => $data['role'],
                'status' => User::STATUS_ACTIVE,
                'is_archived' => false,
                // An administrator opened this account and hands over the
                // credentials directly, so the address has nothing left to
                // prove - the verification workflow is for self-registration.
                'email_verified_at' => now(),
                // The generated password is temporary by definition.
                'must_change_password' => true,
                'created_by' => auth()->id(),
                'password' => $password,
                // No picture: the account starts on the default avatar and its
                // owner sets their own from their Profile page.
            ]);

            $this->syncTechnicianRecord($user, $skillIds);

            return $user;
        });

        $this->activityLogger->record(ActivityLog::EMPLOYEE_CREATED, $user);
        $this->notifications->employeeAccountCreated($user);

        return ['user' => $user, 'password' => $password];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{user: User, password: string}
     */
    public function createClient(array $data): array
    {
        $password = $data['password'] ?? $this->generateTemporaryPassword();

        $user = DB::transaction(function () use ($data, $password): User {
            [$first, $middle, $last] = $this->splitFullName($data['full_name']);

            return User::create([
                'user_code' => $this->nextUserCode('CLI'),
                'name' => trim($data['full_name']),
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'contact_number' => $data['contact_number'],
                'email' => $data['email'],
                // The company details are collected when the account is
                // edited, not when it is opened.
                'company_name' => $data['company_name'] ?? null,
                'company_address' => $data['company_address'] ?? null,
                'role' => User::ROLE_CLIENT,
                'status' => User::STATUS_ACTIVE,
                'is_archived' => false,
                // Opened by an administrator who hands the credentials over
                // directly, so there is no address left to prove.
                'email_verified_at' => now(),
                'must_change_password' => true,
                'created_by' => auth()->id(),
                'password' => $password,
                // Clients never carry a profile picture.
            ]);
        });

        $this->activityLogger->record(ActivityLog::CLIENT_CREATED, $user);
        $this->notifications->clientAccountRegistered($user);

        return ['user' => $user, 'password' => $password];
    }

    /**
     * A client signing themselves up from the public site.
     *
     * The role is hard-coded rather than taken from the form: self-service
     * registration only ever produces a client. Every employee role is granted
     * by an administrator in Configuration, and there is no request field here
     * that could ask for one.
     *
     * Unlike an administrator-created account, the password is the person's
     * own choice, so nothing has to be replaced at first sign-in.
     *
     * @param  array<string, mixed>  $data
     */
    public function registerClient(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            [$first, $middle, $last] = $this->splitFullName($data['full_name']);

            return User::create([
                'user_code' => $this->nextUserCode('CLI'),
                'name' => trim($data['full_name']),
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'contact_number' => $data['contact_number'],
                'email' => $data['email'],
                'role' => User::ROLE_CLIENT,
                'status' => User::STATUS_ACTIVE,
                'is_archived' => false,
                // Unverified on purpose. A self-registered address has proved
                // nothing yet, and the account cannot sign in until a code
                // sent to it comes back - see EmailVerificationController.
                'email_verified_at' => null,
                'must_change_password' => false,
                'created_by' => null,
                'password' => $data['password'],
            ]);
        });

        $this->activityLogger->record(
            ActivityLog::CLIENT_CREATED,
            $user,
            sprintf(
                'Client Account Created - %s (%s), self-registered',
                $user->fullName(),
                $user->user_code
            )
        );

        $this->notifications->clientAccountRegistered($user);

        return $user;
    }

    // ------------------------------------------------------------------
    // Editing
    // ------------------------------------------------------------------

    /**
     * The user code, registration date, creator and login history are never
     * touched here - they are the account's immutable record.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $skillIds
     */
    public function updateEmployee(User $user, array $data, array $skillIds = []): User
    {
        $this->guardEditable($user);

        DB::transaction(function () use ($user, $data, $skillIds): void {
            $user->fill([
                'name' => $this->displayName($data),
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'contact_number' => $data['contact_number'],
                'email' => $data['email'],
                // Saving the role is what applies the permission change; the
                // RBAC system reads this column on every request. A Super
                // Admin keeps theirs - the form cannot assign or remove it.
                'role' => $user->isSuperAdmin() ? User::ROLE_SUPER_ADMIN : $data['role'],
            ]);

            $user->save();

            $this->syncTechnicianRecord($user, $skillIds);
        });

        $this->activityLogger->record(ActivityLog::EMPLOYEE_UPDATED, $user);

        return $user->refresh();
    }

    /**
     * The client's email is their login credential, so it is deliberately not
     * part of this form - changeEmail() handles it under its own workflow.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateClient(User $user, array $data): User
    {
        $this->guardEditable($user);

        DB::transaction(function () use ($user, $data): void {
            [$first, $middle, $last] = $this->splitFullName($data['full_name']);

            $user->fill([
                'name' => trim($data['full_name']),
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'contact_number' => $data['contact_number'],
            ]);

            $user->save();
        });

        $this->activityLogger->record(ActivityLog::CLIENT_UPDATED, $user);

        return $user->refresh();
    }

    // ------------------------------------------------------------------
    // Status, archiving and passwords
    // ------------------------------------------------------------------

    /**
     * Deactivating only stops the account signing in. Assignments, reports
     * and every historical record are left exactly as they are.
     */
    public function setStatus(User $user, bool $active): User
    {
        if ($user->is_archived) {
            throw new RuntimeException('Restore this account before changing its status.');
        }

        $this->guardNotSelf($user, 'You cannot change the status of your own account.');

        $user->status = $active ? User::STATUS_ACTIVE : User::STATUS_DEACTIVATED;
        $user->save();

        $this->activityLogger->record(
            $user->isClient()
                ? ($active ? ActivityLog::CLIENT_ACTIVATED : ActivityLog::CLIENT_DEACTIVATED)
                : ($active ? ActivityLog::EMPLOYEE_ACTIVATED : ActivityLog::EMPLOYEE_DEACTIVATED),
            $user
        );

        $this->notifications->accountStatusChanged($user, $active);

        // The account holder is told directly. A bell notification is no use
        // to somebody who has just been locked out of the system it lives in.
        $this->email->sendTo($user, new AccountStatusMail(
            $user,
            $active ? AccountStatusMail::ACTIVATED : AccountStatusMail::DEACTIVATED
        ));

        return $user;
    }

    /**
     * Archiving takes the account off the active lists without deleting
     * anything: project assignments, quotations, reports, documents and the
     * audit trail all keep pointing at this row.
     */
    public function archive(User $user): User
    {
        $this->guardNotSelf($user, 'You cannot archive your own account.');

        if ($user->is_archived) {
            throw new RuntimeException('That account is already archived.');
        }

        $wasActive = $user->isActive();

        $user->is_archived = true;
        $user->archived_at = now();
        // Recorded on the row as well as in the trail: the Archived Accounts
        // table names who did it, and a table is joined rather than searched.
        $user->archived_by = auth()->id();
        // An archived account must not be able to sign in either.
        $user->status = User::STATUS_DEACTIVATED;
        $user->save();

        $this->activityLogger->record(
            $user->isClient() ? ActivityLog::CLIENT_ARCHIVED : ActivityLog::EMPLOYEE_ARCHIVED,
            $user
        );

        $this->notifications->accountArchived($user);

        // Archiving takes an account's access away exactly as deactivating
        // does, so the holder is told in the same words. An account that was
        // already switched off has been told once and is not told twice.
        if ($wasActive) {
            $this->email->sendTo($user, new AccountStatusMail($user, AccountStatusMail::DEACTIVATED));
        }

        return $user;
    }

    /**
     * Put an archived account back on the active lists.
     *
     * The exact opposite of archive(): nothing was deleted, so nothing has to
     * be rebuilt. The account's projects, reports, documents and audit history
     * never stopped pointing at this row - clearing the flags is the whole of
     * the restore, and it comes back active so it can sign in again.
     */
    public function restore(User $user): User
    {
        if (! $user->is_archived) {
            throw new RuntimeException('That account is not archived.');
        }

        $user->is_archived = false;
        $user->archived_at = null;
        $user->archived_by = null;
        $user->status = User::STATUS_ACTIVE;
        $user->save();

        $this->activityLogger->record(
            $user->isClient() ? ActivityLog::CLIENT_RESTORED : ActivityLog::EMPLOYEE_RESTORED,
            $user
        );

        $this->notifications->accountRestored($user);

        $this->email->sendTo($user, new AccountStatusMail($user, AccountStatusMail::ACTIVATED));

        return $user;
    }

    /**
     * Issue a new temporary password. The administrator never sees the stored
     * one - it is hashed and cannot be read back - only this new value.
     */
    public function resetPassword(User $user): string
    {
        $this->guardEditable($user);
        $this->guardNotSelf(
            $user,
            'You cannot reset your own password here. Use the password change page instead.'
        );
        $this->guardMayResetPassword($user);

        $password = $this->generateTemporaryPassword();

        $user->password = $password;
        $user->must_change_password = true;
        $user->save();

        $this->activityLogger->record(
            $user->isClient() ? ActivityLog::CLIENT_PASSWORD_RESET : ActivityLog::EMPLOYEE_PASSWORD_RESET,
            $user
        );

        $this->notifications->accountPasswordReset($user);

        return $password;
    }

    // ------------------------------------------------------------------
    // Generators
    // ------------------------------------------------------------------

    /**
     * A generated password: letters and digits only, with a lower case, an
     * upper case and a digit all guaranteed, then shuffled so the guaranteed
     * characters aren't always in the same place.
     *
     * Symbols are left out deliberately - these are read off a screen and
     * typed by hand, and the length is what carries the strength: 14
     * alphanumerics is roughly 83 bits of entropy, far past anything a
     * password policy asks for.
     *
     * Built on random_int(), which is cryptographically secure.
     */
    public function generateTemporaryPassword(): string
    {
        // Ambiguous characters (O/0, l/1/I) are left out for the same reason.
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $all = $lower.$upper.$digits;

        $characters = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];

        for ($index = count($characters); $index < self::TEMPORARY_PASSWORD_LENGTH; $index++) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        // shuffle() is not seeded securely, so swap with random_int instead.
        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$characters[$index], $characters[$swap]] = [$characters[$swap], $characters[$index]];
        }

        return implode('', $characters);
    }

    /**
     * The next code in a prefix's sequence: EMP-0001, EMP-0002, CLI-0001.
     *
     * Locks the rows it reads so two administrators submitting at the same
     * moment cannot be handed the same number; the unique index on user_code
     * is the second line of defence.
     */
    public function nextUserCode(string $prefix): string
    {
        $latest = User::query()
            ->where('user_code', 'like', $prefix.'-%')
            ->orderByDesc('user_code')
            ->lockForUpdate()
            ->value('user_code');

        $next = $latest ? ((int) substr((string) $latest, strlen($prefix) + 1)) + 1 : 1;

        return $prefix.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Keep the technician record and its specialties in step with the role.
     *
     * A record is created when a role starts needing one, but never deleted
     * when it stops: project assignments, schedules and reports all reference
     * technician_id, and those have to survive a change of job title. The
     * Technicians page filters on the account's current role, so a former
     * technician simply stops being listed there.
     *
     * @param  array<int, int>  $skillIds
     */
    private function syncTechnicianRecord(User $user, array $skillIds): void
    {
        if (! $user->needsTechnicianRecord()) {
            return;
        }

        $technician = Technician::firstOrCreate(
            ['account_id' => $user->id],
            ['role' => $user->role]
        );

        if ($technician->role !== $user->role) {
            $technician->update(['role' => $user->role]);
        }

        // sync() is what makes a duplicate specialty impossible: the pivot
        // ends up holding exactly these ids, however many times one was sent.
        $technician->skills()->sync(array_values(array_unique($skillIds)));
    }

    /**
     * An archived account is a historical record and stays read-only until it
     * is restored.
     */
    private function guardEditable(User $user): void
    {
        if ($user->is_archived) {
            throw new RuntimeException('Archived accounts cannot be edited until they are restored.');
        }
    }

    /**
     * Stops an administrator locking themselves out. auth() is empty until
     * authentication is built, at which point this starts biting for real.
     */
    private function guardNotSelf(User $user, string $message): void
    {
        if (auth()->id() !== null && auth()->id() === $user->id) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Only a Super Admin may reset a Super Admin's password.
     *
     * A reset hands the new password straight back to whoever asked for it, so
     * without this an Admin could take the system owner's account: reset it,
     * read the value off the screen, and sign in as them. Every other role is
     * fair game for either administrator.
     */
    private function guardMayResetPassword(User $user): void
    {
        $actor = auth()->user();

        if ($actor === null || ! $user->isSuperAdmin() || $actor->isSuperAdmin()) {
            return;
        }

        throw new RuntimeException("A Super Admin's password can only be reset by another Super Admin.");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function displayName(array $data): string
    {
        return implode(' ', array_filter([
            trim((string) $data['first_name']),
            trim((string) ($data['middle_name'] ?? '')),
            trim((string) $data['last_name']),
        ]));
    }

    /**
     * Clients are captured as one full name, but the name parts still get
     * filled so both tables can sort and search the same way.
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function splitFullName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return match (true) {
            count($parts) === 0 => ['Unnamed', null, null],
            count($parts) === 1 => [$parts[0], null, null],
            count($parts) === 2 => [$parts[0], null, $parts[1]],
            default => [
                implode(' ', array_slice($parts, 0, -2)),
                $parts[count($parts) - 2],
                $parts[count($parts) - 1],
            ],
        };
    }
}
