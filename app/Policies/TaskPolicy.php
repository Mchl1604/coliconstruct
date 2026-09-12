<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * What a technician may do with a single task.
 *
 * Completing is either yours to do or yours to oversee: the technician holding
 * the task, or the lead running the project it belongs to. Editing is about
 * rank alone - a lead manages the board for the projects they are on.
 */
class TaskPolicy
{
    public function __construct(private ProjectPolicy $projects) {}

    /**
     * Whether this account may read a single task at all.
     *
     * The counterpart to Task::scopeVisibleTo, for the places that are handed
     * one task rather than building a list: a plain technician reads their own
     * work, and a lead reads the board for a project they are on. The office
     * is not asked about here - nothing that serves an administrator consults
     * this policy - so an employee who carries no technician record falls to
     * the caller's own rule.
     *
     * The two questions are deliberately separate from complete(): a completed
     * task is still readable by whoever held it, long after there is nothing
     * left to do on it.
     */
    public function view(User $user, Task $task): bool
    {
        $project = $task->project;

        if ($project === null) {
            return false;
        }

        if (! $this->projects->viewAssigned($user, $project)) {
            return false;
        }

        // A lead sees the whole board for the project they run; a technician
        // sees the work that is theirs.
        return ! $user->isTechnician() || $task->isAssignedTo($user);
    }

    /**
     * A technician closes the work assigned to them; a lead may also close
     * anything on a project they run, for work that is finished on site but
     * was never marked.
     *
     * Never before the day the work was due to begin. A task ticked off ahead
     * of its start date is not a completion, it is a claim about work nobody
     * has been on site for - and the record would then carry a completion
     * instant days before the first scheduled day. The date is read off the
     * office clock and moves on its own, so a task refused this morning is
     * offered the moment its start date arrives, with nobody to change
     * anything.
     *
     * This policy is the technician portal's alone - see the class docblock -
     * so the rule reaches exactly the two roles it is meant for. An
     * administrator closing a task on the crew's behalf goes through
     * TaskController and is not asked, the same allowance they have on every
     * other completion rule.
     */
    public function complete(User $user, Task $task): bool
    {
        return $this->completableBy($user, $task) && ! $task->startsInFuture();
    }

    /**
     * Whether the start date is the only thing standing in the way.
     *
     * What the boards draw a disabled button from: a refusal that simply
     * removes the control leaves the technician to wonder where it went, so
     * the button stays, greyed, carrying Task::NOT_STARTED_REFUSAL. False
     * where the task was never this person's to close anyway, because there is
     * no button to grey out in that case.
     */
    public function blockedByStartDate(User $user, Task $task): bool
    {
        return $task->startsInFuture() && $this->completableBy($user, $task);
    }

    /**
     * Everything complete() asks except the start date: whose task it is, and
     * whether the project it sits on is still live.
     */
    private function completableBy(User $user, Task $task): bool
    {
        $project = $task->project;

        if ($project === null || ! $task->isOpen() || $task->status === 'unassigned') {
            return false;
        }

        if ($project->isReadOnly() || $project->isArchived()) {
            return false;
        }

        return $task->isAssignedTo($user)
            || $this->projects->manageTasks($user, $project);
    }

    /**
     * Whether closing this task obliges the closer to say what was done.
     *
     * The technician who did the work is asked for notes and a photo. Someone
     * closing it on their behalf has no first-hand account to give, so both
     * are optional and the completion panel says as much afterwards.
     */
    public function mustDescribeCompletion(User $user, Task $task): bool
    {
        return $task->isAssignedTo($user);
    }

    /**
     * Editing somebody else's task is a lead's call, and only on a project
     * they are actually on. Finished work is left alone.
     */
    public function update(User $user, Task $task): bool
    {
        $project = $task->project;

        return $project !== null
            && ! $task->isCompleted()
            && $this->projects->manageTasks($user, $project);
    }

    /**
     * Deleting is the same reach as editing: the lead running the project.
     *
     * Unlike editing, a completed task can go too - a task raised in error is
     * still an error after somebody ticks it off. The dialog says plainly that
     * the completion notes and photos go with it.
     */
    public function delete(User $user, Task $task): bool
    {
        $project = $task->project;

        return $project !== null
            && $this->projects->manageTasks($user, $project);
    }
}
