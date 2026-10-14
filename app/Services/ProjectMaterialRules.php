<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMaterialEntry;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * Who may do what to a project's materials.
 *
 * The same three roles that set a project's phases up - Super Admin, Admin,
 * and a Lead Technician on the project today - and the same two-sided use as
 * ProjectPhaseRules: the view asks before drawing a control, the controller
 * asks again before writing anything.
 */
class ProjectMaterialRules
{
    public function __construct(
        private readonly ProjectPhaseRules $phaseRules,
        private readonly ProjectPolicy $projects,
    ) {}

    /**
     * Whether the setup screen's material plan may be written.
     *
     * Only during a project's first setup. Once it has been finalized the plan
     * is the baseline everything since is measured against, so a Super Admin
     * who unlocks the phases later finds the plan read-only: changes from then
     * on are entries on the Materials tab.
     */
    public function canPlan(?User $user, Project $project): bool
    {
        return $project->awaitingInitialSetup()
            && $this->phaseRules->canSetUp($user, $project);
    }

    /**
     * Whether this account may record materials added, used or returned.
     *
     * Open once the project has been set up and while it is live. A hold does
     * not stop it - leftovers often go back when a job pauses. A project
     * waiting on its client, completed, cancelled or archived is a record.
     */
    public function canRecord(?User $user, Project $project): bool
    {
        return $project->hasCompletedInitialSetup()
            && ! $project->isReadOnly()
            && ! $project->isArchived()
            && $this->reach($user, $project);
    }

    /**
     * Whether this account may add a material to the master list from this
     * project - the case where what the job needs is not listed yet.
     */
    public function canAddToCatalog(?User $user, Project $project): bool
    {
        return $this->canPlan($user, $project) || $this->canRecord($user, $project);
    }

    /**
     * Whether this account may void an entry.
     *
     * An administrator may void any; a lead only what they recorded
     * themselves. The leftovers written at close-out are never voidable -
     * they are written once the project is a record, when nothing is.
     */
    public function canVoid(?User $user, Project $project, ProjectMaterialEntry $entry): bool
    {
        if ($entry->isVoided() || $entry->entry_type === ProjectMaterialEntry::TYPE_UNUSED) {
            return false;
        }

        if (! $this->canRecord($user, $project)) {
            return false;
        }

        return in_array($user?->role, User::ADMINISTRATOR_ROLES, true)
            || (int) $entry->recorded_by === (int) $user?->id;
    }

    /**
     * The office reads every project; a lead runs the ones they are on today.
     * A plain technician sees the Materials tab but records nothing.
     */
    private function reach(?User $user, Project $project): bool
    {
        if ($user === null || ! $user->canLogin()) {
            return false;
        }

        if (! in_array($user->role, ProjectPhaseRules::SETUP_ROLES, true)) {
            return false;
        }

        if ($user->isLeadTechnician()) {
            return $this->projects->worksOn($user, $project);
        }

        return true;
    }
}
