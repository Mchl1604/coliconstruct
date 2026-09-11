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

    /**
     * The Registered User account type - the `client` role. The constant names
     * keep the stored role's word so they line up with User::ROLE_CLIENT; the
     * sentences are what an auditor reads, and those say Registered User.
     *
     * Entries recorded before the rename keep their old wording, which is the
     * point of an audit trail: a row says what it said when it was written.
     */
    public const CLIENT_CREATED = 'Registered User Account Created';

    public const CLIENT_UPDATED = 'Registered User Updated';

    public const CLIENT_PASSWORD_RESET = 'Registered User Password Reset';

    public const CLIENT_ACTIVATED = 'Registered User Activated';

    public const CLIENT_DEACTIVATED = 'Registered User Deactivated';

    public const CLIENT_ARCHIVED = 'Registered User Archived';

    public const CLIENT_RESTORED = 'Registered User Restored';

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

    /**
     * The client confirmed, but not on the website, and an administrator wrote
     * it down.
     *
     * Kept apart from PROJECT_COMPLETION_CONFIRMED for the reason the override
     * above is kept apart: a confirmation with no click behind it rests
     * entirely on somebody's word, and "which projects were closed on an
     * administrator's say-so, and what did they say?" has to be a question the
     * trail can be filtered for rather than read for.
     */
    public const PROJECT_COMPLETION_RECORDED_BY_ADMIN = 'Project Completion Recorded By Administrator';

    public const PROJECT_AUTO_COMPLETED = 'Project Automatically Completed';

    public const PROJECT_REOPENED = 'Project Reopened';

    public const PROJECT_CANCELLED = 'Project Cancelled';

    public const PROJECT_ARCHIVED = 'Project Archived';

    public const PROJECT_RESTORED = 'Project Restored';

    public const PROJECT_PUT_ON_HOLD = 'Project Put On Hold';

    public const PROJECT_RESUMED = 'Project Resumed';

    public const PROJECT_RESCHEDULED = 'Project Rescheduled';

    /**
     * The phase structure, which is settled once and then locked.
     *
     * Four entries rather than one, because the four things they record answer
     * four different questions. 'Which projects had their agreed structure
     * changed after work started, and on whose authority?' is the one the
     * whole feature exists to keep answerable, and it needs the override to be
     * a thing a filter can find rather than a sentence somebody has to read.
     */
    public const PROJECT_PHASES_FINALIZED = 'Project Phases Finalized';

    public const PROJECT_PHASE_STRUCTURE_OVERRIDDEN = 'Project Phase Structure Overridden';

    public const PROJECT_PHASE_COMPLETED = 'Project Phase Completed';

    /**
     * A phase closed with tasks still open on it, on a Super Admin's say-so.
     * Kept apart from the entry above for the reason
     * PROJECT_COMPLETION_OVERRIDDEN is kept apart from PROJECT_COMPLETED.
     */
    public const PROJECT_PHASE_COMPLETION_OVERRIDDEN = 'Project Phase Completion Overridden';

    /**
     * The welcome sent to the client address a project was booked under, so
     * they can follow the work on the public website.
     */
    public const INVITATION_EMAIL_SENT = 'Invitation Email Sent';

    /**
     * Which Registered User account a project is connected to.
     *
     * Three entries rather than one because three different things happen and
     * they answer different questions: an account was put on a project that had
     * none, one account was swapped for another, or the project was left with
     * none at all. The project's own client details are untouched by all three
     * - this is about who follows the work on the public website.
     */
    public const REGISTERED_USER_ASSIGNED = 'Registered User Assigned';

    public const REGISTERED_USER_ASSIGNMENT_CHANGED = 'Registered User Assignment Changed';

    public const REGISTERED_USER_REMOVED = 'Registered User Assignment Removed';

    /**
     * An administrator moved a project's contact address onto the one its
     * Registered User signs in with.
     *
     * Filed here beside the assignment entries rather than under User
     * Management, because that is what it is about: nothing on the account
     * changes, and the account's own address is never touched. Only the
     * project's contact details move - see ProjectRegisteredUser::useAccountEmail().
     */
    public const PROJECT_CONTACT_EMAIL_UPDATED = 'Project Contact Email Updated';

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

    /**
     * Somebody took a copy of the audit trail away with them.
     *
     * Filed under Configuration, which is the page it happens on. It is an
     * entry in the very table it describes, which is the point: who read the
     * trail, when, and with what filters is itself something an auditor asks.
     */
    public const ACTIVITY_LOGS_EXPORTED = 'Activity Logs Exported';

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

    public const PHASE_STAGE_CREATED = 'Phase Stage Added';

    public const PHASE_STAGE_UPDATED = 'Phase Stage Updated';

    public const PHASE_STAGE_DELETED = 'Phase Stage Removed';

    public const PHASE_STAGES_REORDERED = 'Phase Stages Reordered';

    /**
     * A project type's default phases and tasks were rewritten. What the NEXT
     * project of that type is offered changes; no existing project moves.
     */
    public const PHASE_TEMPLATE_UPDATED = 'Phase Template Updated';

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
        self::PROJECT_COMPLETION_RECORDED_BY_ADMIN => self::MODULE_PROJECTS,
        self::PROJECT_AUTO_COMPLETED => self::MODULE_PROJECTS,
        self::PROJECT_REOPENED => self::MODULE_PROJECTS,
        self::PROJECT_CANCELLED => self::MODULE_PROJECTS,
        self::PROJECT_ARCHIVED => self::MODULE_PROJECTS,
        self::PROJECT_RESTORED => self::MODULE_PROJECTS,
        self::PROJECT_PUT_ON_HOLD => self::MODULE_PROJECTS,
        self::PROJECT_RESUMED => self::MODULE_PROJECTS,
        self::PROJECT_RESCHEDULED => self::MODULE_PROJECTS,
        self::PROJECT_PHASES_FINALIZED => self::MODULE_PROJECTS,
        self::PROJECT_PHASE_STRUCTURE_OVERRIDDEN => self::MODULE_PROJECTS,
        self::PROJECT_PHASE_COMPLETED => self::MODULE_PROJECTS,
        self::PROJECT_PHASE_COMPLETION_OVERRIDDEN => self::MODULE_PROJECTS,
        self::INVITATION_EMAIL_SENT => self::MODULE_PROJECTS,
        self::REGISTERED_USER_ASSIGNED => self::MODULE_PROJECTS,
        self::REGISTERED_USER_ASSIGNMENT_CHANGED => self::MODULE_PROJECTS,
        self::REGISTERED_USER_REMOVED => self::MODULE_PROJECTS,
        self::PROJECT_CONTACT_EMAIL_UPDATED => self::MODULE_PROJECTS,
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
        self::ACTIVITY_LOGS_EXPORTED => self::MODULE_CONFIGURATION,
        self::CONTACT_INQUIRY_SENT => self::MODULE_CONFIGURATION,
        self::INQUIRY_STATUS_CHANGED => self::MODULE_CONFIGURATION,
        self::INQUIRY_REPLY_SENT => self::MODULE_CONFIGURATION,
        self::INQUIRY_ARCHIVED => self::MODULE_CONFIGURATION,
        self::INQUIRY_RESTORED => self::MODULE_CONFIGURATION,
        self::PROJECT_TYPE_CREATED => self::MODULE_CONFIGURATION,
        self::PROJECT_TYPE_UPDATED => self::MODULE_CONFIGURATION,
        self::PROJECT_TYPE_DELETED => self::MODULE_CONFIGURATION,
        self::PHASE_STAGE_CREATED => self::MODULE_CONFIGURATION,
        self::PHASE_STAGE_UPDATED => self::MODULE_CONFIGURATION,
        self::PHASE_STAGE_DELETED => self::MODULE_CONFIGURATION,
        self::PHASE_STAGES_REORDERED => self::MODULE_CONFIGURATION,
        self::PHASE_TEMPLATE_UPDATED => self::MODULE_CONFIGURATION,
        self::BACKUP_CREATED => self::MODULE_CONFIGURATION,
        self::RESTORE_PERFORMED => self::MODULE_CONFIGURATION,
    ];

    /**
     * What record_type holds for an entry recorded against a project itself.
     * ActivityLogger stores class_basename() of whatever it was handed, so
     * this is the class name rather than the table.
     */
    public const RECORD_PROJECT = 'Project';

    /**
     * The records that belong to a project rather than standing on their own,
     * and how to ask their table which project that is.
     *
     * Read by scopeForProject() to follow an entry filed against a task, a
     * report or a phase back to the job it was done on. A new kind of record
     * that hangs off a project is one line here and nothing else.
     *
     * @var array<string, array{table: string, key: string}>
     */
    public const PROJECT_RECORD_SOURCES = [
        'Task' => ['table' => 'tbl_tasks', 'key' => 'task_id'],
        'TechnicianReport' => ['table' => 'tbl_technician_reports', 'key' => 'id'],
        'ProjectPhase' => ['table' => 'tbl_project_phases', 'key' => 'phase_id'],
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
     * Everything recorded about one project.
     *
     * An entry points at whatever the action was about through record_type and
     * record_id - see ActivityLogger::attributes(). Most of a project's trail
     * points straight at the project, but the work done ON it is filed against
     * the task, report or phase it happened to, and a reader looking at one
     * project wants those too.
     *
     * So the pointer is followed one step: a child record counts when it
     * belongs to this project, asked of the child's own table rather than
     * inferred from the sentence. That is what keeps another project's
     * entries out - an id is only ever matched inside the type it was
     * recorded under, so task 7 can never be read as project 7.
     *
     * The types are listed in PROJECT_RECORD_SOURCES. Anything else - a user
     * account, an inquiry, a configuration change - belongs to no project and
     * is never drawn in.
     */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where(function (Builder $outer) use ($projectId): void {
            $outer->where(function (Builder $direct) use ($projectId): void {
                $direct->where('record_type', self::RECORD_PROJECT)
                    ->where('record_id', $projectId);
            });

            foreach (self::PROJECT_RECORD_SOURCES as $type => $source) {
                $outer->orWhere(function (Builder $child) use ($type, $source, $projectId): void {
                    $child->where('record_type', $type)
                        ->whereIn('record_id', function ($sub) use ($source, $projectId): void {
                            $sub->select($source['key'])
                                ->from($source['table'])
                                ->where('project_id', $projectId);
                        });
                });
            }
        });
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
     *
     * A custom window may be open at either end. "Everything since March" and
     * "everything up to March" are both things somebody exporting the trail
     * asks for, and neither of them is a reason to hand back the whole table -
     * which is what requiring both ends used to do. No bounds at all still
     * means every date, which is what "all" has always meant.
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
            'custom' => [
                $from ? CarbonImmutable::parse($from)->startOfDay() : null,
                $to ? CarbonImmutable::parse($to)->endOfDay() : null,
            ],
            default => [null, null],
        };

        if (! $start && ! $end) {
            return $query;
        }

        // Given back to front, a custom range still means what was intended.
        if ($start && $end && $start->gt($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return $query
            ->when($start, fn (Builder $q): Builder => $q->where('created_at', '>=', $start))
            ->when($end, fn (Builder $q): Builder => $q->where('created_at', '<=', $end));
    }
}
