<?php

namespace App\Services;

use App\Exceptions\RoleChangeBlocked;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What a change of job title is allowed to do to work already booked.
 *
 * A project's lead is not stored anywhere. It is derived: the lead is the team
 * member whose ACCOUNT role is Lead Technician - see Project::leadAssignment()
 * and the note in ProjectTeamRules. That makes the role column and the lead
 * assignment the same fact, and it is why editing an account in Configuration
 * used to reach into live projects without saying so:
 *
 *   - Demoting a Lead Technician stripped the lead from EVERY project they
 *     were on, at once. Nothing warned, nothing was logged against the
 *     projects, and no notification went out. The team, the dates and the
 *     tasks all survived, but the task board, technician reports and Complete
 *     Project are gated on the lead's role, so the job could no longer be run
 *     or closed by anybody in the technician portal.
 *
 *   - Promoting a technician did the mirror image. On a project with no lead
 *     they silently BECAME the lead. On one that already had a lead the
 *     project ended up carrying two, which is a state ProjectTeamRules refuses
 *     to save - so the team was frozen until somebody was removed, and which
 *     of the two counted as "the" lead came down to row order.
 *
 * So the rule is: a role change may not decide who leads a project. That is an
 * operational decision and it belongs to an administrator, made against the
 * project, where the replacement can be checked for availability and the
 * people involved can be told. This class refuses the role change until that
 * has been done, and names the projects that are in the way.
 *
 * A note on promotion, because it is stricter than it first looks. A Lead
 * Technician cannot be a supporting member of anything - ProjectTeamRules
 * rejects a lead-role technician in the supporting picker, precisely so a
 * project cannot carry two leads. Promoting somebody therefore has to take
 * them off the live projects they are crewing, whether or not those projects
 * already have a lead, because there is no seat left for them on any of them.
 *
 * Only live work is in the way. Completed, cancelled and archived projects are
 * historical records: they are never checked and never changed, which is the
 * one thing a promotion and a demotion have always got right.
 */
class TechnicianRoleChangeRules
{
    /**
     * Refuse a role change that would decide who leads a live project.
     *
     * Throws RoleChangeBlocked, which ConfigurationController::failure() turns
     * into a 422 carrying two short sentences and the projects in the way -
     * which the Configuration page prints as a list of links, so the person
     * refused can open each project and settle its team.
     */
    public function guard(User $user, string $newRole): void
    {
        $currentRole = (string) $user->role;

        if ($currentRole === $newRole) {
            return;
        }

        // Losing the lead role, whatever is being moved to - Technician, Admin
        // or anything else. What matters is that the account stops being a
        // Lead Technician, not what it becomes instead.
        if ($currentRole === User::ROLE_LEAD_TECHNICIAN) {
            $this->guardDemotion($user);

            return;
        }

        if ($newRole === User::ROLE_LEAD_TECHNICIAN) {
            $this->guardPromotion($user);
        }
    }

    /**
     * The live projects this account currently leads.
     *
     * Public so a caller can ask before it submits - the same question the
     * guard asks, answered without having to provoke the refusal.
     *
     * @return Collection<int, Project>
     */
    public function liveProjectsLedBy(User $user): Collection
    {
        $technicianId = $user->technician?->technician_id;

        if (! $technicianId) {
            return collect();
        }

        return $this->liveProjectsCrewedBy($user)
            ->filter(fn (Project $project): bool => (int) ($project->leadAssignment()?->technician_id ?? 0) === (int) $technicianId)
            ->values();
    }

    /**
     * Every live project this account is on the team of, lead or not.
     *
     * @return Collection<int, Project>
     */
    public function liveProjectsCrewedBy(User $user): Collection
    {
        $technicianId = $user->technician?->technician_id;

        if (! $technicianId) {
            return collect();
        }

        return Project::query()
            // Loaded here so leadAssignment() costs nothing per project: it is
            // asked of every row that comes back.
            ->with('projectTechnicians.technician.account')
            ->whereIn('status', Project::DERIVED_LIVE_STATUSES)
            ->where('is_archived', false)
            ->whereHas(
                'projectTechnicians',
                fn ($assignment) => $assignment->where('technician_id', $technicianId)
            )
            ->orderBy('project_id')
            ->get();
    }

    /**
     * A lead may not put their projects down by changing their job title.
     */
    private function guardDemotion(User $user): void
    {
        $projects = $this->liveProjectsLedBy($user);

        if ($projects->isEmpty()) {
            return;
        }

        throw new RoleChangeBlocked(
            sprintf(
                'Role change affects %d project%s.',
                $projects->count(),
                $projects->count() === 1 ? '' : 's'
            ),
            'Assign a new Lead Technician before continuing.',
            $projects
        );
    }

    /**
     * A promotion may not hand somebody a project, and may not put a second
     * lead on one either.
     *
     * Both faults need the same thing done - open the project and settle its
     * team - so both list every live project the account is on. Only the
     * second sentence differs, because an administrator wants to know which
     * of the two they are looking at.
     */
    private function guardPromotion(User $user): void
    {
        $projects = $this->liveProjectsCrewedBy($user);

        if ($projects->isEmpty()) {
            return;
        }

        $wouldDouble = $projects->contains(fn (Project $project): bool => $project->hasLead());

        throw new RoleChangeBlocked(
            'Cannot update role.',
            $wouldDouble
                ? 'This change would create multiple Lead Technicians.'
                : 'This change would make them Lead Technician on assigned projects.',
            $projects
        );
    }
}
