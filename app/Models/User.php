<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'user_code',
    'name',
    'first_name',
    'middle_name',
    'last_name',
    'contact_number',
    'birthdate',
    'profile_photo_path',
    'position',
    'company_name',
    'company_address',
    'email',
    'pending_email',
    'email_verified_at',
    'role',
    'status',
    'is_archived',
    'archived_at',
    'archived_by',
    'must_change_password',
    'last_login_at',
    'created_by',
    'password',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEACTIVATED = 'deactivated';

    /**
     * The shape a contact number has to take, wherever one is entered -
     * registration, the Configuration dialogs, and a person editing their own
     * profile. One copy, so a number accepted by one form is not rejected by
     * the next.
     */
    public const CONTACT_NUMBER_RULE = 'regex:/^\+?[0-9][0-9\s\-().]{5,20}$/';

    /**
     * Every role the RBAC enum accepts, with the label the interface shows.
     * Anything other than `client` is an employee account.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'lead_technician' => 'Lead Technician',
        'technician' => 'Technician',
        'client' => 'Client',
    ];

    /**
     * Every role an employee account can hold, which is what the table lists
     * and filters by. Super Admin is included so the system owner's own
     * account is still visible and manageable.
     *
     * @var array<int, string>
     */
    public const EMPLOYEE_ROLES = ['super_admin', 'admin', 'lead_technician', 'technician'];

    /**
     * The roles the create and edit forms may actually assign.
     *
     * Super Admin is deliberately absent: it is the system owner's role and is
     * granted directly in the database, never handed out from the interface.
     * An account that already holds it keeps it - see ROLE_SUPER_ADMIN below.
     *
     * @var array<int, string>
     */
    public const ASSIGNABLE_ROLES = ['admin', 'lead_technician', 'technician'];

    /**
     * The roles an Admin may assign.
     *
     * An Admin cannot make another Admin: granting a peer the same authority
     * you hold is how an administrative tier quietly stops being controlled by
     * the system's owner. Only a Super Admin hands out `admin`.
     *
     * @var array<int, string>
     */
    public const ADMIN_ASSIGNABLE_ROLES = ['lead_technician', 'technician'];

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    /**
     * The two roles that run the system and share the administrative portal.
     *
     * @var array<int, string>
     */
    public const ADMINISTRATOR_ROLES = ['super_admin', 'admin'];

    public const ROLE_LEAD_TECHNICIAN = 'lead_technician';

    /**
     * Roles that carry a technician record, and therefore specialties.
     *
     * @var array<int, string>
     */
    public const TECHNICIAN_ROLES = ['technician', 'lead_technician'];

    public const ROLE_CLIENT = 'client';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birthdate' => 'date',
            'password' => 'hashed',
            'is_archived' => 'boolean',
            'must_change_password' => 'boolean',
            'archived_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    /**
     * The technician record, present only for the two technician roles.
     */
    public function technician(): HasOne
    {
        return $this->hasOne(Technician::class, 'account_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by', 'id');
    }

    /**
     * The administrator who archived this account, for the Archived Accounts
     * table. Null for an account archived before the column existed.
     */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(self::class, 'archived_by', 'id');
    }

    /**
     * Log entries about this account, rather than the ones it caused.
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'subject_id', 'id');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * Accounts that belong on an active list: never the archived ones.
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * The other half: what the Archived Accounts table lists.
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    public function scopeEmployees(Builder $query): Builder
    {
        return $query->whereIn('role', self::EMPLOYEE_ROLES);
    }

    public function scopeClients(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_CLIENT);
    }

    /**
     * The accounts a future login endpoint may authenticate.
     *
     * Kept as a scope as well as canLogin() so the check can be pushed into
     * SQL by the eventual user provider instead of loading a row first.
     */
    public function scopeLoginable(Builder $query): Builder
    {
        return $query->where('is_archived', false)->where('status', self::STATUS_ACTIVE);
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    /**
     * Whether this account may sign in.
     *
     * Authentication is not built yet; when it is, the login attempt must
     * consult this (or scopeLoginable) so deactivated and archived accounts
     * are turned away while their historical records stay intact.
     */
    public function canLogin(): bool
    {
        return ! $this->is_archived && $this->status === self::STATUS_ACTIVE;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether this account has proved it owns its email address.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Whether this account must confirm its address before it may sign in.
     *
     * Only self-registration produces an unproved address. Public registration
     * is client-only by construction - see AuthController::register - and
     * every employee account is opened by an administrator who already knows
     * the person and hands them their credentials, so an employee has nothing
     * left to prove.
     *
     * Deliberately separate from canLogin(): a deactivated account is turned
     * away with nothing to do about it, while this one is turned towards the
     * verification page.
     */
    public function requiresEmailVerification(): bool
    {
        return $this->isClient() && ! $this->hasVerifiedEmail();
    }

    /**
     * Whether a change of email is waiting on a code sent to the new address.
     */
    public function hasPendingEmailChange(): bool
    {
        return filled($this->pending_email);
    }

    public function isArchivedAccount(): bool
    {
        return (bool) $this->is_archived;
    }

    public function isEmployee(): bool
    {
        return in_array($this->role, self::EMPLOYEE_ROLES, true);
    }

    public function isClient(): bool
    {
        return $this->role === self::ROLE_CLIENT;
    }

    /**
     * Super Admin cannot be assigned from the interface, so an account that
     * already holds it keeps it through every edit.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Whether this account's role carries specialties.
     */
    public function needsTechnicianRecord(): bool
    {
        return in_array($this->role, self::TECHNICIAN_ROLES, true);
    }

    public function isLeadTechnician(): bool
    {
        return $this->role === self::ROLE_LEAD_TECHNICIAN;
    }

    /**
     * Whether the stored password is something Hash::check can ever match.
     *
     * A row whose password column holds anything other than a real hash - a
     * placeholder from a seed file, a truncated import, a value written
     * straight to the database - can never authenticate. The failure looks
     * exactly like a wrong password, so it is worth being able to name.
     */
    public function hasUsablePassword(): bool
    {
        return filled($this->password) && password_get_info($this->password)['algo'] !== null;
    }

    /**
     * Accounts that can never sign in because their stored password is not a
     * hash. Should always be empty; `users:repair-passwords` fixes any that
     * are not.
     */
    public function scopeWithUnusablePassword(Builder $query): Builder
    {
        // Every supported hash starts with a $algo$ prefix and is far longer
        // than this; anything shorter cannot be one. `length` rather than
        // `char_length` so the check works on SQLite as well as MySQL.
        return $query->whereRaw('length(password) < 50');
    }

    /**
     * The technician record's id, or null for an account that has none.
     *
     * Every authorization check in the technician portal is anchored to this,
     * so a null here means "owns no technician work" rather than an error.
     */
    public function technicianId(): ?int
    {
        $technicianId = $this->technician()->value('technician_id');

        return $technicianId === null ? null : (int) $technicianId;
    }

    /**
     * This account's technician record, created on demand.
     *
     * Configuration makes one alongside every technician account, but a role
     * granted directly in the database would not have one, so the portal
     * creates it rather than failing the page.
     */
    public function technicianRecord(): Technician
    {
        return Technician::firstOrCreate(
            ['account_id' => $this->id],
            ['role' => $this->role]
        );
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    /**
     * First + middle + last, falling back to the stored display name for
     * accounts that predate the name parts.
     */
    public function fullName(): string
    {
        $parts = array_filter([$this->first_name, $this->middle_name, $this->last_name]);

        return $parts === [] ? (string) $this->name : implode(' ', $parts);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? ucwords(str_replace('_', ' ', (string) $this->role));
    }

    /**
     * The roles this account may hand out, optionally when editing a specific
     * account.
     *
     * A target's own current role is always included so saving an existing
     * account never fails on a role the editor could not have granted in the
     * first place - an Admin editing another Admin's contact number must not
     * be told their role is invalid.
     *
     * @return array<int, string>
     */
    public function assignableRoles(?self $target = null): array
    {
        $roles = $this->isSuperAdmin()
            ? self::ASSIGNABLE_ROLES
            : self::ADMIN_ASSIGNABLE_ROLES;

        if ($target && $target->role && ! in_array($target->role, $roles, true)) {
            $roles[] = $target->role;
        }

        return array_values($roles);
    }

    public function statusLabel(): string
    {
        if ($this->is_archived) {
            return 'Archived';
        }

        return $this->isActive() ? 'Active' : 'Deactivated';
    }

    /**
     * Bootstrap background class matching statusLabel().
     */
    public function statusBadgeClass(): string
    {
        if ($this->is_archived) {
            return 'bg-dark';
        }

        return $this->isActive() ? 'bg-success' : 'bg-danger';
    }

    /**
     * A served URL for the profile picture, or null so the interface can fall
     * back to initials rather than a broken image.
     */
    public function profilePhotoUrl(): ?string
    {
        return $this->profile_photo_path
            ? asset('storage/'.$this->profile_photo_path)
            : null;
    }

    /**
     * Whether this account has a profile picture at all.
     *
     * Clients never do: they are customers, and the system shows them by name.
     * Everyone who runs the work - technicians, leads, admins, the owner - has
     * one, falling back to the default avatar until they upload their own.
     */
    /**
     * Every account has a picture, clients included. They set their own from
     * their Profile page, and fall back to the default avatar until they do.
     */
    public function usesProfilePhoto(): bool
    {
        return true;
    }

    /**
     * The picture to show for this account, or null when it should not be
     * shown with one at all.
     */
    public function avatarUrl(): ?string
    {
        if (! $this->usesProfilePhoto()) {
            return null;
        }

        return $this->profilePhotoUrl() ?? asset('img/default-avatar.svg');
    }

    public function initials(): string
    {
        $first = mb_substr((string) ($this->first_name ?: $this->name), 0, 1);
        $last = mb_substr((string) $this->last_name, 0, 1);

        return mb_strtoupper($first.$last) ?: '?';
    }
}
