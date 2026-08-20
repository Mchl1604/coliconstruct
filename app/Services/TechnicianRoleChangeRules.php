<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

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
     * Throws RuntimeException, which ConfigurationController::failure() turns
     * into a 422 carrying the message - and which the Configuration page
     * already prints, so this needs nothing of the browser.
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

        throw new RuntimeException(sprintf(
            '%s is the lead technician on %s (%s). '
                .'Hand the lead over on %s before changing their role - open the project and '
                .'choose a new lead in Assigned Team, or replace them from the Technicians page. '
                .'A paused project can be handed over from the Technicians page without resuming it.',
            $user->fullName(),
            $this->countLabel($projects),
            $this->referenceList($projects),
            $projects->count() === 1 ? 'it' : 'each of them'
        ));
    }

    /**
     * A promotion may not hand somebody a project, and may not put a second
     * lead on one either.
     */
    private function guardPromotion(User $user): void
    {
        $projects = $this->liveProjectsCrewedBy($user);

        if ($projects->isEmpty()) {
            return;
        }

        // Named apart because they are two different problems with the same
        // fix, and an administrator reading this wants to know which they are
        // looking at.
        $wouldLead = $projects->reject(fn (Project $project): bool => $project->hasLead());
        $wouldDouble = $projects->filter(fn (Project $project): bool => $project->hasLead());

        $reasons = [];

        if ($wouldLead->isNotEmpty()) {
            $reasons[] = sprintf(
                'they would silently become the lead of %s, which %s no lead yet',
                $this->referenceList($wouldLead),
                $wouldLead->count() === 1 ? 'has' : 'have'
            );
        }

        if ($wouldDouble->isNotEmpty()) {
            $reasons[] = sprintf(
                '%s would end up with two leads, and a project can only have one',
                $this->referenceList($wouldDouble)
            );
        }

        throw new RuntimeException(sprintf(
            '%s is assigned to %s. Promoting them to Lead Technician cannot go ahead because %s. '
                .'Take them off those projects first, or make them the lead of one from Assigned Team, '
                .'which replaces the lead already there.',
            $user->fullName(),
            $this->countLabel($projects),
            implode(', and ', $reasons)
        ));
    }

    /**
     * "1 live project" / "3 live projects".
     *
     * @param  Collection<int, Project>  $projects
     */
    private function countLabel(Collection $projects): string
    {
        return $projects->count().' live project'.($projects->count() === 1 ? '' : 's');
    }

    /**
     * "PRJ-0001, PRJ-0002 and PRJ-0003" - what the administrator will go and
     * look for, so the reference rather than the name.
     *
     * @param  Collection<int, Project>  $projects
     */
    private function referenceList(Collection $projects): string
    {
        return $projects
            ->map(fn (Project $project): string => (string) ($project->reference_no ?: $project->name))
            ->join(', ', ' and ');
    }
}
