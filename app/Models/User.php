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
    'profile_photo_path',
    'position',
    'company_name',
    'company_address',
    'email',
    'role',
    'status',
    'is_archived',
    'archived_at',
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

    public const ROLE_SUPER_ADMIN = 'super_admin';

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

    public function initials(): string
    {
        $first = mb_substr((string) ($this->first_name ?: $this->name), 0, 1);
        $last = mb_substr((string) $this->last_name, 0, 1);

        return mb_strtoupper($first.$last) ?: '?';
    }
}
