<?php

namespace App\Policies;

use App\Models\TechnicianReport;
use App\Models\User;

/**
 * Who may take a report out of the active lists, and who may put it back.
 *
 * The whole permission model for report archiving lives here, so the two pages
 * that offer the action - the Reports page and Project Details, in both
 * portals - cannot disagree about it, and so the endpoint enforces exactly
 * what the buttons promise.
 *
 *   Lead Technician  archives only the reports they filed themselves.
 *   Admin            archives any report, whoever filed it.
 *   Super Admin      archives any report, whoever filed it.
 *
 * Nobody else archives anything: a plain technician files reports but does not
 * manage them, and a client only ever reads their own project's.
 *
 * Ownership is read from the report's own submitter (see
 * TechnicianReport::wasSubmittedBy), never from who happens to be assigned to
 * the project now - two different questions that would otherwise let a lead
 * archive somebody else's account of a visit.
 */
class TechnicianReportPolicy
{
    /**
     * Take a report off the active lists.
     */
    public function archive(User $user, TechnicianReport $report): bool
    {
        return $this->manages($user, $report);
    }

    /**
     * Put an archived report back.
     *
     * The same rule as archiving, deliberately: an administrator restores
     * anything, and a lead undoes their own archive but never reaches into
     * somebody else's report because they hold the lead role.
     */
    public function restore(User $user, TechnicianReport $report): bool
    {
        return $this->manages($user, $report);
    }

    /**
     * Whether this account may act on this report at all.
     *
     * An account that can no longer sign in is refused here as well as at the
     * door, for the same reason ProjectPolicy asks: every power granted over a
     * report is granted through this one method, so the answer should not
     * depend on somebody else having got the door right.
     */
    private function manages(User $user, TechnicianReport $report): bool
    {
        if (! $user->canLogin()) {
            return false;
        }

        if (in_array($user->role, User::ADMINISTRATOR_ROLES, true)) {
            return true;
        }

        return $user->isLeadTechnician() && $report->wasSubmittedBy($user);
    }
}
