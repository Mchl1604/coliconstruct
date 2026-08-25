<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded action, for audit.
 *
 * The actor and subject are stored as snapshots - name and role alongside the
 * id - so an entry still reads correctly after either account is renamed,
 * promoted or removed. That is the whole point of an audit trail: it describes
 * what was true when it happened, not what is true now.
 *
 * Every action is a constant, and every constant declares the module it
 * belongs to in MODULE_FOR. A new module needs a constant and an entry in that
 * map; nothing else in the system has to change.
 */
class ActivityLog extends Model
{
    protected $table = 'tbl_activity_logs';

    protected $primaryKey = 'activity_log_id';

    // ------------------------------------------------------------------
    // Modules
    // ------------------------------------------------------------------

    public const MODULE_AUTHENTICATION = 'Authentication';

    public const MODULE_USER_MANAGEMENT = 'User Management';

    public const MODULE_PROJECTS = 'Projects';

    public const MODULE_TASKS = 'Tasks';

    public const MODULE_INVENTORY = 'Inventory';

    public const MODULE_PURCHASE_ORDERS = 'Purchase Orders';

    public const MODULE_REPORTS = 'Reports';

    public const MODULE_CONFIGURATION = 'Configuration';

    /**
     * Every module the filter offers, in the order it lists them. Inventory and
     * Purchase Orders are here before those modules exist so the audit trail is
     * ready for them.
     *
     * @var array<int, string>
     */
    public const MODULES = [
        self::MODULE_AUTHENTICATION,
        self::MODULE_USER_MANAGEMENT,
        self::MODULE_PROJECTS,
        self::MODULE_TASKS,
        self::MODULE_INVENTORY,
        self::MODULE_PURCHASE_ORDERS,
        self::MODULE_REPORTS,
        self::MODULE_CONFIGURATION,
    ];

    // ------------------------------------------------------------------
    // Authentication
    // ------------------------------------------------------------------

    public const LOGIN = 'Signed In';

    public const LOGOUT = 'Signed Out';

    public const LOGIN_FAILED = 'Sign-In Failed';

    public const PASSWORD_RESET_REQUESTED = 'Password Reset Requested';

    public const PASSWORD_RESET_COMPLETED = 'Password Reset Completed';

    public const PASSWORD_CHANGED = 'Password Changed';

    public const PROFILE_UPDATED = 'Profile Updated';

    /**
     * The profile actions an account performs on itself. Filed under
     * Authentication alongside Profile Updated, which is where the trail
     * already keeps "what somebody did to their own account".
     */
    public const PROFILE_NAME_UPDATED = 'Profile Name Updated';

    public const PROFILE_EMAIL_UPDATED = 'Profile Email Updated';

    public const PROFILE_PHOTO_UPLOADED = 'Profile Picture Uploaded';

    public const PROFILE_PHOTO_CHANGED = 'Profile Picture Changed';

    public const PROFILE_PHOTO_REMOVED = 'Profile Picture Removed';

    /**
     * The verification workflows.
     *
     * Filed under Authentication because that is what every one of them
     * ultimately decides: whether somebody may take an action against an
     * address they claim is theirs.
     */
    public const OTP_SENT = 'OTP Sent';

    public const OTP_VERIFIED = 'OTP Verified';

    public const OTP_EXPIRED = 'OTP Expired';

    public const OTP_FAILED = 'OTP Verification Failed';

    public const REGISTRATION_OTP_SENT = 'Registration OTP Sent';

    public const REGISTRATION_VERIFIED = 'Registration Verified';

    public const EMAIL_CHANGE_REQUESTED = 'Email Change Requested';

    public const EMAIL_CHANGED = 'Email Changed';

    /**
     * A client agreeing to the Terms and Conditions.
     *
     * Filed under Authentication for the same reason the verification
     * workflows are: it is a precondition of an account being allowed to carry
     * on, and the audit trail's question about it - "who agreed to which
     * version, and when" - is the same question the sign-in rows answer about
     * access.
     */
    public const TERMS_ACCEPTED = 'Terms Accepted';

    // ------------------------------------------------------------------
    // User Management
    //
    // These strings predate the rest and are left exactly as they were, so
    // rows already recorded keep matching.
    // ------------------------------------------------------------------

    public const EMPLOYEE_CREATED = 'Employee Account Created';

    public const EMPLOYEE_UPDATED = 'Employee Updated';

    public const EMPLOYEE_PASSWORD_RESET = 'Employee Password Reset';

    public const EMPLOYEE_ACTIVATED = 'Employee Activated';

    public const EMPLOYEE_DEACTIVATED = 'Employee Deactivated';

    public const EMPLOYEE_ARCHIVED = 'Employee Archived';

    public const EMPLOYEE_RESTORED = 'Employee Restored';

    public const CLIENT_CREATED = 'Client Account Created';

    public const CLIENT_UPDATED = 'Client Updated';

    public const CLIENT_PASSWORD_RESET = 'Client Password Reset';

    public const CLIENT_ACTIVATED = 'Client Activated';

    public const CLIENT_DEACTIVATED = 'Client Deactivated';

    public const CLIENT_ARCHIVED = 'Client Archived';

    public const CLIENT_RESTORED = 'Client Restored';

    /**
     * The specialty approval workflow. A technician asks; an administrator
     * decides. All three sit in User Management because that is where the
     * deciding happens.
     */
    public const SPECIALTY_REQUEST_SUBMITTED = 'Specialty Request Submitted';

    public const SPECIALTY_REQUEST_APPROVED = 'Specialty Request Approved';

    public const SPECIALTY_REQUEST_REJECTED = 'Specialty Request Rejected';

    // ------------------------------------------------------------------
    // Projects
    // ------------------------------------------------------------------

    public const PROJECT_CREATED = 'Project Created';

    public const PROJECT_UPDATED = 'Project Updated';

    public const PROJECT_COMPLETED = 'Project Completed';

    /**
     * The confirmation workflow.
     *
     * Four entries rather than one, because four different things happen and
     * an auditor needs to tell them apart: the company said the work was done,
     * the client agreed, nobody answered for a week, or an administrator put
     * the project back to work instead.
     */
    public const PROJECT_COMPLETION_REQUESTED = 'Project Completion Requested';

    /**
     * An administrator closed a project the completion rules would have
     * refused - open tasks, no schedule, paused work - and said why.
     *
     * An entry of its own rather than a longer sentence on the one above,
     * because it is the entry somebody goes looking for: "which projects were
     * signed off with work still open, and on whose say-so?" is a question a
     * filter should be able to answer.
     */
    public const PROJECT_COMPLETION_OVERRIDDEN = 'Project Completion Overridden';

    public const PROJECT_COMPLETION_CONFIRMED = 'Project Completion Confirmed';

    public const PROJECT_AUTO_COMPLETED = 'Project Automatically Completed';

    public const PROJECT_REOPENED = 'Project Reopened';

    public const PROJECT_CANCELLED = 'Project Cancelled';

    public const PROJECT_ARCHIVED = 'Project Archived';

    public const PROJECT_RESTORED = 'Project Restored';

    public const PROJECT_PUT_ON_HOLD = 'Project Put On Hold';

    public const PROJECT_RESUMED = 'Project Resumed';

    public const PROJECT_RESCHEDULED = 'Project Rescheduled';

    /**
     * The welcome sent to the client address a project was booked under, so
     * they can follow the work on the public website.
     */
    public const INVITATION_EMAIL_SENT = 'Invitation Email Sent';

    public const LEAD_TECHNICIAN_ASSIGNED = 'Lead Technician Assigned';

    public const TECHNICIAN_ASSIGNED = 'Technician Assigned';

    public const TECHNICIAN_REMOVED = 'Technician Removed';

    // ------------------------------------------------------------------
    // Tasks
    // ------------------------------------------------------------------

    public const TASK_CREATED = 'Task Created';

    public const TASK_UPDATED = 'Task Updated';

    public const TASK_ASSIGNED = 'Task Assigned';

    public const TASK_REASSIGNED = 'Task Reassigned';

    public const TASK_COMPLETED = 'Task Completed';

    public const TASK_CANCELLED = 'Task Cancelled';

    public const TASK_IMAGE_UPLOADED = 'Task Image Uploaded';

    public const TASK_ARCHIVED = 'Task Archived';

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    public const REPORT_GENERATED = 'Report Generated';

    public const REPORT_EXPORTED = 'Report Exported';

    public const REPORT_PRINTED = 'Report Printed';

    /**
     * A technician report taken off the active lists, and put back. Nothing is
     * deleted either way - see TechnicianReportArchive.
     */
    public const REPORT_ARCHIVED = 'Report Archived';

    public const REPORT_RESTORED = 'Report Restored';

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    public const SYSTEM_SETTINGS_UPDATED = 'System Settings Updated';

    // The catalogue of work the company does. A project type and a technician
    // specialty are the same entry, so one action covers both halves.
    /**
     * Somebody wrote in through the public website's Contact page.
     *
     * Filed under Configuration, which is where the public site's own content
     * is administered and where the enquiry itself is now handled - see the
     * Inquiries tab. The trail records the arrival; tbl_inquiries records the
     * message.
     */
    public const CONTACT_INQUIRY_SENT = 'Website Inquiry Sent';

    /**
     * Handling an enquiry once it has arrived. All four sit beside the entry
     * above, under Configuration, because that is the page they happen on.
     */
    public const INQUIRY_STATUS_CHANGED = 'Inquiry Status Changed';

    public const INQUIRY_REPLY_SENT = 'Inquiry Reply Sent';

    public const INQUIRY_ARCHIVED = 'Inquiry Archived';

    public const INQUIRY_RESTORED = 'Inquiry Restored';

    public const PROJECT_TYPE_CREATED = 'Project Type Created';

    public const PROJECT_TYPE_UPDATED = 'Project Type Renamed';

    public const PROJECT_TYPE_DELETED = 'Project Type Removed';

    public const BACKUP_CREATED = 'Backup Created';

    public const RESTORE_PERFORMED = 'Restore Performed';

    /**
     * Which module each action belongs to.
     *
     * The logger reads this rather than making every caller repeat itself, so
     * an action can never be filed under two different modules by two
     * different call sites.
     *
     * @var array<string, string>
     */
    public const MODULE_FOR = [
        self::LOGIN => self::MODULE_AUTHENTICATION,
        self::LOGOUT => self::MODULE_AUTHENTICATION,
        self::LOGIN_FAILED => self::MODULE_AUTHENTICATION,
        self::PASSWORD_RESET_REQUESTED => self::MODULE_AUTHENTICATION,
        self::PASSWORD_RESET_COMPLETED => self::MODULE_AUTHENTICATION,
        self::PASSWORD_CHANGED => self::MODULE_AUTHENTICATION,
        self::PROFILE_UPDATED => self::MODULE_AUTHENTICATION,
        self::PROFILE_NAME_UPDATED => self::MODULE_AUTHENTICATION,
        self::PROFILE_EMAIL_UPDATED => self::MODULE_AUTHENTICATION,
        self::PROFILE_PHOTO_UPLOADED => self::MODULE_AUTHENTICATION,
        self::PROFILE_PHOTO_CHANGED => self::MODULE_AUTHENTICATION,
        self::PROFILE_PHOTO_REMOVED => self::MODULE_AUTHENTICATION,
        self::OTP_SENT => self::MODULE_AUTHENTICATION,
        self::OTP_VERIFIED => self::MODULE_AUTHENTICATION,
        self::OTP_EXPIRED => self::MODULE_AUTHENTICATION,
        self::OTP_FAILED => self::MODULE_AUTHENTICATION,
        self::REGISTRATION_OTP_SENT => self::MODULE_AUTHENTICATION,
        self::REGISTRATION_VERIFIED => self::MODULE_AUTHENTICATION,
        self::EMAIL_CHANGE_REQUESTED => self::MODULE_AUTHENTICATION,
        self::EMAIL_CHANGED => self::MODULE_AUTHENTICATION,
        self::TERMS_ACCEPTED => self::MODULE_AUTHENTICATION,

        self::EMPLOYEE_CREATED => self::MODULE_USER_MANAGEMENT,
        self::EMPLOYEE_UPDATED => self::MODULE_USER_MANAGEMENT,
        self::EMPLOYEE_PASSWORD_RESET => self::MODULE_USER_MANAGEMENT,
        self::EMPLOYEE_ACTIVATED => self::MODULE_USER_MANAGEMENT,
        self::EMPLOYEE_DEACTIVATED => self::MODULE_USER_MANAGEMENT,
        self::EMPLOYEE_ARCHIVED => self::MODULE_USER_MANAGEMENT,
        self::EMPLOYEE_RESTORED => self::MODULE_USER_MANAGEMENT,
        self::CLIENT_CREATED => self::MODULE_USER_MANAGEMENT,
        self::CLIENT_UPDATED => self::MODULE_USER_MANAGEMENT,
        self::CLIENT_PASSWORD_RESET => self::MODULE_USER_MANAGEMENT,
        self::CLIENT_ACTIVATED => self::MODULE_USER_MANAGEMENT,
        self::CLIENT_DEACTIVATED => self::MODULE_USER_MANAGEMENT,
        self::CLIENT_ARCHIVED => self::MODULE_USER_MANAGEMENT,
        self::CLIENT_RESTORED => self::MODULE_USER_MANAGEMENT,
        self::SPECIALTY_REQUEST_SUBMITTED => self::MODULE_USER_MANAGEMENT,
        self::SPECIALTY_REQUEST_APPROVED => self::MODULE_USER_MANAGEMENT,
        self::SPECIALTY_REQUEST_REJECTED => self::MODULE_USER_MANAGEMENT,

        self::PROJECT_CREATED => self::MODULE_PROJECTS,
        self::PROJECT_UPDATED => self::MODULE_PROJECTS,
        self::PROJECT_COMPLETED => self::MODULE_PROJECTS,
        self::PROJECT_COMPLETION_REQUESTED => self::MODULE_PROJECTS,
        self::PROJECT_COMPLETION_OVERRIDDEN => self::MODULE_PROJECTS,
        self::PROJECT_COMPLETION_CONFIRMED => self::MODULE_PROJECTS,
        self::PROJECT_AUTO_COMPLETED => self::MODULE_PROJECTS,
        self::PROJECT_REOPENED => self::MODULE_PROJECTS,
        self::PROJECT_CANCELLED => self::MODULE_PROJECTS,
        self::PROJECT_ARCHIVED => self::MODULE_PROJECTS,
        self::PROJECT_RESTORED => self::MODULE_PROJECTS,
        self::PROJECT_PUT_ON_HOLD => self::MODULE_PROJECTS,
        self::PROJECT_RESUMED => self::MODULE_PROJECTS,
        self::PROJECT_RESCHEDULED => self::MODULE_PROJECTS,
        self::INVITATION_EMAIL_SENT => self::MODULE_PROJECTS,
        self::LEAD_TECHNICIAN_ASSIGNED => self::MODULE_PROJECTS,
        self::TECHNICIAN_ASSIGNED => self::MODULE_PROJECTS,
        self::TECHNICIAN_REMOVED => self::MODULE_PROJECTS,

        self::TASK_CREATED => self::MODULE_TASKS,
        self::TASK_UPDATED => self::MODULE_TASKS,
        self::TASK_ASSIGNED => self::MODULE_TASKS,
        self::TASK_REASSIGNED => self::MODULE_TASKS,
        self::TASK_COMPLETED => self::MODULE_TASKS,
        self::TASK_CANCELLED => self::MODULE_TASKS,
        self::TASK_IMAGE_UPLOADED => self::MODULE_TASKS,
        self::TASK_ARCHIVED => self::MODULE_TASKS,

        self::REPORT_GENERATED => self::MODULE_REPORTS,
        self::REPORT_EXPORTED => self::MODULE_REPORTS,
        self::REPORT_PRINTED => self::MODULE_REPORTS,
        self::REPORT_ARCHIVED => self::MODULE_REPORTS,
        self::REPORT_RESTORED => self::MODULE_REPORTS,

        self::SYSTEM_SETTINGS_UPDATED => self::MODULE_CONFIGURATION,
        self::CONTACT_INQUIRY_SENT => self::MODULE_CONFIGURATION,
        self::INQUIRY_STATUS_CHANGED => self::MODULE_CONFIGURATION,
        self::INQUIRY_REPLY_SENT => self::MODULE_CONFIGURATION,
        self::INQUIRY_ARCHIVED => self::MODULE_CONFIGURATION,
        self::INQUIRY_RESTORED => self::MODULE_CONFIGURATION,
        self::PROJECT_TYPE_CREATED => self::MODULE_CONFIGURATION,
        self::PROJECT_TYPE_UPDATED => self::MODULE_CONFIGURATION,
        self::PROJECT_TYPE_DELETED => self::MODULE_CONFIGURATION,
        self::BACKUP_CREATED => self::MODULE_CONFIGURATION,
        self::RESTORE_PERFORMED => self::MODULE_CONFIGURATION,
    ];

    /**
     * Bootstrap background for each role's badge, so the colour means the same
     * thing on this page as everywhere else.
     *
     * @var array<string, string>
     */
    public const ROLE_BADGE_CLASSES = [
        'super_admin' => 'bg-danger',
        'admin' => 'bg-primary',
        'lead_technician' => 'badge-role-lead',
        'technician' => 'bg-success',
        'client' => 'bg-secondary',
    ];

    protected $fillable = [
        'actor_id',
        'actor_name',
        'actor_role',
        'action',
        'module',
        'description',
        'subject_id',
        'subject_name',
        'subject_role',
        'record_type',
        'record_id',
        'ip_address',
        'browser',
        'operating_system',
        'user_agent',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id', 'id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id', 'id');
    }

    /**
     * The module an action belongs to. Unmapped actions are filed under
     * Configuration rather than left blank, so nothing falls off the page.
     */
    public static function moduleFor(string $action): string
    {
        return self::MODULE_FOR[$action] ?? self::MODULE_CONFIGURATION;
    }

    public function actorRoleLabel(): string
    {
        return User::ROLES[$this->actor_role] ?? 'System';
    }

    public function actorRoleBadgeClass(): string
    {
        return self::ROLE_BADGE_CLASSES[$this->actor_role] ?? 'bg-dark';
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * Newest first, which is the only order the log is ever read in.
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('activity_log_id');
    }

    /**
     * The entries one account is allowed to read.
     *
     * A Super Admin sees everything. An Admin sees their own trail and that of
     * everyone below them, but not another administrator's - an audit trail
     * that one peer can quietly read is not much of a check on the other.
     * Anyone else sees nothing; the page is not theirs.
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        if ($viewer?->isSuperAdmin()) {
            return $query;
        }

        if ($viewer?->role !== 'admin') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($viewer): void {
            $outer->where('actor_id', $viewer->id)
                ->orWhereNotIn('actor_role', ['super_admin', 'admin'])
                // An entry with no role recorded predates this column; it can
                // only have come from User Management, which was administrator
                // -only, so it stays with the administrators.
                ->orWhereNull('actor_role');
        })->where(function (Builder $outer) use ($viewer): void {
            $outer->where('actor_id', $viewer->id)
                ->orWhereNotNull('actor_role');
        });
    }

    /**
     * Narrow to one of the date windows the filter offers.
     */
    public function scopeWithinRange(
        Builder $query,
        ?string $range,
        ?string $from = null,
        ?string $to = null
    ): Builder {
        $today = CarbonImmutable::today();

        [$start, $end] = match ($range) {
            'today' => [$today, $today->endOfDay()],
            'week' => [$today->subDays(6), $today->endOfDay()],
            'month' => [$today->subDays(29), $today->endOfDay()],
            'custom' => $from && $to
                ? [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay()]
                : [null, null],
            default => [null, null],
        };

        if (! $start || ! $end) {
            return $query;
        }

        // Given back to front, a custom range still means what was intended.
        if ($start->gt($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return $query->whereBetween('created_at', [$start, $end]);
    }
}
