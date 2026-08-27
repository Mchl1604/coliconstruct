<?php

namespace App\Support;

use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * What a task's status actually is, decided in one place.
 *
 * The stored `status` column is a record of what somebody set. It is not the
 * whole answer, because two of the states nobody sets:
 *
 *   - Overdue is the passage of time. A task stored as Pending whose due date
 *     went by yesterday is Overdue today and nobody touched it. Waiting for an
 *     administrator to change the column by hand is how a task sat reading
 *     "Pending" three weeks after its deadline.
 *   - Finished Late is the relationship between two stored values. The column
 *     says 'completed' either way; whether it was late is completed_at against
 *     due_date, and flattening that to "Completed" loses the only part a
 *     reader cares about.
 *
 * So the column is never overwritten to make a page read correctly. Every
 * fact - the original status, the due date, the completion instant and who
 * closed it - stays exactly as recorded, and the state a person sees is
 * DERIVED from them here, on every read. That is what makes the same task read
 * the same in the Super Admin portal, the Admin portal, both technician
 * portals, Project Details, the Tasks board, a modal, a table and a report:
 * none of them decides for itself.
 *
 * Dates are read off the office clock. The database stores UTC, which for a
 * timestamp is right and for "has the deadline passed?" is wrong: between 4 PM
 * and midnight in Manila the server's date is still yesterday, so a UTC
 * comparison would keep calling a task on time for the last eight hours of
 * every working day. due_date is a date-only column with no time in it to
 * shift, so it is compared as the calendar date it is - see BusinessTime.
 */
class TaskStatus
{
    /** Nobody holds it yet. */
    public const UNASSIGNED = 'unassigned';

    /** Somebody holds it, the deadline is still ahead. */
    public const PENDING = 'pending';

    /** Being worked on. */
    public const ONGOING = 'ongoing';

    /** Not finished, and the due date has gone by. Derived, never stored. */
    public const OVERDUE = 'overdue';

    /** Finished on or before the due date. */
    public const COMPLETED = 'completed';

    /** Finished, but after the due date. Derived, never stored. */
    public const FINISHED_LATE = 'finished_late';

    /** Called off. */
    public const CANCELLED = 'cancelled';

    /**
     * How each state is written wherever a person reads it.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::UNASSIGNED => 'Unassigned',
        self::PENDING => 'Pending',
        self::ONGOING => 'Ongoing',
        self::OVERDUE => 'Overdue',
        self::COMPLETED => 'Completed',
        self::FINISHED_LATE => 'Finished Late',
        self::CANCELLED => 'Cancelled',
    ];

    /**
     * The badge each state is drawn in. Bootstrap utilities where one fits;
     * `badge-overdue` and `badge-finished-late` are the project's own, because
     * Bootstrap has no colour for either.
     *
     * @var array<string, string>
     */
    public const BADGE_CLASSES = [
        self::UNASSIGNED => 'bg-warning text-dark',
        self::PENDING => 'bg-secondary',
        self::ONGOING => 'bg-primary',
        self::OVERDUE => 'badge-overdue',
        self::COMPLETED => 'bg-success',
        self::FINISHED_LATE => 'badge-finished-late',
        self::CANCELLED => 'bg-danger',
    ];

    /**
     * The same badges as [background, ink] pairs, for the exported PDF - which
     * has no stylesheet and cannot use the classes above.
     *
     * Kept beside BADGE_CLASSES so the printed report and the screen cannot
     * drift into showing one state in two colours. The values are what the
     * Bootstrap utilities and the project's own two classes actually resolve
     * to.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const COLORS = [
        self::UNASSIGNED => ['#ffc107', '#000000'],
        self::PENDING => ['#6c757d', '#ffffff'],
        self::ONGOING => ['#0d6efd', '#ffffff'],
        self::OVERDUE => ['#c9302c', '#ffffff'],
        self::COMPLETED => ['#198754', '#ffffff'],
        self::FINISHED_LATE => ['#7a6410', '#ffffff'],
        self::CANCELLED => ['#dc3545', '#ffffff'],
    ];

    /**
     * The states a finished task can be in. Both are completions - a filter
     * for "done" wants the pair - but they stay distinguishable, which is the
     * whole point of splitting them.
     *
     * @var array<int, string>
     */
    public const COMPLETED_STATES = [self::COMPLETED, self::FINISHED_LATE];

    /**
     * THE calculation. Everything else in this class, and every page in the
     * system, reads the answer from here.
     */
    public static function for(Task $task): string
    {
        if ($task->status === self::CANCELLED) {
            return self::CANCELLED;
        }

        if ($task->status === self::COMPLETED) {
            return self::finishedLate($task) ? self::FINISHED_LATE : self::COMPLETED;
        }

        // Late beats the stored status, the way Overdue beats Ongoing on a
        // project: "this needed doing yesterday" is the more useful of the two
        // things that are true.
        if (self::overdue($task)) {
            return self::OVERDUE;
        }

        return match ($task->status) {
            self::UNASSIGNED => self::UNASSIGNED,
            self::PENDING => self::PENDING,
            self::ONGOING => self::ONGOING,
            // A status this class has not been taught is passed through rather
            // than silently relabelled.
            default => (string) $task->status,
        };
    }

    /**
     * Unfinished, with the due date behind us.
     *
     * Compared against the office's today rather than the server's: the due
     * date was picked off a Manila calendar and has to be read against one.
     * A task with no due date can never be late - there is nothing to be late
     * against - which is why an undated task is chased as Unassigned work
     * instead (see Task::scopeNeedsAssignment).
     */
    public static function overdue(Task $task): bool
    {
        if (! $task->isOpen() || $task->due_date === null) {
            return false;
        }

        return self::dueOn($task) < BusinessTime::today()->toDateString();
    }

    /**
     * Finished after the deadline.
     *
     * Both sides are compared as calendar days, because due_date is a day and
     * not an instant: a task due the 25th and closed at 11 PM on the 25th was
     * finished on time, and calling it late because the timestamp is late in
     * the day would be inventing a deadline nobody set.
     *
     * A completion with no recorded instant - work closed before the system
     * kept one - reads as on time. The alternative is accusing a record of
     * being late on evidence that does not exist.
     */
    public static function finishedLate(Task $task): bool
    {
        if ($task->status !== self::COMPLETED || $task->due_date === null || $task->completed_at === null) {
            return false;
        }

        $closed = BusinessTime::at($task->completed_at)?->toDateString();

        return $closed !== null && $closed > self::dueOn($task);
    }

    /**
     * How many days past the deadline the work landed, or null when it did
     * not land late.
     *
     * Counted between the two calendar days, for the same reason the states
     * are decided that way: measuring the gap between a due date parsed at one
     * midnight and a completion converted to another produces a fraction of a
     * day, and "6.67 days after the due date" is not something to show anyone.
     */
    public static function daysLate(Task $task): ?int
    {
        if (! self::finishedLate($task)) {
            return null;
        }

        $closed = BusinessTime::at($task->completed_at)?->toDateString();

        if ($closed === null) {
            return null;
        }

        return (int) CarbonImmutable::parse(self::dueOn($task))
            ->diffInDays(CarbonImmutable::parse($closed));
    }

    /**
     * The label for a task: "Pending", "Overdue", "Finished Late".
     */
    public static function label(Task $task): string
    {
        return self::labelFor(self::for($task));
    }

    /**
     * The label for a state key, for the callers holding a key rather than a
     * task - a report's summary, a filter's tab.
     */
    public static function labelFor(string $state): string
    {
        return self::LABELS[$state] ?? ucfirst(str_replace('_', ' ', $state));
    }

    /**
     * The badge class for a task, always agreeing with label().
     */
    public static function badgeClass(Task $task): string
    {
        return self::badgeClassFor(self::for($task));
    }

    public static function badgeClassFor(string $state): string
    {
        return self::BADGE_CLASSES[$state] ?? 'bg-secondary';
    }

    /**
     * [background, ink] for a state, for the PDF export.
     *
     * @return array{0: string, 1: string}
     */
    public static function colorFor(string $state): array
    {
        return self::COLORS[$state] ?? ['#6c757d', '#ffffff'];
    }

    /**
     * The derived state as a JSON payload, so a panel drawn in the browser
     * shows what the server-rendered table shows instead of re-deciding it
     * from the raw column. `status` is kept alongside for the callers that
     * genuinely want what is stored.
     *
     * @return array{status: string, status_key: string, status_label: string, status_badge_class: string}
     */
    public static function payload(Task $task): array
    {
        $state = self::for($task);

        return [
            'status' => (string) $task->status,
            'status_key' => $state,
            'status_label' => self::labelFor($state),
            'status_badge_class' => self::badgeClassFor($state),
        ];
    }

    /**
     * The due date as the calendar day it is, 'Y-m-d'.
     *
     * Every comparison in this class is between two 'Y-m-d' strings rather
     * than between two instants, and deliberately so. A date-only column has
     * no time of day in it; turning one into an instant gives it a midnight,
     * and that midnight belongs to whichever timezone did the parsing.
     * Comparing a due date parsed at UTC midnight against a completion
     * converted to Manila midnight is comparing two different eight-hour-apart
     * fictions - which is exactly how a task closed on its due date came out
     * as Finished Late.
     *
     * 'Y-m-d' sorts lexicographically in date order, so a string comparison
     * IS a calendar comparison, and there is no midnight left to misplace.
     */
    private static function dueOn(Task $task): string
    {
        return CarbonImmutable::parse($task->due_date)->toDateString();
    }
}
