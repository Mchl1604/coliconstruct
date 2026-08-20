<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * What a technician may do with a project they are assigned to.
 *
 * Everything the technician portal is allowed to touch is decided here, so no
 * controller action or Blade view has to re-derive it. Nothing in this policy
 * grants administrative rights: editing project information, changing the
 * team, rescheduling and archiving stay with the Super Admin portal, which
 * does not consult this policy at all.
 */
class ProjectPolicy
{
    /**
     * Statuses that may still receive a technician report. Mirrors
     * TechnicianReportController, which re-checks it on the way in.
     *
     * @var array<int, string>
     */
    private const REPORTABLE_STATUSES = ['unscheduled', 'pending', 'ongoing'];

    /**
     * A technician sees a project because they are on its team - never
     * because they typed its id into the address bar.
     *
     * An account that can no longer sign in is refused here as well as at the
     * door. Every route into this policy is behind `auth`, so a disabled
     * account should never reach it - but "should never" is the authentication
     * layer's promise to keep, and every power a technician has on a project
     * is granted through this one method. Asking here costs nothing and means
     * the answer does not depend on somebody else having got it right.
     */
    public function viewAssigned(User $user, Project $project): bool
    {
        $technicianId = $user->technicianId();

        if ($technicianId === null || ! $user->needsTechnicianRecord() || ! $user->canLogin()) {
            return false;
        }

        return $project->projectTechnicians()
            ->where('technician_id', $technicianId)
            ->exists();
    }

    /**
     * Creating and editing tasks is a lead's job, and only on live work: a
     * completed, cancelled, archived or on-hold project takes no new tasks,
     * and an unscheduled one has no dates to put them in.
     */
    public function manageTasks(User $user, Project $project): bool
    {
        return $user->isLeadTechnician()
            && $this->viewAssigned($user, $project)
            && ! $project->isReadOnly()
            && ! $project->isArchived()
            && ! $project->on_hold
            && $project->schedules()->exists();
    }

    /**
     * Filing a report is a lead's job, and only on live work.
     *
     * A hold is asked about separately because it does not change the stored
     * status: pausing a project leaves it Unscheduled, which is a reportable
     * status, so without this a paused project would still accept reports. A
     * report describes a visit, and nobody visits a project that is paused.
     */
    public function submitReport(User $user, Project $project): bool
    {
        return $user->isLeadTechnician()
            && $this->viewAssigned($user, $project)
            && ! $project->isArchived()
            && ! $project->on_hold
            && in_array($project->status, self::REPORTABLE_STATUSES, true);
    }

    /**
     * A lead may close out their own project once there is nothing left to
     * do on it. blockersFor() spells out what "nothing left" means and is
     * what the confirmation dialog shows when it isn't true yet.
     */
    public function complete(User $user, Project $project): bool
    {
        return $user->isLeadTechnician()
            && $this->viewAssigned($user, $project)
            && $this->blockersFor($project) === [];
    }

    /**
     * Why this project cannot be marked complete yet, in the order a person
     * would check them. An empty list means it can.
     *
     * @return array<int, string>
     */
    public function blockersFor(Project $project): array
    {
        $blockers = [];

        if ($project->isReadOnly() || $project->isArchived()) {
            return [sprintf('This project is already %s.', $project->statusLabel())];
        }

        if ($project->on_hold) {
            return ['This project is on hold. It has to be resumed before it can be completed.'];
        }

        if (! in_array($project->status, Project::ACTIVE_PROJECT_STATUSES, true)) {
            return [sprintf('A %s project cannot be completed.', $project->statusLabel())];
        }

        // Read from a count the caller loaded where there is one. The projects
        // listing asks this of every row at once, and two queries per project
        // on the busiest page in the portal is a cost worth not paying.
        $openTasks = $project->open_tasks_count !== null
            ? (int) $project->open_tasks_count
            : $project->tasks()->whereIn('status', Task::OPEN_STATUSES)->count();

        $allTasks = $project->tasks_count !== null
            ? (int) $project->tasks_count
            : $project->tasks()->count();

        if ($openTasks > 0) {
            $blockers[] = $openTasks === 1
                ? '1 task is still open. Every task has to be completed first.'
                : $openTasks.' tasks are still open. Every task has to be completed first.';
        }

        if ($allTasks === 0) {
            $blockers[] = 'This project has no tasks yet, so there is no completed work to close out.';
        }

        // A date range still to come is deliberately not a blocker. Work
        // finishing ahead of schedule is a good outcome, not something to
        // argue with - completing the project releases those dates instead
        // (see ProjectCompletion).
        return $blockers;
    }
}
