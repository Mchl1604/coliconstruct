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
     * A technician closes the work assigned to them; a lead may also close
     * anything on a project they run, for work that is finished on site but
     * was never marked.
     */
    public function complete(User $user, Task $task): bool
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
