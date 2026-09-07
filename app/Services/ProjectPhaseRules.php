<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * Who may do what to a project's phase structure.
 *
 * A service rather than a policy because the question spans both portals and
 * every role in them. ProjectPolicy is deliberately technician-only - it says
 * so in its own docblock, and nothing that serves an administrator consults it
 * - so putting "may a Super Admin unlock this structure?" there would make it
 * something other than what it says it is.
 *
 * Every rule below is asked twice: once by the view, to decide whether to draw
 * a control, and again by the controller, before anything is written. The
 * point of stating them in one place is that those two answers cannot differ.
 */
class ProjectPhaseRules
{
    /**
     * The roles that may settle a project's phase structure in the first
     * place. A Lead Technician is included because they are the person who
     * knows what the stages of the job actually are - but only for a project
     * they are actually on, which reach() checks separately.
     *
     * @var array<int, string>
     */
    public const SETUP_ROLES = [
        User::ROLE_SUPER_ADMIN,
        User::ROLE_ADMIN,
        User::ROLE_LEAD_TECHNICIAN,
    ];

    public function __construct(private readonly ProjectPolicy $projects) {}

    /**
     * Whether this account may open the phase setup screen and type into it.
     *
     * Three things have to be true: the project's structure is still open, the
     * project is one work could still be done on, and the reader is one of the
     * three roles - on the project, if they are a technician.
     *
     * A read-only or archived project is refused outright. Setting up phases
     * for a project that is finished is not a job anybody has; more to the
     * point, the only thing phases are for is monitoring work still to come.
     */
    public function canSetUp(?User $user, Project $project): bool
    {
        return $project->needsPhaseSetup()
            && ! $project->isReadOnly()
            && ! $project->isArchived()
            && $this->reach($user, $project);
    }

    /**
     * Whether this account may lock the structure it has just typed.
     *
     * The same question as canSetUp() today, and stated separately anyway:
     * finalizing is the irreversible half of setup for everyone but a Super
     * Admin, and a rule that irreversible should be named at its call site
     * rather than borrowed from the one next to it.
     */
    public function canFinalize(?User $user, Project $project): bool
    {
        return $this->canSetUp($user, $project);
    }

    /**
     * Whether this account may unlock a finalized structure.
     *
     * The Super Admin alone, and only on a project whose structure is actually
     * locked. This is the single exception to the lock, and it is what the
     * whole feature rests on: if an Admin or a Lead could reach this, "4
     * phases" would stop meaning anything the moment somebody disagreed with
     * it.
     */
    public function canOverrideStructure(?User $user, Project $project): bool
    {
        return $user?->isSuperAdmin() === true
            && $project->phasesAreFinalized()
            && ! $project->isReadOnly()
            && ! $project->isArchived();
    }

    /**
     * Whether this account may close out the project's current phase.
     *
     * Wider than the structure rules on purpose: closing a phase is ordinary
     * running of the job, not a change to how it is monitored, so it is open
     * to everybody who runs the project. Whether the phase in front of them
     * can actually be closed is a different question, answered by
     * ProjectPhaseProgress::completionBlockers().
     */
    public function canCompletePhase(?User $user, Project $project): bool
    {
        return $project->phasesAreFinalized()
            && ! $project->isReadOnly()
            && ! $project->isArchived()
            && ! $project->on_hold
            && $this->reach($user, $project);
    }

    /**
     * Whether this account may close a phase that still has open tasks on it.
     *
     * The Super Admin's existing override power, applied to phases: the same
     * person who may sign off a whole project with work outstanding may sign
     * off one stage of it, and on the same terms - they have to say why.
     */
    public function canOverridePhaseCompletion(?User $user, Project $project): bool
    {
        return $user?->isSuperAdmin() === true
            && $this->canCompletePhase($user, $project);
    }

    /**
     * Whether this account can see the project at all, in a way that puts its
     * phases within their reach.
     *
     * The office reads every project. A technician reads the ones they are on,
     * and only a lead among them has any business shaping the structure - a
     * plain technician is refused here, which is what keeps the setup screen
     * and every phase action out of their portal.
     */
    private function reach(?User $user, Project $project): bool
    {
        if ($user === null || ! $user->canLogin()) {
            return false;
        }

        if (! in_array($user->role, self::SETUP_ROLES, true)) {
            return false;
        }

        if ($user->isLeadTechnician()) {
            return $this->projects->viewAssigned($user, $project);
        }

        return true;
    }
}
