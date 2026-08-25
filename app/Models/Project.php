<?php

namespace App\Models;

use App\Services\SystemContentService;
use App\Support\DisplayCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Project extends Model
{
    protected $table = 'tbl_projects';

    protected $primaryKey = 'project_id';

    /**
     * The work is finished and the client has been asked to confirm it.
     *
     * Deliberately a status of its own rather than a flag on Completed. The
     * two mean different things and grant different powers: this one can be
     * reopened by an administrator, and Completed can never be.
     */
    public const STATUS_AWAITING_CLIENT_CONFIRMATION = 'awaiting_client_confirmation';

    /**
     * How long a client has to confirm before the system closes the project
     * for them, when nobody has configured anything else.
     *
     * The number the system ships with, not the number it uses. The window is
     * a Super Admin setting now - Configuration -> System Settings -> Project
     * Settings - and everything that needs it asks completionConfirmationDays()
     * rather than reading this. This is what that method falls back to on a
     * fresh installation, and on one where the setting has been cleared.
     */
    public const DEFAULT_COMPLETION_CONFIRMATION_DAYS = 7;

    /**
     * The setting the window is stored under.
     */
    public const SETTING_COMPLETION_DAYS = 'project_settings.auto_completion_days';

    /**
     * How long before the deadline the client is reminded that the clock is
     * running.
     *
     * Two days, and derived rather than stored: the reminder is a warning
     * about the deadline, so it has to move when the deadline does. With the
     * shipped seven-day window this puts the reminder on day five, which is
     * exactly where it has always been.
     */
    public const COMPLETION_REMINDER_LEAD_DAYS = 2;

    /**
     * How a project came to be Completed.
     *
     * Recorded rather than inferred: "the client agreed" and "nobody answered
     * for a week" are the same status and very different facts, and only the
     * stored method can tell them apart afterwards.
     */
    public const METHOD_CLIENT_CONFIRMED = 'client_confirmed';

    public const METHOD_AUTO_COMPLETED = 'auto_completed';

    /**
     * Statuses that count as "locked" / view-only records.
     *
     * Awaiting Client Confirmation is one of them, and that single line is
     * what locks the project everywhere: every controller guard, every policy
     * and every page already asks isReadOnly() rather than naming statuses of
     * its own. Reopening is the one action that looks past it, and it does so
     * by asking for this status by name.
     *
     * @var array<int, string>
     */
    public const READ_ONLY_STATUSES = [
        self::STATUS_AWAITING_CLIENT_CONFIRMATION,
        'completed',
        'cancelled',
        'archived',
    ];

    /**
     * Statuses that DO count as a scheduling conflict for a technician.
     * Everything else (unscheduled, on_hold, awaiting_client_confirmation,
     * completed, cancelled, archived) must be ignored by the technician
     * availability checker.
     *
     * Awaiting Client Confirmation is excluded on purpose. Asking for
     * completion is what releases the dates past the completion date, so the
     * crew is free from that moment - waiting on the client's reply must not
     * keep them booked.
     *
     * @var array<int, string>
     */
    public const ACTIVE_PROJECT_STATUSES = ['pending', 'ongoing'];

    /**
     * The statuses a project is in while it is still live work.
     *
     * Wider than ACTIVE_PROJECT_STATUSES, which answers a different question:
     * that one is about whether a technician's DATES are taken, and an
     * unscheduled project has no dates to take. A project still belongs to
     * whoever is on it, though - work not booked yet is somebody's to book -
     * so it counts as live.
     *
     * @var array<int, string>
     */
    public const DERIVED_LIVE_STATUSES = ['unscheduled', 'pending', 'ongoing'];

    /**
     * Statuses a project can be in and still go overdue. A finished or
     * abandoned project can't be late, and a paused one is late on purpose.
     *
     * @var array<int, string>
     */
    public const OVERDUE_CANDIDATE_STATUSES = ['pending', 'ongoing'];

    /**
     * The only status a project can be closed out from.
     *
     * Narrower than ACTIVE_PROJECT_STATUSES on purpose. Pending means "booked,
     * not started" and Unscheduled means "no dates at all" - see
     * ProjectStatusRules, which derives all three - and neither describes work
     * that can be finished. Completing one of those would file a completion
     * report for a visit nobody has made yet, so the technician portal does
     * not offer the button and ProjectPolicy refuses the request behind it.
     *
     * Overdue is deliberately included: it is derived rather than stored, and
     * a late project is stored as Ongoing. Closing one off is exactly what the
     * overdue banner asks the lead to do.
     *
     * @var array<int, string>
     */
    public const COMPLETABLE_STATUSES = ['ongoing'];

    /**
     * Deep red, reserved for overdue. Bootstrap has no such background
     * utility, so `badge-overdue` is defined in superAdminNav.css.
     *
     * It used to be orange, which sat one notch along the wheel from Pending's
     * amber and became the same brown as it once darkened for use as ink - so
     * on the calendar, where both are drawn as outlines, late work and work
     * that has not started yet were told apart only by reading the label. Red
     * is a different hue rather than a different shade of the same one, and it
     * is deliberately deeper than Cancelled's #dc3545: the two are never drawn
     * side by side (a cancelled project leaves the calendar and the schedules
     * page entirely), and where they do meet in a table the labels differ.
     */
    public const OVERDUE_COLOR = '#c9302c';

    /**
     * The order statuses are reported in: roughly the order work moves
     * through, with the two endings last. Alphabetical would put Cancelled
     * first and Ongoing in the middle, which is no order at all.
     *
     * Archived is absent on purpose - it never appears in a report.
     *
     * @var array<int, string>
     */
    public const REPORT_STATUS_ORDER = [
        'unscheduled',
        self::STATUS_AWAITING_CLIENT_CONFIRMATION,
        'pending',
        'ongoing',
        'on_hold',
        'overdue',
        'cancelled',
        'completed',
    ];

    /**
     * Every status as a printed badge: background, then the ink that stays
     * legible on it.
     *
     * The backgrounds are the colours the application already uses - the
     * calendar's, the dashboard breakdown's, OVERDUE_COLOR - gathered in one
     * place so the pie, the badges and the exported PDF cannot drift into
     * three different colour systems. Only the ink is chosen here, and only
     * because a fill picked to sit behind white lettering is not always one
     * white lettering can sit on: amber and cyan need dark ink to stay
     * readable, which is the same call `bg-info text-dark` makes on screen.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const STATUS_COLORS = [
        'unscheduled' => ['#0dcaf0', '#053b45'],
        self::STATUS_AWAITING_CLIENT_CONFIRMATION => ['#6ea67f', '#0f2e1c'],
        'pending' => ['#f0ad4e', '#4a2c00'],
        'ongoing' => ['#0d6efd', '#ffffff'],
        'on_hold' => ['#6c757d', '#ffffff'],
        'overdue' => [self::OVERDUE_COLOR, '#ffffff'],
        'cancelled' => ['#dc3545', '#ffffff'],
        'completed' => ['#198754', '#ffffff'],
        'archived' => ['#212529', '#ffffff'],
    ];

    protected $fillable = [
        'reference_no',
        'name',
        'status',
        'quotation',
        'address',
        'description',
        'on_hold',
        'is_archived',
        'completed_at',
        'completion_summary',
        'completion_remarks',
        'completion_requested_at',
        'completion_requested_by',
        'completion_reminder_sent_at',
        'client_confirmed_at',
        'client_confirmed_by',
        'completion_method',
        'completion_override_reason',
        'completion_override_blockers',
        'completion_overridden_by',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
        'cancelled_at',
        'cancellation_reason',
        'cancellation_remarks',
        'archived_at',
        'archived_by',
        'pre_archive_status',
    ];

    protected $casts = [
        'quotation' => 'decimal:2',
        'on_hold' => 'boolean',
        'is_archived' => 'boolean',
        'completed_at' => 'datetime',
        'completion_requested_at' => 'datetime',
        'completion_reminder_sent_at' => 'datetime',
        'client_confirmed_at' => 'datetime',
        'completion_override_blockers' => 'array',
        'reopened_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'project_id', 'project_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'project_id', 'project_id');
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(Schedule::class, 'project_id', 'project_id')
            ->orderBy('start_datetime');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'project_id', 'project_id')
            ->orderBy('start_datetime');
    }

    public function projectTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            ProjectType::class,
            'tbl_project_type_map',
            'project_id',
            'type_id',
            'project_id',
            'type_id'
        );
    }

    public function projectTechnicians(): HasMany
    {
        return $this->hasMany(ProjectTechnician::class, 'project_id', 'project_id');
    }

    /**
     * The photographs of the CURRENT completion report.
     *
     * Narrowed to the rows no historical report has claimed. A photograph
     * belongs to the completion cycle it was filed in, and reopening a project
     * hands that cycle's photographs to the report it supersedes - see
     * ProjectCompletionHistory. Without the condition, a project completed a
     * second time would show the first visit's photographs beside the second
     * visit's report as though they were one job.
     *
     * Every photograph ever filed is still reachable: through this relation
     * while its cycle is current, and through the historical report's own
     * photos() afterwards.
     */
    public function completionPhotos(): HasMany
    {
        return $this->hasMany(ProjectCompletionPhoto::class, 'project_id', 'project_id')
            ->whereNull('completion_report_id');
    }

    /**
     * The completion reports this project has already been through, newest
     * cycle first. Empty for a project that has never been reopened.
     */
    public function completionReports(): HasMany
    {
        return $this->hasMany(ProjectCompletionReport::class, 'project_id', 'project_id')
            ->orderByDesc('cycle')
            ->orderByDesc('completion_report_id');
    }

    /**
     * Progress and incident reports filed by the technicians on site. This is
     * what a client follows a project by.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(TechnicianReport::class, 'project_id', 'project_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id', 'project_id');
    }

    public function archivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by', 'id');
    }

    /**
     * Whoever pressed Complete Project - a lead technician, an admin or a
     * super admin. Null on work completed before this workflow existed.
     */
    public function completionRequestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completion_requested_by', 'id');
    }

    /**
     * The client account that confirmed. Null when the seven days ran out and
     * the system closed the project instead - which is exactly the case
     * completion_method is there to distinguish.
     */
    public function clientConfirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_confirmed_by', 'id');
    }

    public function reopenedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by', 'id');
    }

    /**
     * The administrator who closed this project over the completion rules.
     * Null on every project completed normally, which is what tells the two
     * apart.
     */
    public function completionOverriddenByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completion_overridden_by', 'id');
    }

    /**
     * Whether this project was signed off against the rules.
     */
    public function completionWasOverridden(): bool
    {
        return filled($this->completion_override_reason);
    }

    /**
     * How the project's key is printed, e.g. PROJ-0007.
     *
     * Not the same thing as reference_no, which the client quotes and which
     * carries the date the project was booked; this is the row's own id in a
     * form somebody can read back.
     */
    public function displayCode(): string
    {
        return DisplayCode::format(DisplayCode::PROJECT, $this->project_id);
    }

    /**
     * Residential or Commercial, as recorded on the project's client.
     *
     * There is no type column on the project itself: the distinction is set
     * once when the wizard writes the client row and is never editable
     * afterwards. Callers that ask this in a loop should eager load `clients`,
     * as the projects and schedules listings already do.
     */
    public function clientType(): ?string
    {
        return $this->clients->first()?->client_type;
    }

    /**
     * Partial-day scheduling is offered on Residential work only. Commercial
     * projects keep the whole-day workflow they have always had.
     */
    public function isResidential(): bool
    {
        return mb_strtolower(trim((string) $this->clientType())) === 'residential';
    }

    /**
     * Team members whose account can no longer be used.
     *
     * Deactivating a technician deliberately does NOT release the dates they
     * are holding: those bookings are real commitments and handing them back
     * silently would leave a project short-crewed with nobody told. What it
     * does instead is make the project say so, which is what this answers.
     *
     * Derived rather than stored, for the same reason Overdue is: it corrects
     * itself. Reactivate the account, or take the person off the team, and the
     * project stops being flagged with nothing to migrate.
     *
     * @return Collection<int, ProjectTechnician>
     */
    public function inactiveCrew(): Collection
    {
        $this->loadMissing('projectTechnicians.technician.account');

        return $this->projectTechnicians
            ->filter(fn (ProjectTechnician $assignment): bool => $assignment->technician !== null
                && ! $assignment->technician->isAssignable())
            ->values();
    }

    /**
     * "Ana Mendoza and Ben Cruz" - the people the warnings name.
     *
     * Written once because four screens print it: the projects listing and
     * Project Details in the administrative portal, and the same two pages in
     * the technician portal, where the lead is told about their own crew. A
     * warning that names different people on different pages is worse than no
     * warning at all.
     */
    public function inactiveCrewNames(): string
    {
        return $this->inactiveCrew()
            ->map(fn (ProjectTechnician $assignment): ?string => $assignment->technician?->name)
            ->filter()
            ->join(', ', ' and ');
    }

    /**
     * The team member who leads this project, or null while it has none.
     *
     * There is no lead column: a project's lead is the member whose ACCOUNT
     * role says so - see Technician::isLead(). Four screens and three services
     * derived that for themselves, which is exactly the shape ProjectTeamRules
     * warns about, so the derivation lives here now and they ask this instead.
     *
     * first() rather than sole(): a project may only ever carry one lead, but
     * that is a rule ProjectTeamRules and TechnicianRoleChangeRules enforce on
     * the way in, and a reader is no place to throw over data that already
     * exists.
     */
    public function leadAssignment(): ?ProjectTechnician
    {
        $this->loadMissing('projectTechnicians.technician.account');

        return $this->projectTechnicians
            ->first(fn (ProjectTechnician $assignment): bool => (bool) $assignment->technician?->isLead());
    }

    /**
     * Whether anybody is leading this project.
     */
    public function hasLead(): bool
    {
        return $this->leadAssignment() !== null;
    }

    /**
     * Whether this project's team can no longer run the job.
     *
     * Two different faults, flagged the same way because they need the same
     * thing from an administrator - somebody has to open the team and fix it.
     *
     * The first is a crew member whose account has been switched off. The
     * second is a project with no lead at all, which is what a demotion used
     * to leave behind silently: the task board, the reports and Complete
     * Project are all gated on the lead's account role, so a lead-less project
     * cannot be run or closed by anybody in the technician portal.
     *
     * TechnicianRoleChangeRules stops new ones being made. This is what says
     * so for the projects that were left that way before it existed.
     */
    public function needsRecrew(): bool
    {
        if ($this->isReadOnly() || $this->isArchived()) {
            return false;
        }

        return ! $this->hasLead() || $this->inactiveCrew()->isNotEmpty();
    }

    /**
     * The short label the projects listings put on a flagged row.
     *
     * A missing lead outranks an inactive crew member when a project has both:
     * the crew can be reassigned by whoever is running the job, and with no
     * lead there is nobody to do it.
     */
    public function recrewFlagLabel(): string
    {
        return $this->hasLead() ? 'Inactive technician' : 'No lead technician';
    }

    /**
     * Whether this project is locked for editing (historical record).
     */
    public function isReadOnly(): bool
    {
        return in_array($this->status, self::READ_ONLY_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Whether this project is in a state a completion can even be offered
     * for - before anything is asked about the work recorded on it.
     *
     * This is the question the Complete Project button asks. What is still
     * outstanding on a completable project is a separate question, and
     * ProjectPolicy::blockersFor() answers it.
     */
    public function isCompletable(): bool
    {
        return ! $this->isReadOnly()
            && ! $this->isArchived()
            && ! $this->on_hold
            && in_array($this->status, self::COMPLETABLE_STATUSES, true);
    }

    public function isAwaitingClientConfirmation(): bool
    {
        return $this->status === self::STATUS_AWAITING_CLIENT_CONFIRMATION;
    }

    /**
     * Whether the work is finished, whichever side of the client's reply the
     * project currently sits on.
     *
     * What the two states share is that the job is done - which is what the
     * projects tables, the client's own list and the reports group on. What
     * they do not share is whether anything can still be done about it, and
     * that question is isAwaitingClientConfirmation() / canBeReopened().
     */
    public function isWorkFinished(): bool
    {
        return $this->isCompleted() || $this->isAwaitingClientConfirmation();
    }

    /**
     * Only a project still waiting on its client may be reopened.
     *
     * A Completed project never can be, by either route into it: the client
     * agreed, or the seven days ran out and the system closed it. Either way
     * it is a historical record, and further work is a new project.
     *
     * What reopening does NOT do is throw the completion report away. The
     * report for the cycle that is ending is filed as history first - see
     * ProjectCompletionHistory - so the project comes back with no current
     * completion report while the previous one stays readable.
     */
    public function canBeReopened(): bool
    {
        return $this->isAwaitingClientConfirmation();
    }

    /**
     * Whether this project has been through a reopen at any point.
     */
    public function wasReopened(): bool
    {
        return $this->reopened_at !== null;
    }

    /**
     * Whether the Project Reopened notice belongs on the page.
     *
     * Only while the project is live again. Once it is completed a second
     * time, the notice would be describing something that is no longer true -
     * the current completion report is what the page has to say then, and the
     * history moves behind View Previous Completion Reports.
     */
    public function showsReopenedNotice(): bool
    {
        return $this->wasReopened() && ! $this->isReadOnly();
    }

    /**
     * Whether there is anything for View Previous Completion Reports to show.
     *
     * Counted through the relation so a page that eager-loaded it does not go
     * back to the database to ask.
     */
    public function hasPreviousCompletionReports(): bool
    {
        return $this->relationLoaded('completionReports')
            ? $this->completionReports->isNotEmpty()
            : $this->completionReports()->exists();
    }

    /**
     * How long a client has to confirm, as configured.
     *
     * The single place the number is decided. Every guard, every email, every
     * countdown and the nightly sweep read it here, so changing the setting
     * changes all of them at once and none of them can drift.
     */
    public static function completionConfirmationDays(): int
    {
        return app(SystemContentService::class)->number(
            self::SETTING_COMPLETION_DAYS,
            self::DEFAULT_COMPLETION_CONFIRMATION_DAYS
        );
    }

    /**
     * The day of the window the reminder goes out on, counted from the day
     * completion was requested.
     *
     * Floored at one so a very short window still reminds somebody rather than
     * quietly reminding them before they were asked. On a window of one or two
     * days the reminder pass excludes projects already due to be completed, so
     * the client hears once - about the completion - which is the right way
     * round.
     */
    public static function completionReminderDays(): int
    {
        return max(1, self::completionConfirmationDays() - self::COMPLETION_REMINDER_LEAD_DAYS);
    }

    /**
     * The moment the system will complete this project if nobody answers.
     *
     * Measured from when completion was requested, never from the completion
     * date: work often finishes days before anybody records it, and the client
     * cannot be held to a clock that started before they were told.
     */
    public function confirmationDeadline(): ?CarbonImmutable
    {
        if (! $this->completion_requested_at) {
            return null;
        }

        return CarbonImmutable::parse($this->completion_requested_at)
            ->addDays(self::completionConfirmationDays());
    }

    /**
     * Whole days left before the project completes itself, floored at zero -
     * a deadline that has passed but not yet been swept up reads as "0 days
     * left" rather than as a negative number.
     */
    public function confirmationDaysRemaining(): ?int
    {
        $deadline = $this->confirmationDeadline();

        if (! $deadline) {
            return null;
        }

        return max(0, (int) ceil(CarbonImmutable::now()->diffInDays($deadline, false)));
    }

    /**
     * How the confirmation window reads to the client: "3 days left", and the
     * two ends of the range spelled out rather than left to arithmetic.
     */
    public function confirmationCountdown(): ?string
    {
        $remaining = $this->confirmationDaysRemaining();

        if ($remaining === null) {
            return null;
        }

        return match (true) {
            $remaining === 0 => 'Less than a day left',
            $remaining === 1 => '1 day left',
            default => $remaining.' days left',
        };
    }

    /**
     * Whether this project has a completion report worth showing - which is
     * true from the moment completion is requested, not only once it is
     * confirmed. The client is being asked to review that report, so they have
     * to be able to read it first.
     */
    public function hasCompletionReport(): bool
    {
        return $this->completed_at !== null || filled($this->completion_summary);
    }

    /**
     * "Confirmed by the client" / "Completed automatically after 7 days", for
     * the project pages and the reports.
     */
    public function completionMethodLabel(): ?string
    {
        return match ($this->completion_method) {
            self::METHOD_CLIENT_CONFIRMED => 'Confirmed by the client',
            self::METHOD_AUTO_COMPLETED => sprintf(
                'Completed automatically after %d days without a reply',
                self::completionConfirmationDays()
            ),
            default => null,
        };
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived' || (bool) $this->is_archived;
    }

    /**
     * The two finished statuses that may still be archived.
     *
     * Awaiting Client Confirmation is deliberately absent, though it is
     * read-only too: that project is a question put to a client that has not
     * been answered yet, and taking it out of the active system would strand
     * both the answer and the seven-day clock. It is archivable the moment it
     * becomes Completed.
     *
     * @var array<int, string>
     */
    public const ARCHIVABLE_READ_ONLY_STATUSES = ['completed', 'cancelled'];

    /**
     * Whether this project may be archived.
     *
     * Asked by the Archive button and again by the endpoint behind it, so a
     * request typed straight at the route is refused on the same rule the page
     * drew - hiding a button is not a permission.
     *
     * Live work has always been archivable. A finished record is archivable
     * too, which is the point of an archive: Completed and Cancelled projects
     * are exactly the ones there is no more to do about, and leaving them in
     * the active listing forever was the reason the list grew without end.
     */
    public function isArchivable(): bool
    {
        if ($this->isArchived()) {
            return false;
        }

        return ! $this->isReadOnly()
            || in_array($this->status, self::ARCHIVABLE_READ_ONLY_STATUSES, true);
    }

    /**
     * The status a restore should put this project back into.
     *
     * Archiving keeps the project whole and records what it was on the way in,
     * so restoring returns it rather than reinventing it: a Completed project
     * comes back Completed, a Cancelled one Cancelled, and work that was under
     * way comes back under way with the dates it still holds.
     *
     * Null means there is nothing recorded to return to, which is true of
     * every project archived by the earlier flow - that one deleted the
     * schedule and the team on the way in, so there is no earlier state left.
     * Those keep restoring as Unscheduled, which is what they always did and
     * the only honest answer for a project with no dates behind it.
     */
    public function statusToRestore(): ?string
    {
        $status = $this->pre_archive_status;

        // 'archived' would be a project archived twice by some earlier path;
        // restoring into it would leave the row unreachable from either list.
        return is_string($status) && $status !== '' && $status !== 'archived'
            ? $status
            : null;
    }

    /**
     * Whether restoring this project would put its schedule back into force -
     * that is, whether the dates it kept would start occupying its technicians
     * again.
     *
     * Only work that is actually booked does. Completed and cancelled records
     * keep their schedules for the history and have never counted against
     * anybody's availability (see ACTIVE_PROJECT_STATUSES), and a paused
     * project holds nobody either - that is what a hold is for. So restoring
     * one of those cannot collide with anything, and only the statuses below
     * have to be screened against the calendar first.
     */
    public function restoreWouldClaimDates(): bool
    {
        return ! $this->on_hold
            && in_array((string) $this->statusToRestore(), self::ACTIVE_PROJECT_STATUSES, true);
    }

    /**
     * The last day the project is scheduled for, across every date range.
     */
    public function scheduleEndsOn(): ?CarbonImmutable
    {
        $end = $this->schedules->max('end_datetime');

        return $end ? CarbonImmutable::parse($end)->startOfDay() : null;
    }

    /**
     * Overdue means the project should have finished by now: its last
     * scheduled day has passed but it is still open.
     *
     * Derived, never stored - a project stops being overdue the moment its
     * schedule is extended or it is completed, with nothing to migrate.
     */
    public function isOverdue(): bool
    {
        if ($this->isReadOnly() || $this->isArchived() || $this->on_hold) {
            return false;
        }

        if (! in_array($this->status, self::OVERDUE_CANDIDATE_STATUSES, true)) {
            return false;
        }

        $endsOn = $this->scheduleEndsOn();

        // The office's today, not the server's. A schedule is a promise about
        // the working day in Manila, and measuring it against a UTC date calls
        // a project late for the eight hours the two disagree by.
        return $endsOn !== null && $endsOn->lt(Schedule::businessToday());
    }

    /**
     * Overdue projects, resolved in SQL for lists and counts.
     *
     * "Has schedules, but none of them reach today" is the same thing as
     * "the latest end date is in the past", without a subquery.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::OVERDUE_CANDIDATE_STATUSES)
            ->where('is_archived', false)
            ->where(function (Builder $holdQuery): void {
                $holdQuery->where('on_hold', false)->orWhereNull('on_hold');
            })
            ->whereHas('schedules')
            ->whereDoesntHave('schedules', function (Builder $scheduleQuery): void {
                $scheduleQuery->whereDate('end_datetime', '>=', Schedule::businessToday()->toDateString());
            });
    }

    /**
     * One place decides how a project's state reads, so the projects table,
     * the tasks table, the calendars and the JSON payloads never disagree.
     */
    public function statusLabel(): string
    {
        if ($this->on_hold) {
            return 'On Hold';
        }

        if ($this->isOverdue()) {
            return 'Overdue';
        }

        return self::statusLabelFor($this->status);
    }

    /**
     * How a stored status reads, without a project in hand.
     *
     * statusLabel() is the one to reach for nearly everywhere: it knows that a
     * paused project reads as On Hold and a late one as Overdue whatever the
     * column says. This is for the cases where there is a status but no
     * project in that state to ask - the archive listing saying what a restore
     * would bring a project back as, for instance.
     */
    public static function statusLabelFor(?string $status): string
    {
        return match ($status) {
            'unscheduled' => 'Unscheduled',
            'pending' => 'Pending',
            'ongoing' => 'Ongoing',
            self::STATUS_AWAITING_CLIENT_CONFIRMATION => 'Awaiting Client Confirmation',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'archived' => 'Archived',
            default => ucfirst((string) $status),
        };
    }

    /**
     * The state this project reads as, as a key rather than a sentence.
     *
     * The same precedence statusLabel() uses - paused beats late, late beats
     * the stored status - so a label and a key never describe two different
     * things. Reports group, order and colour by this.
     */
    public function statusKey(): string
    {
        // A safety net rather than a real case: archived work is filtered out
        // of every report before this is asked. It is here so that a project
        // that somehow reaches a table cannot borrow another status's colour.
        if ($this->isArchived()) {
            return 'archived';
        }

        if ($this->on_hold) {
            return 'on_hold';
        }

        if ($this->isOverdue()) {
            return 'overdue';
        }

        return (string) $this->status;
    }

    /**
     * Which tab of a projects table this project files under.
     *
     * Not the same thing as statusKey(): a tab is a question somebody is
     * asking of the list, and several stored statuses can answer the same one.
     * Unscheduled work reads as Pending, and work awaiting the client's
     * confirmation reads as Completed, because that is where a person looks
     * for it.
     *
     * The precedence is the one the badges already use - paused beats late,
     * late beats the stored status - so a project appears under exactly one
     * tab and no count double-counts it. Stated here so the tab counts, the
     * rows' own data attributes and the browser-side filter cannot disagree;
     * they were three separate copies of this before.
     */
    public function tabKey(): string
    {
        if ($this->on_hold) {
            return 'on_hold';
        }

        if ($this->isOverdue()) {
            return 'overdue';
        }

        return match ($this->status) {
            'unscheduled', 'pending' => 'pending',
            self::STATUS_AWAITING_CLIENT_CONFIRMATION, 'completed' => 'completed',
            default => (string) $this->status,
        };
    }

    /**
     * The tabs that answer "what needs attention", as opposed to "what state
     * is this in".
     *
     * Separate from STATUS_TABS because they are a different kind of question
     * and follow different rules. A status tab is exclusive - tabKey() files
     * each project under exactly one - whereas a project with no crew and no
     * dates needs attention twice and belongs in both of these. They are also
     * drawn only when they hold something: a status reading zero is worth
     * knowing, but a permanent "No Technicians: 0" tab is a reminder of
     * nothing.
     *
     * The dashboard's Urgent Actions link straight at these keys, which is how
     * a figure there opens the list of projects behind it.
     *
     * @var array<string, array{label: string, badge: string}>
     */
    public const ATTENTION_TABS = [
        'unscheduled' => ['label' => 'Unscheduled', 'badge' => 'bg-info text-dark'],
        'no_technicians' => ['label' => 'No Technicians', 'badge' => 'bg-danger'],
        'inactive_crew' => ['label' => 'Inactive Crew', 'badge' => 'bg-danger'],
    ];

    /**
     * Live work with no dates on it at all.
     *
     * Paused work is excluded on the same terms overdue work is: a hold is a
     * decision somebody already took, and putting a project on hold sets its
     * status to Unscheduled, so without this every held project would report
     * itself as needing to be booked.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMissingSchedule(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::DERIVED_LIVE_STATUSES)
            ->where('is_archived', false)
            ->where(fn (Builder $paused) => $paused->where('on_hold', false)->orWhereNull('on_hold'))
            ->whereDoesntHave('schedules');
    }

    /**
     * Live work with nobody on it.
     *
     * A held project still counts here: a hold pauses the dates, not the
     * question of who is going to do the job, and an empty team is what stops
     * it being resumed.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMissingTechnicians(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::DERIVED_LIVE_STATUSES)
            ->where('is_archived', false)
            ->whereDoesntHave('projectTechnicians');
    }

    /**
     * Live work still crewed by somebody who can no longer sign in.
     *
     * Deactivating an account keeps its bookings rather than handing the dates
     * back silently - see inactiveCrew() - so the assignment outlives the
     * login, and somebody has to reassign the work.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithInactiveCrew(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::DERIVED_LIVE_STATUSES)
            ->where('is_archived', false)
            ->whereHas('projectTechnicians.technician', fn ($technician) => $technician
                ->whereDoesntHave('account', fn ($account) => $account
                    ->whereIn('role', User::TECHNICIAN_ROLES)
                    ->where('is_archived', false)
                    ->where('status', User::STATUS_ACTIVE)));
    }

    /**
     * Which attention tabs this project falls under, if any.
     *
     * Unlike tabKey() this returns a list: the questions overlap, and a
     * project can need booking, need a crew and be carrying somebody who has
     * been switched off all at once. Decided here rather than in the view or
     * the browser, for the same reason tabKey() is - the counts beside the
     * tabs, the rows' own attributes and the filter all read this.
     *
     * @return array<int, string>
     */
    public function attentionTabKeys(): array
    {
        // The same window the scopes above count over, so a badge and the
        // rows it promises cannot describe different projects.
        if ($this->isArchived() || ! in_array($this->status, self::DERIVED_LIVE_STATUSES, true)) {
            return [];
        }

        $this->loadMissing(['schedules', 'projectTechnicians.technician.account']);

        $keys = [];

        if (! $this->on_hold && $this->schedules->isEmpty()) {
            $keys[] = 'unscheduled';
        }

        if ($this->projectTechnicians->isEmpty()) {
            $keys[] = 'no_technicians';
        }

        if ($this->inactiveCrew()->isNotEmpty()) {
            $keys[] = 'inactive_crew';
        }

        return $keys;
    }

    /**
     * How many projects fall under each tab, keyed by tabKey().
     *
     * `all` is the whole list, and every other key is the number of rows the
     * matching tab will actually show - counted from the same method the rows
     * are labelled with, so a badge can never promise more rows than the tab
     * has.
     *
     * @param  Collection<int, self>  $projects
     * @return array<string, int>
     */
    public static function tabCounts($projects): array
    {
        $counts = ['all' => $projects->count()] + $projects
            ->groupBy(fn (self $project): string => $project->tabKey())
            ->map->count()
            ->all();

        // Counted separately because they overlap: a project needing dates AND
        // a crew is one row under two tabs, so groupBy() cannot answer this.
        foreach (array_keys(self::ATTENTION_TABS) as $key) {
            $counts[$key] = $projects
                ->filter(fn (self $project): bool => in_array($key, $project->attentionTabKeys(), true))
                ->count();
        }

        return $counts;
    }

    /**
     * The tabs a projects table offers, with the badge each count is printed
     * in. Keyed by tabKey(), in the order work moves through with the two
     * endings last - the same order the reports use.
     *
     * @var array<string, array{label: string, badge: string}>
     */
    public const STATUS_TABS = [
        'all' => ['label' => 'All', 'badge' => 'bg-primary'],
        'pending' => ['label' => 'Pending', 'badge' => 'bg-warning text-dark'],
        'ongoing' => ['label' => 'Ongoing', 'badge' => 'bg-primary'],
        'overdue' => ['label' => 'Overdue', 'badge' => 'badge-overdue'],
        'on_hold' => ['label' => 'On Hold', 'badge' => 'bg-secondary'],
        'completed' => ['label' => 'Completed', 'badge' => 'bg-success'],
        'cancelled' => ['label' => 'Cancelled', 'badge' => 'bg-danger'],
    ];

    /**
     * The tabs to draw above a projects table, each carrying its own count.
     *
     * Every STATUS tab is shown whether or not it holds anything: a count of
     * zero is information, and a tab that appears and disappears as the data
     * changes is a moving target. A caller may narrow the list - the
     * technician portal never lists work it cannot reach - and the order is
     * kept whatever order the keys are given in.
     *
     * The attention tabs are the exception, and are opt-in: they answer "what
     * needs doing" rather than "what state is this in", so an empty one is
     * noise rather than information. See ATTENTION_TABS.
     *
     * @param  Collection<int, self>  $projects
     * @param  array<int, string>|null  $only  the tab keys to draw, or null for all
     * @param  bool  $withAttention  also draw the attention tabs that hold something
     * @return array<int, array{key: string, label: string, badge: string, count: int}>
     */
    public static function statusTabs($projects, ?array $only = null, bool $withAttention = false): array
    {
        $counts = self::tabCounts($projects);

        return collect(self::STATUS_TABS)
            ->when($only !== null, fn ($tabs) => $tabs->only($only))
            // The attention tabs come last, and only the ones holding
            // something: see ATTENTION_TABS on why these appear and disappear
            // where a status tab never does.
            ->merge(
                $withAttention
                    ? collect(self::ATTENTION_TABS)
                        ->filter(fn (array $tab, string $key): bool => ($counts[$key] ?? 0) > 0)
                    : []
            )
            ->map(fn (array $tab, string $key): array => [
                'key' => $key,
                'label' => $tab['label'],
                'badge' => $tab['badge'],
                'count' => (int) ($counts[$key] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * The background and ink a status is printed with.
     *
     * @return array{0: string, 1: string}
     */
    public static function statusColor(string $key): array
    {
        return self::STATUS_COLORS[$key] ?? ['#6c757d', '#ffffff'];
    }

    /**
     * The same state in the few places a full sentence will not fit - a table
     * cell, a card header, a calendar tooltip.
     *
     * This used to shorten Awaiting Completion Confirmation to "Awaiting
     * Confirmation", which left the same state named two different things
     * depending on which screen you were looking at - and "Awaiting
     * Confirmation" did not say what was being confirmed. There is nothing
     * left to shorten, so this is now statusLabel() under a name the callers
     * already use; it is kept so a genuinely short form can be reintroduced in
     * one place rather than in every table.
     */
    public function shortStatusLabel(): string
    {
        return $this->statusLabel();
    }

    /**
     * Bootstrap background class matching statusLabel().
     */
    public function statusBadgeClass(): string
    {
        if ($this->on_hold) {
            return 'bg-secondary';
        }

        if ($this->isOverdue()) {
            return 'badge-overdue';
        }

        return match ($this->status) {
            'unscheduled' => 'bg-info text-dark',
            'pending' => 'bg-warning',
            'ongoing' => 'bg-primary',
            // A lighter green than Completed: the work is done, but the
            // project is not closed yet, and the two must not look identical
            // at a glance.
            self::STATUS_AWAITING_CLIENT_CONFIRMATION => 'bg-success-subtle text-success-emphasis border border-success-subtle',
            'completed' => 'bg-success',
            'cancelled' => 'bg-danger',
            'archived' => 'bg-dark',
            default => 'bg-secondary',
        };
    }

    /**
     * Colour for this project's calendar events.
     */
    public function calendarColor(): string
    {
        if ($this->isOverdue()) {
            return self::OVERDUE_COLOR;
        }

        return match ($this->status) {
            'pending' => '#f0ad4e',
            'ongoing' => '#0d6efd',
            self::STATUS_AWAITING_CLIENT_CONFIRMATION => '#6ea67f',
            'completed' => '#198754',
            default => '#0d6efd',
        };
    }

    /**
     * The darker cut of each status colour, for use as ink rather than as a
     * fill.
     *
     * A colour chosen to sit BEHIND white lettering is the wrong colour to
     * write with. The fills above were picked on exactly that basis, and used
     * unchanged as an outline they come out weak - the amber especially, which
     * is close to invisible as a hairline on white. These are the same hues
     * taken down to roughly 5:1 against white, which is what an outlined
     * booking needs to read at a glance.
     *
     * @var array<string, string>
     */
    public const CALENDAR_INK = [
        'on_hold' => '#5a6570',
        'pending' => '#b26b00',
        'ongoing' => '#0a58ca',
        'overdue' => '#9a2620',
        self::STATUS_AWAITING_CLIENT_CONFIRMATION => '#3f7d53',
        'completed' => '#146c43',
    ];

    /**
     * The colour this project's bookings are drawn WITH, as opposed to filled
     * with - see CALENDAR_INK.
     */
    public function calendarInkColor(): string
    {
        // Paused first, for the same reason statusLabel() asks it first: a
        // held project's stored status is Unscheduled, so without this its
        // remaining bookings would be drawn in the fallback blue and read as
        // work in progress.
        if ($this->on_hold) {
            return self::CALENDAR_INK['on_hold'];
        }

        if ($this->isOverdue()) {
            return self::CALENDAR_INK['overdue'];
        }

        return self::CALENDAR_INK[$this->status] ?? self::CALENDAR_INK['ongoing'];
    }

    /**
     * How a booking is painted on a calendar.
     *
     * A whole-day booking is filled: it owns every hour of the days it
     * covers, and a solid bar says so at a glance. A partial day is outlined,
     * because it does not - it books a few hours and leaves the rest of that
     * date free, and a solid bar would claim the whole of it. The two are
     * different kinds of booking, so they are drawn as different kinds of
     * mark rather than as the same mark in different colours.
     *
     * Stated here rather than in each of the three calendars so they cannot
     * drift into looking like three different products.
     *
     * @param  bool  $filled  whether this booking occupies whole days
     * @return array{backgroundColor: string, borderColor: string, textColor: string}
     */
    public function calendarEventColors(bool $filled = false): array
    {
        $ink = $this->calendarInkColor();

        if ($filled) {
            return [
                'backgroundColor' => $ink,
                'borderColor' => $ink,
                // The ink colours sit at roughly 5:1 against white, so white
                // lettering on them is comfortably readable.
                'textColor' => '#ffffff',
            ];
        }

        return [
            // Transparent rather than white: the day cell behind it may be
            // tinted - today's cell is - and a white bar would punch a hole
            // in that tint.
            'backgroundColor' => 'transparent',
            'borderColor' => $ink,
            'textColor' => $ink,
        ];
    }

    /**
     * The calendar legend: what each colour means, in the order it is read.
     *
     * Stated here so the key and the bookings cannot disagree - it was written
     * out as four hard-coded hex values in the schedules page, and went stale.
     *
     * Awaiting Completion Confirmation is deliberately absent. Its name is too
     * long to sit in a row of one-word keys, and its green is close enough to
     * Completed's that the pair read as one colour anyway - a legend row that
     * takes a third of the line to point at a shade nobody can distinguish is
     * a row that costs more than it explains. The status is still drawn in its
     * own green, and the booking's tooltip and the schedule modal both name it
     * exactly.
     *
     * @return array<int, array{label: string, colour: string}>
     */
    public static function calendarLegend(): array
    {
        return [
            ['label' => 'Pending', 'colour' => self::CALENDAR_INK['pending']],
            ['label' => 'Ongoing', 'colour' => self::CALENDAR_INK['ongoing']],
            ['label' => 'Overdue', 'colour' => self::CALENDAR_INK['overdue']],
            ['label' => 'Completed', 'colour' => self::CALENDAR_INK['completed']],
            ['label' => 'On Hold', 'colour' => self::CALENDAR_INK['on_hold']],
        ];
    }

    /**
     * Cancelled work is kept out of every calendar. Nothing else is.
     *
     * A project awaiting confirmation stays on it. Its remaining dates are the
     * days the crew actually worked, and a calendar that quietly dropped them
     * the moment completion was requested would misreport what happened that
     * week - which is the same reason completed work is still drawn.
     *
     * Held work stays on it for exactly that reason too. A hold cuts the
     * bookings off at the day it was placed (see ScheduleHoldCutoff), so the
     * only dates a held project still holds are days that were actually
     * worked - and a calendar that hid them made the weeks before the pause
     * look empty. Nothing here recreates a released date: what is drawn is
     * whatever rows survived the cutoff, and the hold is what decided that.
     *
     * Drawing a held project is not the same as being able to change it. Its
     * schedule is locked until it is resumed - see scheduleIsEditable() - so
     * clicking one opens the view-only panel.
     */
    public function showsOnCalendar(): bool
    {
        return ! $this->isArchived()
            && $this->status !== 'cancelled';
    }

    /**
     * Whether this project's dates may still be changed.
     *
     * Two different reasons a schedule is fixed, asked as one question so the
     * calendar, the schedules table and the panel a date opens cannot answer
     * it three different ways: the project is a closed record, or it is paused
     * and has to be resumed first. ScheduleController refuses both, and this
     * is what stops a page offering a button that endpoint would reject.
     */
    public function scheduleIsEditable(): bool
    {
        return ! $this->isReadOnly() && ! $this->isArchived() && ! $this->on_hold;
    }
}
