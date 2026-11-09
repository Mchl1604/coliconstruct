<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ServiceReport;
use App\Support\BusinessTime;
use App\Support\RoleAccess;
use App\Support\ServiceReportForms;

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
     * The summary a project with no phase structure carries, named because
     * blockerDetailsFor() has to recognise its own blocker: that one refusal
     * stands alone, where every other one is listed alongside the rest.
     */
    private const NO_PHASES_SUMMARY = 'no phases set up';

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
     *
     * Somebody scheduled to join the team may look at the project before their
     * first day, so they can see what they are walking into. Everything a
     * technician can DO on a project waits for that day - see worksOn().
     */
    public function viewAssigned(User $user, Project $project): bool
    {
        return $this->membership($user, $project, onlyCurrent: false);
    }

    /**
     * Whether this account is on the project's team today - not merely due to
     * join it. Every power a lead holds on a project starts here, so a
     * replacement lead starting on the 21st runs the job from the 21st and the
     * lead they replace runs it until then.
     */
    public function worksOn(User $user, Project $project): bool
    {
        return $this->membership($user, $project, onlyCurrent: true);
    }

    private function membership(User $user, Project $project, bool $onlyCurrent): bool
    {
        $technicianId = $user->technicianId();

        if ($technicianId === null || ! $user->needsTechnicianRecord() || ! $user->canLogin()) {
            return false;
        }

        return ($onlyCurrent ? $project->projectTechnicians() : $project->rosterTechnicians())
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
            && $this->worksOn($user, $project)
            && ! $project->isReadOnly()
            && ! $project->isArchived()
            && ! $project->on_hold
            && $project->schedules()->exists();
    }

    /**
     * The Super Admin, or a lead on the project, ticks the unit checklists while
     * the work is live. Everyone else reads them.
     */
    public function updateChecklist(User $user, Project $project): bool
    {
        return ($user->isSuperAdmin() || ($user->isLeadTechnician() && $this->worksOn($user, $project)))
            && ! $project->isReadOnly()
            && ! $project->isArchived()
            && ! $project->awaitingInitialSetup();
    }

    /**
     * The Super Admin, or a lead on the project, edits the Service Report and
     * detail forms while the work is live. Everyone else reads them - the
     * Admin Officer included, who fills them only at creation and in the
     * Complete Project form.
     */
    public function updateServiceReport(User $user, Project $project): bool
    {
        return ($user->isSuperAdmin()
                || ($user->isLeadTechnician() && $this->worksOn($user, $project)))
            && ! $project->isReadOnly()
            && ! $project->isArchived();
    }

    /**
     * Filing a report is a lead's job or a Super Admin's, and only on live work.
     *
     * A hold is asked about separately because it does not change the stored
     * status: pausing a project leaves it Unscheduled, which is a reportable
     * status, so without this a paused project would still accept reports. A
     * report describes a visit, and nobody visits a project that is paused.
     */
    public function submitReport(User $user, Project $project): bool
    {
        return $this->reportsOn($user, $project)
            && $project->isScheduledOn(BusinessTime::today()->toDateString());
    }

    /**
     * Everything submitReport() asks except the date: whether this account
     * files this project's reports at all, on the days it is scheduled. A lead
     * files on the projects they run; a Super Admin on any.
     *
     * A report is dated the day it is filed and describes that day's visit, so
     * it is only taken on one of the project's scheduled days. Asked on its own
     * so a page can tell "not today" apart from "not yours", and say which.
     */
    public function reportsOn(User $user, Project $project): bool
    {
        return (RoleAccess::can($user, RoleAccess::FILE_REPORTS)
                || ($user->isLeadTechnician() && $this->worksOn($user, $project)))
            && $project->hasCompletedInitialSetup()
            && ! $project->isArchived()
            && ! $project->on_hold
            && in_array($project->status, self::REPORTABLE_STATUSES, true);
    }

    /**
     * Whether the Complete Project button is offered at all: the Super Admin,
     * the Admin Officer, or a lead on the project, on work in a state a
     * completion could be filed for.
     *
     * Deliberately separate from complete(). Pressing the button shows what is
     * still outstanding, which the person can go and deal with.
     */
    public function offersCompletion(User $user, Project $project): bool
    {
        return ($user->isSuperAdmin()
                || $user->role === User::ROLE_ADMIN_OFFICER
                || ($user->isLeadTechnician() && $this->worksOn($user, $project)))
            && $project->isCompletableBy($user);
    }

    /**
     * Completion photos go on ahead of completing, by whoever may complete the
     * project, at any point while it is live.
     */
    public function manageCompletionPhotos(User $user, Project $project): bool
    {
        return ($user->isSuperAdmin()
                || $user->role === User::ROLE_ADMIN_OFFICER
                || ($user->isLeadTechnician() && $this->worksOn($user, $project)))
            && ! $project->isReadOnly()
            && ! $project->isArchived();
    }

    /**
     * Whether this person may close the project out now. blockersFor() spells
     * out what stands in the way, and nobody may go past it.
     */
    public function complete(User $user, Project $project): bool
    {
        return $this->offersCompletion($user, $project)
            && $this->blockersFor($project, $user) === [];
    }

    /**
     * Why this project cannot be marked complete yet, as sentences.
     *
     * For wherever only the wording is wanted; blockerDetailsFor() has the links.
     *
     * @param  User|null  $viewer  Who is asking. Only the status blocker
     *                             depends on it - see blockerDetailsFor().
     * @return array<int, string>
     */
    public function blockersFor(Project $project, ?User $viewer = null): array
    {
        return array_column($this->blockerDetailsFor($project, $viewer), 'message');
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
     * Who is asking matters in three places. A Super Admin may close out a
     * project that has not reached its first scheduled day - see
     * Project::isCompletableBy(). A lead must have added a completion photo.
     * And the links go to the asker's own portal. Everything else reads the
     * work recorded on the project and refuses everybody alike.
     *
     * @param  User|null  $viewer  Who is asking. Omitted where the answer is
     *                             wanted for the project rather than for a
     *                             person, which is the stricter reading.
     * @return array<int, array{message: string, summary: string, action: array{label: string, url: string}|null}>
     */
    public function blockerDetailsFor(Project $project, ?User $viewer = null): array
    {
        $portal = $viewer?->isOfficeStaff() ? 'super-admin' : 'technician';
        $projectUrl = route($portal.'.projects.show', $project->project_id);
        $tasksUrl = $projectUrl.'#tasks';

        if ($project->isReadOnly() || $project->isArchived()) {
            // Nothing to fix and nowhere to send anybody: the work is already
            // closed, and this is a statement of fact rather than a to-do.
            return [$this->blocker(
                sprintf('This project is already %s.', $project->statusLabel()),
                sprintf('already %s', mb_strtolower($project->statusLabel()))
            )];
        }

        if ($project->on_hold) {
            return [$this->blocker(
                'This project is on hold. An administrator has to resume it before it can be completed.',
                'a hold',
                'Open the project',
                $projectUrl
            )];
        }

        // Read-only, archived and on-hold projects were turned away above, so
        // what is left for this to decide is the status alone.
        if (! $project->isCompletableBy($viewer)) {
            return [$this->statusBlocker($project, $projectUrl)];
        }

        // Phases first, because everything below them depends on there being
        // a structure: a project whose phases are not settled accepts no tasks
        // at all, so "No tasks yet" on one of those is a consequence rather
        // than a second thing to go and fix.
        $phaseBlockers = $this->phaseBlockers($project, $portal);

        if ($phaseBlockers !== [] && $phaseBlockers[0]['summary'] === self::NO_PHASES_SUMMARY) {
            return $phaseBlockers;
        }

        $blockers = $phaseBlockers;

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
                $openTasks === 1 ? '1 open task' : $openTasks.' open tasks',
                $openTasks === 1 ? 'Go to the open task' : 'Go to the open tasks',
                $tasksUrl
            );
        }

        if ($allTasks === 0) {
            $blockers[] = $this->blocker(
                'No tasks yet - there is nothing to close out.',
                'no tasks',
                'Add the first task',
                $tasksUrl
            );
        }

        $uncheckedUnits = collect(app(ServiceReport::class)->checklists($project->fresh()))
            ->flatMap(fn (array $checklist): array => $checklist['units'])
            ->filter(fn (array $unit): bool => count($unit['done']) < count(ServiceReportForms::checklistItems()))
            ->count();

        if ($uncheckedUnits > 0) {
            $blockers[] = $this->blocker(
                $uncheckedUnits === 1
                    ? '1 unit has an incomplete checklist.'
                    : $uncheckedUnits.' units have incomplete checklists.',
                $uncheckedUnits === 1 ? '1 incomplete checklist' : $uncheckedUnits.' incomplete checklists',
                'Go to the checklist',
                $projectUrl.'#checklist'
            );
        }

        // A lead is on site, so the photographs are the evidence.
        if (($viewer === null || $viewer->isLeadTechnician()) && ! $project->completionPhotos()->exists()) {
            $blockers[] = $this->blocker(
                'Add at least one completion photo.',
                'no completion photo',
                'Go to Completion Photos',
                $projectUrl.'#completion-photos'
            );
        }

        // A date range still to come is deliberately not a blocker. Work
        // finishing ahead of schedule is a good outcome, not something to
        // argue with - completing the project releases those dates instead
        // (see ProjectCompletion).
        return $blockers;
    }

    /**
     * Why this project's phases stand between a lead technician and closing
     * it, if they do.
     *
     * Three states, told apart deliberately, because collapsing them is the
     * bug this exists to prevent. "No phase is outstanding" is true of a
     * project with four finished phases AND of a project nobody has set any up
     * for, and only the first of those is finished work. So the structure is
     * asked about first, and an empty phase list is a refusal in its own right
     * rather than a silent pass.
     *
     * Read from the database on every call rather than from anything the page
     * had loaded. A phase closed in another tab, or reopened by a Super Admin
     * after this page was drawn, has to count: the whole point of the check is
     * that a stale browser cannot get past it.
     *
     * @return array<int, array{message: string, summary: string, action: array{label: string, url: string}|null}>
     */
    private function phaseBlockers(Project $project, string $portal): array
    {
        // One query for both figures. Only the two columns the answer is made
        // of, because nothing here reads a phase's title or its notes.
        $phases = $project->phases()->get(['phase_id', 'completed_at']);

        if (! $project->phasesAreFinalized() || $phases->isEmpty()) {
            return [$this->blocker(
                'Set up the project phases before completing the project.',
                self::NO_PHASES_SUMMARY,
                'Set up the phases',
                route($portal.'.projects.phases.setup', $project->project_id)
            )];
        }

        $outstanding = $phases->whereNull('completed_at')->count();

        if ($outstanding === 0) {
            return [];
        }

        return [$this->blocker(
            'Complete all project phases before completing the project.',
            $outstanding === 1 ? '1 incomplete phase' : $outstanding.' incomplete phases',
            $outstanding === 1 ? 'Go to the open phase' : 'Go to the open phases',
            route($portal.'.projects.show', $project->project_id).'#phases'
        )];
    }

    /**
     * The refusal for a project whose status is not one work can be finished
     * from, worded for the status it actually is: Pending and Unscheduled are
     * two different reasons nobody has finished anything yet.
     *
     * @return array{message: string, summary: string, action: array{label: string, url: string}|null}
     */
    private function statusBlocker(Project $project, string $projectUrl): array
    {
        [$message, $summary] = match ($project->status) {
            'unscheduled' => ['Not scheduled yet.', 'no schedule'],
            'pending' => ['Not started yet.', 'work not started'],
            default => [
                sprintf('A %s project cannot be completed.', $project->statusLabel()),
                sprintf('a %s project', mb_strtolower($project->statusLabel())),
            ],
        };

        return $this->blocker($message, $summary, 'Check the booked dates', $projectUrl);
    }

    /**
     * @param  string  $summary  The same objection in a few words - "1 open
     *                           task", "no tasks" - for a line with no room
     *                           for the whole sentence. A notification title
     *                           is the case that needs it: "Completed despite
     *                           1 open task by Ana Mendoza" says the whole
     *                           thing at a glance, where the message above is
     *                           written to be read in a dialog and to tell the
     *                           reader what to go and do about it.
     * @return array{message: string, summary: string, action: array{label: string, url: string}|null}
     */
    private function blocker(
        string $message,
        string $summary,
        ?string $label = null,
        ?string $url = null
    ): array {
        return [
            'message' => $message,
            'summary' => $summary,
            'action' => $label !== null && $url !== null
                ? ['label' => $label, 'url' => $url]
                : null,
        ];
    }
}
