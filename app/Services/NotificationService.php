<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Project;
use App\Models\SpecialtyRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single way an event reaches somebody's bell.
 *
 * Modelled on ActivityLogger, and holding the same two guarantees: nothing
 * thrown here escapes, and inside a transaction the write is deferred until
 * after the commit - so a rolled-back action never leaves somebody notified
 * about work that did not happen.
 *
 * Where it differs is the audience. An audit entry is one row about an actor;
 * a notification is one row per person who needs to look at something, and
 * each of them may need a different link to the same project because the
 * portals are separate. Callers therefore name the event, not the recipients
 * or the wording - deliver() resolves both.
 */
class NotificationService
{
    /**
     * How long an identical notification suppresses a repeat.
     *
     * The same event firing twice in a row - a form double-submitted, a job
     * retried - should not stack up. A day later the same words genuinely mean
     * something new happened, so it is a window rather than a permanent rule.
     */
    private const DUPLICATE_WINDOW_HOURS = 24;

    // ------------------------------------------------------------------
    // Core delivery
    // ------------------------------------------------------------------

    /**
     * Send one notification to each recipient.
     *
     * @param  iterable<int, User>|User|null  $recipients
     * @param  (callable(User): ?string)|string|null  $url  A closure when the
     *                                                      destination depends on the reader's portal.
     */
    public function deliver(
        iterable|User|null $recipients,
        string $title,
        string $message,
        string $module,
        ?Model $reference = null,
        callable|string|null $url = null
    ): void {
        $rows = $this->rowsFor($recipients, $title, $message, $module, $reference, $url);

        if ($rows === []) {
            return;
        }

        DB::afterCommit(function () use ($rows): void {
            $this->write($rows);
        });
    }

    /**
     * @param  iterable<int, User>|User|null  $recipients
     * @param  (callable(User): ?string)|string|null  $url
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(
        iterable|User|null $recipients,
        string $title,
        string $message,
        string $module,
        ?Model $reference,
        callable|string|null $url
    ): array {
        $rows = [];
        $seen = [];

        foreach ($this->normalise($recipients) as $recipient) {
            // The same person can be reached twice by one event - a lead is
            // also a member of their own team - and should still be told once.
            if (isset($seen[$recipient->id])) {
                continue;
            }

            $seen[$recipient->id] = true;

            $rows[] = [
                'user_id' => $recipient->id,
                'title' => $title,
                'message' => $message,
                'module' => $module,
                'reference_type' => $reference ? class_basename($reference) : null,
                'reference_id' => $reference?->getKey(),
                'url' => is_callable($url) ? $url($recipient) : $url,
            ];
        }

        return $rows;
    }

    /**
     * @param  iterable<int, User>|User|null  $recipients
     * @return array<int, User>
     */
    private function normalise(iterable|User|null $recipients): array
    {
        if ($recipients === null) {
            return [];
        }

        if ($recipients instanceof User) {
            $recipients = [$recipients];
        }

        $people = [];

        foreach ($recipients as $recipient) {
            // A deactivated or archived account has nobody reading its bell.
            if ($recipient instanceof User && $recipient->canLogin()) {
                $people[] = $recipient;
            }
        }

        return $people;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function write(array $rows): void
    {
        try {
            foreach ($rows as $row) {
                if ($this->isDuplicate($row)) {
                    continue;
                }

                Notification::create($row);
            }
        } catch (Throwable $exception) {
            // The action itself already succeeded. Failing to tell somebody
            // about it is worth knowing, not worth failing the request over.
            Log::error('Could not write a notification.', [
                'title' => $rows[0]['title'] ?? null,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isDuplicate(array $row): bool
    {
        return Notification::query()
            ->where('user_id', $row['user_id'])
            ->where('title', $row['title'])
            ->where('message', $row['message'])
            ->where('reference_type', $row['reference_type'])
            ->where('reference_id', $row['reference_id'])
            ->where('created_at', '>=', now()->subHours(self::DUPLICATE_WINDOW_HOURS))
            ->exists();
    }

    // ------------------------------------------------------------------
    // Audiences
    // ------------------------------------------------------------------

    /**
     * Everybody who runs the system: admins and super admins.
     *
     * @return EloquentCollection<int, User>
     */
    public function administrators(): EloquentCollection
    {
        return User::query()
            ->loginable()
            ->whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN])
            ->get();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    public function superAdministrators(): EloquentCollection
    {
        return User::query()
            ->loginable()
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->get();
    }

    /**
     * The accounts behind a project's assigned technicians, leads included.
     *
     * @return Collection<int, User>
     */
    public function projectTeam(Project $project): Collection
    {
        $project->loadMissing('projectTechnicians.technician.account');

        return $project->projectTechnicians
            ->map(fn ($projectTechnician) => $projectTechnician->technician?->account)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * The project's lead, or null while it has none.
     */
    public function projectLead(Project $project): ?User
    {
        return $this->projectTeam($project)
            ->first(fn (User $user): bool => $user->role === User::ROLE_LEAD_TECHNICIAN);
    }

    /**
     * Client accounts for a project's contacts.
     *
     * Contacts and accounts are still matched on email address, exactly as the
     * public website does it - see ClientProjects.
     *
     * @return EloquentCollection<int, User>
     */
    public function projectClients(Project $project): EloquentCollection
    {
        $project->loadMissing('clients');

        $emails = $project->clients
            ->pluck('email_address')
            ->filter()
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            return User::query()->whereRaw('1 = 0')->get();
        }

        return User::query()
            ->loginable()
            ->where('role', User::ROLE_CLIENT)
            ->whereIn('email', $emails->all())
            ->get();
    }

    /**
     * The technician a task belongs to, if it has one.
     */
    public function taskOwner(Task $task): ?User
    {
        $task->loadMissing('technician.account');

        return $task->technician?->account;
    }

    // ------------------------------------------------------------------
    // Destinations
    //
    // The same project is a different page in each portal, so every link is
    // resolved against the reader rather than the writer.
    //
    // All of them are stored as paths rather than absolute URLs. The
    // scheduled reminder run writes notifications from the console, where
    // route() would build its host from APP_URL - a path is correct whatever
    // host the reader arrives on.
    // ------------------------------------------------------------------

    /**
     * @return callable(User): ?string
     */
    public function projectLink(Project $project): callable
    {
        return function (User $user) use ($project): ?string {
            return match (true) {
                $user->isEmployee() && ! in_array($user->role, User::TECHNICIAN_ROLES, true) => route('super-admin.projects.show', $project->project_id, false),
                in_array($user->role, User::TECHNICIAN_ROLES, true) => route('technician.projects.show', $project->project_id, false),
                // A client reads their projects on the public website.
                default => route('public.projects.show', $project->project_id, false),
            };
        };
    }

    /**
     * A task opens its own details modal on whichever tasks page the reader has.
     *
     * @return callable(User): ?string
     */
    public function taskLink(Task $task): callable
    {
        return function (User $user) use ($task): ?string {
            if (in_array($user->role, User::TECHNICIAN_ROLES, true)) {
                return route('technician.tasks', ['task' => $task->task_id], false);
            }

            if ($user->role === User::ROLE_CLIENT) {
                // A client does not see individual tasks, so the nearest real
                // page is the project the task belongs to.
                return $task->project_id
                    ? route('public.projects.show', $task->project_id, false)
                    : route('public.projects', [], false);
            }

            return route('super-admin.tasks.index', ['task' => $task->task_id], false);
        };
    }

    /**
     * @return callable(User): ?string
     */
    public function scheduleLink(Project $project): callable
    {
        return function (User $user) use ($project): ?string {
            if (in_array($user->role, User::TECHNICIAN_ROLES, true)) {
                return route('technician.schedule', [], false);
            }

            if ($user->role === User::ROLE_CLIENT) {
                // The project's own page carries its timeline.
                return route('public.projects.show', $project->project_id, false);
            }

            return route('super-admin.schedules.index', [], false);
        };
    }

    /**
     * An account lives in Configuration, which only administrators reach.
     */
    public function accountLink(): string
    {
        return route('super-admin.configuration.index', [], false);
    }

    /**
     * A short project label for message text: the reference number when there
     * is one, since that is what people quote to each other.
     */
    public function projectLabel(Project $project): string
    {
        return $project->reference_no ?: $project->name;
    }

    /**
     * The name to credit an action to when writing somebody else's message.
     */
    public function actorName(): string
    {
        return auth()->user()?->fullName() ?? 'The system';
    }

    /**
     * Everyone in a project's audience except the person who caused the event.
     *
     * Telling somebody about the thing they just did themselves is noise.
     *
     * @param  iterable<int, User>  $recipients
     * @return Collection<int, User>
     */
    /**
     * The administrators, including whoever is acting.
     *
     * Oversight notifications are a record of what the business did, so an
     * administrator stays on the list for their own actions - a Super Admin
     * who runs most of the work would otherwise have an empty bell while
     * everybody else received the news of it.
     *
     * Contrast excludingActor(), which is for messages addressed to a person
     * about themselves - "you have been assigned" reads as nonsense sent to
     * the person who did the assigning.
     *
     * @return EloquentCollection<int, User>
     */
    public function oversight(): EloquentCollection
    {
        return $this->administrators();
    }

    public function excludingActor(iterable $recipients): Collection
    {
        $actorId = auth()->id();

        return collect($recipients)
            ->filter(fn (User $user): bool => $actorId === null || $user->id !== $actorId)
            ->values();
    }

    /**
     * Clients get the plain project name: a reference number means nothing to
     * somebody outside the company.
     */
    public function clientProjectLabel(Project $project): string
    {
        return $project->name;
    }

    // ==================================================================
    // Events
    //
    // Callers name what happened; the wording, the audience and the link
    // are decided here. That is what keeps two screens from describing the
    // same event two different ways.
    // ==================================================================

    // ------------------------------------------------------------------
    // Projects
    // ------------------------------------------------------------------

    public function projectCreated(Project $project): void
    {
        $this->deliver(
            $this->oversight(),
            'New Project Created',
            sprintf('%s created %s.', $this->actorName(), $this->projectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );
    }

    public function projectUpdated(Project $project): void
    {
        $this->deliver(
            $this->oversight(),
            'Project Updated',
            sprintf('%s updated %s.', $this->actorName(), $this->projectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );
    }

    public function projectCompleted(Project $project): void
    {
        $this->deliver(
            $this->oversight()->merge($this->excludingActor($this->projectTeam($project))),
            'Project Completed',
            sprintf('%s has been marked complete.', $this->projectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );

        $this->deliver(
            $this->projectClients($project),
            'Your Project Is Complete',
            sprintf('%s has been completed. Thank you for your business.', $this->clientProjectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );
    }

    public function projectCancelled(Project $project): void
    {
        $this->deliver(
            $this->oversight()->merge($this->excludingActor($this->projectTeam($project))),
            'Project Cancelled',
            sprintf('%s has been cancelled.', $this->projectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );

        $this->deliver(
            $this->projectClients($project),
            'Project Cancelled',
            sprintf('%s has been cancelled.', $this->clientProjectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );
    }

    public function projectArchived(Project $project): void
    {
        $this->deliver(
            $this->oversight(),
            'Project Archived',
            sprintf('%s was archived by %s.', $this->projectLabel($project), $this->actorName()),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );
    }

    public function projectRestored(Project $project): void
    {
        $this->deliver(
            $this->oversight(),
            'Project Restored',
            sprintf('%s was restored from the archive by %s.', $this->projectLabel($project), $this->actorName()),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );
    }

    /**
     * @param  iterable<int, User>|null  $team  Read before the hold released
     *                                          it, when the caller has it.
     */
    public function projectPutOnHold(Project $project, ?iterable $team = null): void
    {
        $this->deliver(
            $this->oversight()->merge($this->excludingActor($team ?? $this->projectTeam($project))),
            'Project Put On Hold',
            sprintf('%s has been put on hold. Work is paused until it resumes.', $this->projectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );

        $this->deliver(
            $this->projectClients($project),
            'Project Put On Hold',
            sprintf('%s has been put on hold.', $this->clientProjectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );
    }

    public function projectResumed(Project $project): void
    {
        $this->deliver(
            $this->oversight()->merge($this->excludingActor($this->projectTeam($project))),
            'Project Resumed',
            sprintf('%s is active again.', $this->projectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );

        $this->deliver(
            $this->projectClients($project),
            'Project Resumed',
            sprintf('Work on %s has resumed.', $this->clientProjectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );
    }

    /**
     * @param  iterable<int, User>  $technicians
     */
    public function techniciansAssignedToProject(Project $project, iterable $technicians): void
    {
        $technicians = collect($technicians)->values();

        if ($technicians->isEmpty()) {
            return;
        }

        $this->deliver(
            $technicians,
            'New Project Assignment',
            sprintf('You have been assigned to %s by %s.', $this->projectLabel($project), $this->actorName()),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );

        // The lead is told who joined the project they run, unless they are
        // the one who did it.
        $lead = $this->projectLead($project);

        if ($lead && ! $technicians->contains(fn (User $user): bool => $user->id === $lead->id)) {
            $this->deliver(
                $this->excludingActor([$lead]),
                'Technician Joined Your Project',
                sprintf(
                    '%s joined %s.',
                    $technicians->map(fn (User $user): string => $user->fullName())->implode(', '),
                    $this->projectLabel($project)
                ),
                Notification::MODULE_PROJECTS,
                $project,
                $this->projectLink($project)
            );
        }
    }

    /**
     * @param  iterable<int, User>  $technicians
     */
    public function techniciansRemovedFromProject(Project $project, iterable $technicians): void
    {
        $technicians = collect($technicians)->values();

        if ($technicians->isEmpty()) {
            return;
        }

        $this->deliver(
            $technicians,
            'Removed From Project',
            sprintf('You have been removed from %s.', $this->projectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );

        $lead = $this->projectLead($project);

        if ($lead && ! $technicians->contains(fn (User $user): bool => $user->id === $lead->id)) {
            $this->deliver(
                $this->excludingActor([$lead]),
                'Technician Removed From Your Project',
                sprintf(
                    '%s left %s.',
                    $technicians->map(fn (User $user): string => $user->fullName())->implode(', '),
                    $this->projectLabel($project)
                ),
                Notification::MODULE_PROJECTS,
                $project,
                $this->projectLink($project)
            );
        }
    }

    public function leadAssignedToProject(Project $project, User $lead): void
    {
        $this->deliver(
            $this->excludingActor([$lead]),
            'Assigned As Lead Technician',
            sprintf('You are now the lead technician on %s.', $this->projectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );

        $this->deliver(
            $this->oversight(),
            'Lead Technician Assigned',
            sprintf('%s now leads %s.', $lead->fullName(), $this->projectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );

        $this->deliver(
            $this->projectClients($project),
            'Lead Technician Assigned',
            sprintf('%s will be leading the work on %s.', $lead->fullName(), $this->clientProjectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );
    }

    public function leadRemovedFromProject(Project $project, User $lead): void
    {
        $this->deliver(
            $this->excludingActor([$lead]),
            'Removed As Lead Technician',
            sprintf('You are no longer the lead technician on %s.', $this->projectLabel($project)),
            Notification::MODULE_PROJECTS,
            $project,
            $this->projectLink($project)
        );
    }

    // ------------------------------------------------------------------
    // Schedule
    // ------------------------------------------------------------------

    /**
     * @param  string  $ranges  The project's dates after the change.
     */
    public function projectScheduleChanged(Project $project, string $ranges): void
    {
        $this->deliver(
            $this->excludingActor($this->projectTeam($project)),
            'Project Schedule Changed',
            sprintf('The dates for %s are now %s.', $this->projectLabel($project), $ranges),
            Notification::MODULE_SCHEDULE,
            $project,
            $this->scheduleLink($project)
        );

        $this->deliver(
            $this->projectClients($project),
            'Project Rescheduled',
            sprintf('%s is now scheduled for %s.', $this->clientProjectLabel($project), $ranges),
            Notification::MODULE_SCHEDULE,
            $project,
            $this->projectLink($project)
        );
    }

    /**
     * Tasks that stopped fitting the schedule when a date was taken off it.
     *
     * Their dates are cleared rather than left pointing at a day the project
     * is no longer booked for, and clearing them quietly would mean the work
     * simply went missing from the board. The lead runs the task list and the
     * administrators own the schedule, so those are the people who can put a
     * date back on it.
     *
     * @param  iterable<int, Task>  $tasks
     */
    public function taskDatesCleared(Project $project, iterable $tasks): void
    {
        $tasks = collect($tasks)->values();

        if ($tasks->isEmpty()) {
            return;
        }

        $lead = $this->projectLead($project);

        $this->deliver(
            $this->oversight()->merge($lead ? [$lead] : []),
            'Task Dates Cleared',
            sprintf(
                '%s on %s no longer %s inside the schedule, so %s dates were cleared. %s',
                $tasks->count() === 1
                    ? sprintf('"%s"', $tasks->first()->task_title)
                    : sprintf('%d tasks', $tasks->count()),
                $this->projectLabel($project),
                $tasks->count() === 1 ? 'falls' : 'fall',
                $tasks->count() === 1 ? 'its' : 'their',
                $tasks->count() === 1
                    ? 'It needs new dates.'
                    : sprintf('They need new dates: %s.', $this->taskTitleList($tasks))
            ),
            Notification::MODULE_TASKS,
            $project,
            $this->projectLink($project)
        );
    }

    /**
     * Tasks left without a technician because the person holding them was
     * taken off the project's team.
     *
     * The same audience as taskDatesCleared(), for the same reason: the work
     * has not gone away, and somebody has to give it to whoever is picking it
     * up. Only unfinished work is ever released - what they already completed
     * stays theirs.
     *
     * @param  iterable<int, Task>  $tasks
     */
    public function tasksUnassignedByTeamChange(Project $project, string $technicianName, iterable $tasks): void
    {
        $tasks = collect($tasks)->values();

        if ($tasks->isEmpty()) {
            return;
        }

        $lead = $this->projectLead($project);

        $this->deliver(
            $this->oversight()->merge($lead ? [$lead] : []),
            'Tasks Left Unassigned',
            sprintf(
                '%s on %s %s unassigned because %s was removed from the project team. %s',
                $tasks->count() === 1
                    ? sprintf('"%s"', $tasks->first()->task_title)
                    : sprintf('%d tasks', $tasks->count()),
                $this->projectLabel($project),
                $tasks->count() === 1 ? 'is now' : 'are now',
                $technicianName,
                $tasks->count() === 1
                    ? 'It needs a technician.'
                    : sprintf('They need a technician: %s.', $this->taskTitleList($tasks))
            ),
            Notification::MODULE_TASKS,
            $project,
            $this->projectLink($project)
        );
    }

    /**
     * '"Fit the ducting", "Test the unit", and 3 more' - enough to recognise
     * the work without turning the message into a list.
     *
     * @param  Collection<int, Task>  $tasks
     */
    private function taskTitleList(Collection $tasks): string
    {
        $shown = $tasks->take(3)->map(fn (Task $task): string => sprintf('"%s"', $task->task_title));
        $remaining = $tasks->count() - $shown->count();

        if ($remaining > 0) {
            $shown->push(sprintf('%d more', $remaining));
        }

        return $shown->join(', ', ', and ');
    }

    public function projectScheduled(Project $project, string $ranges): void
    {
        $this->deliver(
            $this->excludingActor($this->projectTeam($project)),
            'Project Scheduled',
            sprintf('%s is scheduled for %s.', $this->projectLabel($project), $ranges),
            Notification::MODULE_SCHEDULE,
            $project,
            $this->scheduleLink($project)
        );

        $this->deliver(
            $this->projectClients($project),
            'Project Scheduled',
            sprintf('%s is scheduled for %s.', $this->clientProjectLabel($project), $ranges),
            Notification::MODULE_SCHEDULE,
            $project,
            $this->projectLink($project)
        );
    }

    // ------------------------------------------------------------------
    // Tasks
    // ------------------------------------------------------------------

    public function taskAssigned(Task $task): void
    {
        $owner = $this->taskOwner($task);
        $project = $task->project;

        $this->deliver(
            $this->excludingActor($owner ? [$owner] : []),
            'New Task Assignment',
            sprintf('You have been assigned "%s".', $task->task_title),
            Notification::MODULE_TASKS,
            $task,
            $this->taskLink($task)
        );

        if (! $project) {
            return;
        }

        $lead = $this->projectLead($project);

        if ($lead && $lead->id !== $owner?->id) {
            $this->deliver(
                $this->excludingActor([$lead]),
                'New Task On Your Project',
                sprintf('"%s" was created on %s.', $task->task_title, $this->projectLabel($project)),
                Notification::MODULE_TASKS,
                $task,
                $this->taskLink($task)
            );
        }
    }

    /**
     * @param  User|null  $previousOwner  Who had it before, when anyone did.
     */
    public function taskReassigned(Task $task, ?User $previousOwner): void
    {
        $owner = $this->taskOwner($task);

        $this->deliver(
            $this->excludingActor($owner ? [$owner] : []),
            'Task Assigned To You',
            sprintf('"%s" has been reassigned to you.', $task->task_title),
            Notification::MODULE_TASKS,
            $task,
            $this->taskLink($task)
        );

        if ($previousOwner && $previousOwner->id !== $owner?->id) {
            $this->deliver(
                $this->excludingActor([$previousOwner]),
                'Task Reassigned',
                sprintf('"%s" is no longer assigned to you.', $task->task_title),
                Notification::MODULE_TASKS,
                $task,
                $this->taskLink($task)
            );
        }
    }

    public function taskCompleted(Task $task, bool $withImages = false): void
    {
        $project = $task->project;
        $owner = $this->taskOwner($task);

        $participants = collect();

        if ($project && $lead = $this->projectLead($project)) {
            $participants->push($lead);
        }

        // Somebody else closing your task is exactly the case worth telling
        // you about.
        if ($owner) {
            $participants->push($owner);
        }

        $this->deliver(
            $this->oversight()->merge($this->excludingActor($participants)),
            'Task Completed',
            sprintf(
                '%s completed "%s".%s',
                $this->actorName(),
                $task->task_title,
                $withImages ? ' Completion photos were uploaded.' : ''
            ),
            Notification::MODULE_TASKS,
            $task,
            $this->taskLink($task)
        );
    }

    public function taskCancelled(Task $task): void
    {
        $owner = $this->taskOwner($task);

        $this->deliver(
            $this->oversight()->merge($this->excludingActor($owner ? [$owner] : [])),
            'Task Cancelled',
            sprintf('"%s" was removed by %s.', $task->task_title, $this->actorName()),
            Notification::MODULE_TASKS,
            $task,
            // The task is gone, so its own modal cannot be opened; the project
            // it belonged to is the nearest page that still exists.
            $task->project ? $this->projectLink($task->project) : null
        );
    }

    /**
     * Reminders are written by the scheduled command, so there is no actor to
     * exclude and no "you did this" wording.
     */
    public function taskDueTomorrow(Task $task): void
    {
        $this->remindAboutTask(
            $task,
            'Task Due Tomorrow',
            sprintf('Reminder: "%s" is due tomorrow.', $task->task_title)
        );
    }

    public function taskDueToday(Task $task): void
    {
        $this->remindAboutTask(
            $task,
            'Task Due Today',
            sprintf('Reminder: "%s" is due today.', $task->task_title)
        );
    }

    public function taskOverdue(Task $task): void
    {
        $this->remindAboutTask(
            $task,
            'Task Overdue',
            sprintf('"%s" passed its due date and is still open.', $task->task_title),
            includeAdministrators: true
        );
    }

    private function remindAboutTask(
        Task $task,
        string $title,
        string $message,
        bool $includeAdministrators = false
    ): void {
        $audience = collect();

        if ($owner = $this->taskOwner($task)) {
            $audience->push($owner);
        }

        if ($task->project && $lead = $this->projectLead($task->project)) {
            $audience->push($lead);
        }

        if ($includeAdministrators) {
            $audience = $audience->merge($this->administrators());
        }

        $this->deliver(
            $audience,
            $title,
            $message,
            Notification::MODULE_TASKS,
            $task,
            $this->taskLink($task)
        );
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    public function technicianReportFiled(Project $project, string $reportType): void
    {
        $lead = $this->projectLead($project);

        $this->deliver(
            $this->oversight()->merge($this->excludingActor($lead ? [$lead] : [])),
            'Technician Report Filed',
            // $reportType already reads "progress report" / "incident report",
            // so the sentence must not add the word a second time.
            sprintf('%s filed a %s on %s.', $this->actorName(), $reportType, $this->projectLabel($project)),
            Notification::MODULE_REPORTS,
            $project,
            $this->projectLink($project)
        );
    }

    public function reportExported(string $label): void
    {
        $this->deliver(
            $this->oversight(),
            'Report Exported',
            sprintf('%s exported the %s report.', $this->actorName(), $label),
            Notification::MODULE_REPORTS,
            null,
            route('super-admin.reports.index', [], false)
        );
    }

    // ------------------------------------------------------------------
    // User management
    // ------------------------------------------------------------------

    public function employeeAccountCreated(User $account): void
    {
        $this->deliver(
            $this->audienceForAccount($account),
            'New Employee Account',
            sprintf('%s created a %s account for %s.', $this->actorName(), $account->roleLabel(), $account->fullName()),
            Notification::MODULE_USER_MANAGEMENT,
            $account,
            $this->accountLink()
        );
    }

    public function clientAccountRegistered(User $account): void
    {
        $this->deliver(
            $this->administrators(),
            'New Client Registration',
            sprintf('%s registered a client account.', $account->fullName()),
            Notification::MODULE_USER_MANAGEMENT,
            $account,
            $this->accountLink()
        );
    }

    public function accountStatusChanged(User $account, bool $activated): void
    {
        $this->deliver(
            $this->audienceForAccount($account),
            $activated ? 'Account Activated' : 'Account Deactivated',
            sprintf(
                '%s %s the %s account for %s.',
                $this->actorName(),
                $activated ? 'activated' : 'deactivated',
                $account->roleLabel(),
                $account->fullName()
            ),
            Notification::MODULE_USER_MANAGEMENT,
            $account,
            $this->accountLink()
        );
    }

    public function accountArchived(User $account): void
    {
        $this->deliver(
            $this->audienceForAccount($account),
            'Account Archived',
            sprintf('%s archived the account for %s.', $this->actorName(), $account->fullName()),
            Notification::MODULE_USER_MANAGEMENT,
            $account,
            $this->accountLink()
        );
    }

    public function accountRestored(User $account): void
    {
        $this->deliver(
            $this->audienceForAccount($account),
            'Account Restored',
            sprintf(
                '%s restored the account for %s, which can sign in again.',
                $this->actorName(),
                $account->fullName()
            ),
            Notification::MODULE_USER_MANAGEMENT,
            $account,
            $this->accountLink()
        );
    }

    // ------------------------------------------------------------------
    // Specialty requests
    // ------------------------------------------------------------------

    /**
     * A technician has asked to change their specialties.
     *
     * Every administrator is told, because any of them can decide it, and the
     * link opens the queue rather than the technician's row - the reviewer
     * wants the request, not the account.
     *
     * The request is the reference, not the technician's account. That is what
     * makes a second request from the same person a second notification: the
     * duplicate guard matches on the reference and the wording, and with the
     * account as the reference every later request looked like a repeat of the
     * first and was silently dropped.
     */
    public function specialtyRequestSubmitted(User $technicianAccount, ?SpecialtyRequest $request = null): void
    {
        $this->deliver(
            $this->administrators(),
            'Specialty Request',
            sprintf(
                '%s requested changes to their specialties (%s) and is awaiting approval.',
                $technicianAccount->fullName(),
                $request?->changeSummary() ?? 'pending review'
            ),
            Notification::MODULE_USER_MANAGEMENT,
            $request ?? $technicianAccount,
            $this->specialtyQueueLink()
        );
    }

    public function specialtyRequestApproved(User $technicianAccount, ?SpecialtyRequest $request = null): void
    {
        $this->deliver(
            $technicianAccount,
            'Specialty Update Approved',
            $request
                ? sprintf('Your specialty update has been approved: %s.', $request->changeSummary())
                : 'Your specialty update has been approved.',
            Notification::MODULE_USER_MANAGEMENT,
            $request ?? $technicianAccount,
            route('profile.edit', [], false)
        );
    }

    public function specialtyRequestRejected(User $technicianAccount, ?SpecialtyRequest $request = null): void
    {
        $this->deliver(
            $technicianAccount,
            'Specialty Update Rejected',
            $request
                ? sprintf(
                    'Your specialty request (%s) has been rejected. Your current specialties are unchanged.',
                    $request->changeSummary()
                )
                : 'Your specialty update request has been rejected. Your current specialties are unchanged.',
            Notification::MODULE_USER_MANAGEMENT,
            $request ?? $technicianAccount,
            route('profile.edit', [], false)
        );
    }

    /**
     * The Technicians page, where whoever is waiting on a specialty decision
     * is highlighted in the table. There is no queue of its own: the decision
     * is taken in the technician's own details dialog.
     */
    public function specialtyQueueLink(): string
    {
        return route('super-admin.technicians.index', [], false);
    }

    /**
     * Somebody resetting an administrator's password is a security event, so
     * it goes to the super admins whoever the account belongs to.
     */
    public function accountPasswordReset(User $account): void
    {
        $recipients = in_array($account->role, User::ADMINISTRATOR_ROLES, true)
            ? $this->superAdministrators()
            : $this->administrators();

        $this->deliver(
            $recipients,
            'Password Reset',
            sprintf('%s reset the password for %s.', $this->actorName(), $account->fullName()),
            in_array($account->role, User::ADMINISTRATOR_ROLES, true)
                ? Notification::MODULE_SECURITY
                : Notification::MODULE_USER_MANAGEMENT,
            $account,
            $this->accountLink()
        );
    }

    /**
     * Anything touching an administrator account is a super admin's business;
     * everything else is ordinary operational news for both admin roles.
     *
     * @return EloquentCollection<int, User>
     */
    private function audienceForAccount(User $account): EloquentCollection
    {
        return in_array($account->role, User::ADMINISTRATOR_ROLES, true)
            ? $this->superAdministrators()
            : $this->administrators();
    }

    // ------------------------------------------------------------------
    // Security
    // ------------------------------------------------------------------

    public function repeatedFailedSignIns(string $email, int $attempts): void
    {
        $this->deliver(
            $this->superAdministrators(),
            'Repeated Failed Sign-Ins',
            sprintf('%d failed sign-in attempts for %s in the last hour.', $attempts, $email),
            Notification::MODULE_SECURITY,
            null,
            route('super-admin.configuration.index', [], false)
        );
    }
}
