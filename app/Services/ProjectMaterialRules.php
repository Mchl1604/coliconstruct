<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMaterialEntry;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Support\RoleAccess;

/**
 * Who may do what to a project's materials.
 *
 *   Add, and void an addition: Super Admin, Admin Officer, Purchasing Admin.
 *   Record use:                Super Admin, Admin Officer, the project's lead.
 *   Record a return:           Super Admin, Admin Officer, Purchasing Admin.
 *   Request more:              the project's lead, who adds nothing themselves.
 *
 * An HR Admin reads the tab. The same two-sided use as ProjectPhaseRules: the
 * view asks before drawing a control, the controller asks again before writing
 * anything.
 */
class ProjectMaterialRules
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    /**
     * Everything this account may do here, for the Materials tab to draw.
     *
     * @return array{add: bool, use: bool, return: bool, request: bool}
     */
    public function abilities(?User $user, Project $project): array
    {
        return [
            'add' => $this->canManage($user, $project),
            'use' => $this->canRecordType($user, $project, ProjectMaterialEntry::TYPE_USED),
            'return' => $this->canRecordType($user, $project, ProjectMaterialEntry::TYPE_RETURNED),
            'request' => $this->canRequest($user, $project),
        ];
    }

    /**
     * Whether this account may add materials to this project, and take them
     * off again.
     */
    public function canManage(?User $user, Project $project): bool
    {
        return $this->isOpen($project)
            && $user?->canLogin() === true
            && RoleAccess::can($user, RoleAccess::MANAGE_PROJECT_MATERIALS);
    }

    /**
     * Whether this account may record this kind of entry. A lead records use on
     * a project they run today, and a Purchasing Admin records only what comes
     * back to the warehouse.
     */
    public function canRecordType(?User $user, Project $project, string $type): bool
    {
        if ($user === null || ! $this->isOpen($project) || ! $user->canLogin()) {
            return false;
        }

        if ($this->isLeadOn($user, $project)) {
            return $type === ProjectMaterialEntry::TYPE_USED;
        }

        if ($user->role === User::ROLE_PURCHASING_ADMIN) {
            return $type === ProjectMaterialEntry::TYPE_RETURNED;
        }

        return RoleAccess::can($user, RoleAccess::MANAGE_PROJECT_MATERIALS);
    }

    /**
     * Whether this account may ask for more: the lead running this project.
     */
    public function canRequest(?User $user, Project $project): bool
    {
        return $user !== null && $this->isOpen($project) && $this->isLeadOn($user, $project);
    }

    /**
     * Whether this account may add a material to the master list from this
     * project - the case where what the job needs is not listed yet.
     */
    public function canAddToCatalog(?User $user, Project $project): bool
    {
        return $this->canManage($user, $project);
    }

    /**
     * Whether this account may void an entry.
     *
     * Those who manage a project's materials may void any; a lead only what
     * they recorded themselves. The leftovers written at close-out are never
     * voidable - they are written once the project is a record, when nothing
     * is.
     */
    public function canVoid(?User $user, Project $project, ProjectMaterialEntry $entry): bool
    {
        if ($entry->isVoided() || $entry->entry_type === ProjectMaterialEntry::TYPE_UNUSED) {
            return false;
        }

        if ($this->canManage($user, $project)) {
            return true;
        }

        return $user !== null
            && $this->isOpen($project)
            && $this->isLeadOn($user, $project)
            && (int) $entry->recorded_by === (int) $user->id;
    }

    /**
     * Open while the project is live, set up or not: setup only settles phases
     * and tasks. A hold does not stop it - leftovers often go back when a job
     * pauses. A project waiting on its client, completed, cancelled or archived
     * is a record.
     */
    private function isOpen(Project $project): bool
    {
        return ! $project->isReadOnly()
            && ! $project->isArchived();
    }

    private function isLeadOn(User $user, Project $project): bool
    {
        return $user->canLogin() && $user->isLeadTechnician() && $this->projects->worksOn($user, $project);
    }
}
