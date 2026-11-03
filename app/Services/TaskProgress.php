<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\TaskUpdateImage;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Support\BusinessTime;
use App\Support\UploadStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Daily progress on a task: what was done on it each day before the day it
 * is completed.
 *
 * Posted by the technician holding the task, or by the project's lead on
 * their behalf - never by the office, which reads it. One update per
 * technician per task per day: posting again the same day edits that day's
 * rather than adding another. The first update moves a Pending task to
 * Ongoing; completing it is still its own step.
 *
 * refusal() is the one rule, asked by the page before it draws Add Update and
 * by post() before it writes anything, so the button and the endpoint cannot
 * disagree.
 */
class TaskProgress
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly ProjectPolicy $projects,
        private readonly TaskScheduleRules $scheduleRules,
    ) {}

    /**
     * Why this account may not post today's update on this task, or null
     * when it may.
     */
    public function refusal(?User $user, Task $task): ?string
    {
        if ($user === null || ! $user->canLogin() || ! $user->needsTechnicianRecord()) {
            return 'Only the technician or the lead posts updates.';
        }

        $project = $task->project;

        if ($project === null || $project->isReadOnly() || $project->isArchived()) {
            return 'This project is closed.';
        }

        if ($project->on_hold) {
            return 'On hold. Resume it first.';
        }

        if (! $task->isOpen()) {
            return 'This task is closed.';
        }

        if ($task->technician_id === null || ! $task->start_date || ! $task->due_date) {
            return 'Assign the task first.';
        }

        $today = BusinessTime::today()->toDateString();

        if ($task->start_date > $today) {
            return 'This task has not started yet.';
        }

        // Your own task, or one on a project you are leading today.
        $ownsTask = (int) $user->technicianId() === (int) $task->technician_id;
        $leadsProject = $user->isLeadTechnician() && $this->projects->worksOn($user, $project);

        if (! $ownsTask && ! $leadsProject) {
            return 'Not your task.';
        }

        // The day's work has to be a day the holder is actually on the job.
        $holder = $task->technician?->account;

        if ($holder === null || ! $this->projects->worksOn($holder, $project)) {
            return 'Not on the team today.';
        }

        if (! $this->scheduleRules->isBookedDate($this->scheduleRules->ranges((int) $project->project_id), $today)) {
            return 'Not a work day for this project.';
        }

        return null;
    }

    public function canPost(?User $user, Task $task): bool
    {
        return $this->refusal($user, $task) === null;
    }

    /**
     * Today's update on this task, written or rewritten.
     *
     * @param  array<int, UploadedFile>  $images  added to any already on today's update
     *
     * @throws RuntimeException
     */
    public function post(Task $task, User $by, string $notes, array $images = []): TaskUpdate
    {
        $refusal = $this->refusal($by, $task);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        $notes = trim($notes);

        if ($notes === '') {
            throw new RuntimeException('Say what was done today.');
        }

        $update = DB::transaction(function () use ($task, $by, $notes, $images): TaskUpdate {
            $today = BusinessTime::today()->toDateString();

            // Matched on the calendar date alone: how a date column is stored
            // differs between databases, and a time part must not make today's
            // update look like a second one.
            $update = TaskUpdate::query()
                ->where('task_id', $task->task_id)
                ->where('technician_id', $task->technician_id)
                ->whereDate('update_date', $today)
                ->first()
                ?? new TaskUpdate([
                    'task_id' => $task->task_id,
                    'technician_id' => $task->technician_id,
                    'update_date' => $today,
                ]);

            $update->fill([
                'notes' => mb_substr($notes, 0, 2000),
                'posted_by' => $by->id,
                'posted_by_name' => $by->fullName(),
            ])->save();

            foreach ($images as $image) {
                TaskUpdateImage::create([
                    'task_update_id' => $update->task_update_id,
                    'image_path' => UploadStore::put($image, 'task_update_images'),
                ]);
            }

            // The first day of work is what makes a task Ongoing.
            if ($task->status === 'pending') {
                $task->update(['status' => 'ongoing']);
            }

            return $update;
        });

        $this->activityLogger->record(
            ActivityLog::TASK_PROGRESS_POSTED,
            null,
            sprintf("Posted today's progress on task '%s'.", $task->task_title),
            $task
        );

        return $update;
    }
}
