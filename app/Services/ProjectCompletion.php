<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What happens to a project's booked dates when it is completed.
 *
 * Work often finishes ahead of schedule. The dates still booked past the
 * completion date are then a lie: the project reads as ongoing on a calendar,
 * the technicians on it read as busy, and it can go on to look overdue for a
 * job that is already done. Completing the project releases them.
 */
class ProjectCompletion
{
    /**
     * Drop every scheduled day that falls after the completion date.
     *
     * A range wholly in the future is deleted. A range that was already
     * running is kept but cut short at the completion date, because it records
     * days the crew actually worked - deleting it would erase real history.
     * Its schedule_technicians rows go with any deleted range, by cascade.
     *
     * @return int how many ranges were removed entirely
     */
    public function releaseFutureSchedules(Project $project, ?CarbonInterface $completedOn = null): int
    {
        $cutoff = CarbonImmutable::parse($completedOn ?? CarbonImmutable::now())->endOfDay();

        $schedules = Schedule::query()
            ->where('project_id', $project->project_id)
            ->get();

        $removed = 0;

        foreach ($schedules as $schedule) {
            $start = CarbonImmutable::parse($schedule->start_datetime);
            $end = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime);

            if ($start->gt($cutoff)) {
                $schedule->delete();
                $removed++;

                continue;
            }

            if ($end->gt($cutoff)) {
                $schedule->update(['end_datetime' => $cutoff]);
            }
        }

        // The relation is stale now that rows have gone, and callers go on to
        // read it - isOverdue() consults every range.
        $project->unsetRelation('schedules');

        return $removed;
    }
}
