<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use Carbon\CarbonImmutable;

/**
 * What status a project's dates say it is in.
 *
 * One answer, in one place, so the projects list, the schedules page, the
 * dashboard, the reports, the availability checker and Resume cannot each
 * reach a different one. Every screen used to work this out for itself, and
 * the copies had already drifted: two controllers held near-identical
 * "promote out of Unscheduled" methods, neither of them able to demote, and
 * neither aware that a project might be on hold.
 *
 * The rule reads off the remaining schedule rows and nothing else:
 *
 *     no rows at all              Unscheduled
 *     earliest date has arrived   Ongoing
 *     every date still to come    Pending
 *
 * Overdue is not written here because it is not stored anywhere: a project
 * whose dates have all passed is Ongoing with nothing left to reach, and
 * Project::isOverdue() derives the label from exactly that. So "all remaining
 * dates are in the past" lands on Ongoing here and reads as Overdue on every
 * screen, which is the same fact told once.
 *
 * What this deliberately never touches:
 *
 *   - A project on hold. A hold is a decision somebody made, and only Resume
 *     ends it. Recalculating a held project would put it straight back to
 *     Ongoing on the strength of the days the hold kept as its record.
 *
 *   - Completed, cancelled, archived and awaiting-confirmation work. Those
 *     are decisions too, not something the calendar implies.
 *
 * "Today" is the office's, not the server's. A schedule is a promise about the
 * working day in Manila - see Schedule::BUSINESS_TIMEZONE - and the two are
 * different dates for eight hours of every day, which is long enough for a
 * project to be called late before it is.
 */
class ProjectStatusRules
{
    /**
     * The statuses this may write. Everything else is somebody's decision.
     *
     * @var array<int, string>
     */
    public const DERIVED_STATUSES = ['unscheduled', 'pending', 'ongoing'];

    /**
     * The status this project's dates imply, or null when its status is not
     * the calendar's to decide.
     */
    public function statusFor(Project $project): ?string
    {
        if ($project->isReadOnly() || $project->isArchived() || $project->on_hold) {
            return null;
        }

        if (! in_array($project->status, self::DERIVED_STATUSES, true)) {
            return null;
        }

        $earliest = $this->earliestStart($project);

        if ($earliest === null) {
            return 'unscheduled';
        }

        // Work whose first day has arrived is under way - and if its last day
        // has passed as well, isOverdue() is what says so. Either way it is
        // not Pending, which means "booked, not started".
        return $earliest->lte(Schedule::businessToday()) ? 'ongoing' : 'pending';
    }

    /**
     * Bring the stored status into line with the dates.
     *
     * @return bool whether anything actually changed, so a caller can say so
     *              rather than announce a change that did not happen
     */
    public function apply(Project $project): bool
    {
        $status = $this->statusFor($project);

        if ($status === null || $status === $project->status) {
            return false;
        }

        $project->update(['status' => $status]);

        return true;
    }

    /**
     * The first day the project is booked for, across every range it holds.
     *
     * Read from the loaded relation when the caller has one - the projects
     * listing asks this of every row - and from the table when it does not.
     */
    private function earliestStart(Project $project): ?CarbonImmutable
    {
        $earliest = $project->relationLoaded('schedules')
            ? $project->schedules->min('start_datetime')
            : $project->schedules()->min('start_datetime');

        return $earliest ? CarbonImmutable::parse($earliest)->startOfDay() : null;
    }
}
