<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    /** Where profile pictures live on the public disk. */
    private const PHOTO_DIRECTORY = 'profile-photos';

    /** Length of a generated temporary password. */
    private const TEMPORARY_PASSWORD_LENGTH = 14;

    public function __construct(private readonly ActivityLogger $activityLogger) {}

    // ------------------------------------------------------------------
    // Creation
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $skillIds
     * @return array{user: User, password: string}
     */
    public function createEmployee(array $data, array $skillIds = [], ?UploadedFile $photo = null): array
    {
        // The administrator may type a password of their own; without one, a
        // generated value is used instead.
        $password = $data['password'] ?? $this->generateTemporaryPassword();

        $user = DB::transaction(function () use ($data, $skillIds, $photo, $password): User {
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
                // The generated password is temporary by definition.
                'must_change_password' => true,
                'created_by' => auth()->id(),
                'password' => $password,
                'profile_photo_path' => $this->storePhoto($photo),
            ]);

            $this->syncTechnicianRecord($user, $skillIds);

            return $user;
        });

        $this->activityLogger->record(ActivityLog::EMPLOYEE_CREATED, $user);

        return ['user' => $user, 'password' => $password];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{user: User, password: string}
     */
    public function createClient(array $data, ?UploadedFile $photo = null): array
    {
        $password = $data['password'] ?? $this->generateTemporaryPassword();

        $user = DB::transaction(function () use ($data, $photo, $password): User {
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
                'must_change_password' => true,
                'created_by' => auth()->id(),
                'password' => $password,
                'profile_photo_path' => $this->storePhoto($photo),
            ]);
        });

        $this->activityLogger->record(ActivityLog::CLIENT_CREATED, $user);

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
    public function updateEmployee(User $user, array $data, array $skillIds = [], ?UploadedFile $photo = null): User
    {
        $this->guardEditable($user);

        DB::transaction(function () use ($user, $data, $skillIds, $photo): void {
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

            if ($photo) {
                $user->profile_photo_path = $this->replacePhoto($user, $photo);
            }

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
    public function updateClient(User $user, array $data, ?UploadedFile $photo = null): User
    {
        $this->guardEditable($user);

        DB::transaction(function () use ($user, $data, $photo): void {
            [$first, $middle, $last] = $this->splitFullName($data['full_name']);

            $user->fill([
                'name' => trim($data['full_name']),
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'contact_number' => $data['contact_number'],
            ]);

            if ($photo) {
                $user->profile_photo_path = $this->replacePhoto($user, $photo);
            }

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

        $user->is_archived = true;
        $user->archived_at = now();
        // An archived account must not be able to sign in either.
        $user->status = User::STATUS_DEACTIVATED;
        $user->save();

        $this->activityLogger->record(
            $user->isClient() ? ActivityLog::CLIENT_ARCHIVED : ActivityLog::EMPLOYEE_ARCHIVED,
            $user
        );

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

    private function storePhoto(?UploadedFile $photo): ?string
    {
        return $photo?->store(self::PHOTO_DIRECTORY, 'public') ?: null;
    }

    /**
     * Swap in a new picture and drop the old file, so replacing a photo
     * repeatedly doesn't accumulate orphans on disk.
     */
    private function replacePhoto(User $user, UploadedFile $photo): string
    {
        $previous = $user->profile_photo_path;
        $path = (string) $this->storePhoto($photo);

        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return $path;
    }
}
