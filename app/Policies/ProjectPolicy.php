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
     * Whether the Complete Project button is offered at all.
     *
     * Deliberately separate from complete(). This asks whether the project is
     * in a state a completion could ever be filed for - a lead's own, live,
     * running work - and it is what decides whether the button is drawn.
     * complete() then asks whether the work recorded on it is actually
     * finished, which is what decides whether pressing the button gets
     * anywhere.
     *
     * Splitting the two is the difference between a button that is missing
     * because the question does not arise (the project is Pending, Unscheduled
     * or paused: nobody has been on site yet, or has been told to stop) and a
     * dialog that explains what is still outstanding (tasks left open), which
     * is a thing the lead can go and deal with.
     */
    public function offersCompletion(User $user, Project $project): bool
    {
        return $user->isLeadTechnician()
            && $this->viewAssigned($user, $project)
            && $project->isCompletable();
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
     * Why this project cannot be marked complete yet, as sentences.
     *
     * The Super Admin portal shows these as a warning it may override, so it
     * wants the wording and nothing else. The technician portal wants the
     * links as well and reads blockerDetailsFor() instead.
     *
     * @return array<int, string>
     */
    public function blockersFor(Project $project): array
    {
        return array_column($this->blockerDetailsFor($project), 'message');
    }

    /**
     * Why this project cannot be marked complete yet, in the order a person
     * would check them, each with the place to go and deal with it. An empty
     * list means it can.
     *
     * A refusal that does not say where to go is a dead end: "2 tasks are
     * still open" leaves the lead to find those tasks themselves. Every
     * blocker a lead can actually act on therefore carries the link to the
     * screen that fixes it, and the two dialogs that close a project render
     * it as a link rather than reprinting the sentence.
     *
     * @return array<int, array{message: string, action: array{label: string, url: string}|null}>
     */
    public function blockerDetailsFor(Project $project): array
    {
        // Every link here points into the technician portal, because a lead is
        // the only person these are a refusal for. An administrator sees the
        // same sentences through blockersFor() as something they may override,
        // and never follows them anywhere.
        $projectUrl = route('technician.projects.show', $project->project_id);
        $tasksUrl = $projectUrl.'#tasks';

        if ($project->isReadOnly() || $project->isArchived()) {
            // Nothing to fix and nowhere to send anybody: the work is already
            // closed, and this is a statement of fact rather than a to-do.
            return [$this->blocker(
                sprintf('This project is already %s.', $project->statusLabel())
            )];
        }

        if ($project->on_hold) {
            return [$this->blocker(
                'This project is on hold. An administrator has to resume it before it can be completed.',
                'Open the project',
                $projectUrl
            )];
        }

        if (! in_array($project->status, Project::COMPLETABLE_STATUSES, true)) {
            return [$this->statusBlocker($project, $projectUrl)];
        }

        $blockers = [];

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
            $blockers[] = $this->blocker(
                $openTasks === 1
                    ? '1 task is still open. Every task has to be completed first.'
                    : $openTasks.' tasks are still open. Every task has to be completed first.',
                $openTasks === 1 ? 'Go to the open task' : 'Go to the open tasks',
                $tasksUrl
            );
        }

        if ($allTasks === 0) {
            $blockers[] = $this->blocker(
                'No tasks yet - there is nothing to close out.',
                'Add the first task',
                $tasksUrl
            );
        }

        // A date range still to come is deliberately not a blocker. Work
        // finishing ahead of schedule is a good outcome, not something to
        // argue with - completing the project releases those dates instead
        // (see ProjectCompletion).
        return $blockers;
    }

    /**
     * The refusal for a project whose status is not one work can be finished
     * from, worded for the status it actually is: Pending and Unscheduled are
     * two different reasons nobody has finished anything yet.
     *
     * @return array{message: string, action: array{label: string, url: string}|null}
     */
    private function statusBlocker(Project $project, string $projectUrl): array
    {
        $message = match ($project->status) {
            'unscheduled' => 'Not scheduled yet. An administrator has to schedule it first.',
            'pending' => 'Not started yet. It can be completed once its first scheduled day arrives.',
            default => sprintf('A %s project cannot be completed.', $project->statusLabel()),
        };

        return $this->blocker($message, 'Check the booked dates', $projectUrl);
    }

    /**
     * @return array{message: string, action: array{label: string, url: string}|null}
     */
    private function blocker(string $message, ?string $label = null, ?string $url = null): array
    {
        return [
            'message' => $message,
            'action' => $label !== null && $url !== null
                ? ['label' => $label, 'url' => $url]
                : null,
        ];
    }
}
