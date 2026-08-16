<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use Carbon\CarbonImmutable;

/**
 * Cutting a project's bookings off at the day it was put on hold.
 *
 * A hold used to take every date the project held, which threw away the record
 * of work that had already happened along with the promises that had not. The
 * two are not the same thing: days already worked are history and belong to
 * the project's record, while days still to come are promises the hold is
 * withdrawing - and the crew must read as free for other work on them.
 *
 * So the hold date is the line. Every booking is measured against it on its
 * own, and the day of the hold itself is kept, because work was done on it:
 *
 *     Aug 10 - Aug 12   ends before the line          kept as it is
 *     Aug 14 - Aug 16   ends on the line              kept as it is
 *     Aug 15 - Aug 18   crosses the line              shortened to Aug 15 - Aug 16
 *     Aug 20 - Aug 25   starts after the line         released
 *
 * A shortened row is the same row with a nearer end, never a copy: the booking
 * that ran up to the hold is the booking that already existed, and the crew
 * listed on it stays listed on it. A released row is deleted outright, and its
 * schedule_technicians rows go with it by cascade - which is what hands those
 * days back to the technicians who were holding them.
 *
 * Gaps between separate bookings are left as gaps. Nothing here merges rows,
 * and nothing creates one.
 *
 * Partial days need no special case. Each occupies a single date, so it is
 * kept whole or released whole and its hours are never touched: a row can only
 * be shortened if it ends after a line it starts before, which a one-date row
 * cannot do.
 */
class ScheduleHoldCutoff
{
    /**
     * Apply the cutoff to every booking the project holds.
     *
     * @param  CarbonImmutable|null  $onHoldDate  The day the hold is being
     *                                            placed; the office's today
     *                                            when not given. Never the
     *                                            project's start date, its
     *                                            schedule's end, or the day it
     *                                            was created.
     * @return array{kept: int, shortened: int, released: int} what became of
     *                                                         the bookings, for the caller's message and audit line
     */
    public function apply(Project $project, ?CarbonImmutable $onHoldDate = null): array
    {
        $holdDate = ($onHoldDate ?? Schedule::businessToday())->startOfDay();

        $summary = ['kept' => 0, 'shortened' => 0, 'released' => 0];

        // Read as rows rather than through the project's relation: the
        // relation may already be loaded, and a stale copy would be measured
        // against the line instead of what is actually stored.
        $schedules = Schedule::query()
            ->where('project_id', $project->project_id)
            ->orderBy('schedule_id')
            ->get();

        foreach ($schedules as $schedule) {
            $outcome = $this->cut($schedule, $holdDate);
            $summary[$outcome]++;
        }

        // The project may have been handed to this with its bookings loaded;
        // whoever reads them next must see what is left, not what was.
        $project->unsetRelation('schedules');
        $project->unsetRelation('schedule');

        return $summary;
    }

    /**
     * What becomes of one booking: 'kept', 'shortened' or 'released'.
     */
    private function cut(Schedule $schedule, CarbonImmutable $holdDate): string
    {
        $start = $schedule->startsOn();
        $end = $schedule->endsOn();

        // Ended before the hold, or on the day of it. Either way it is work
        // that happened, and it is left exactly as it stands - times, mode,
        // crew and all.
        if ($end->lessThanOrEqualTo($holdDate)) {
            return 'kept';
        }

        // Every day of it is still to come, including one starting the day
        // after the hold. The promise is withdrawn whole.
        if ($start->greaterThan($holdDate)) {
            $schedule->delete();

            return 'released';
        }

        // Started on or before the hold and runs past it: keep the part that
        // was worked, up to and including the day of the hold. A booking that
        // starts on the hold date is left holding that single day.
        $schedule->update(['end_datetime' => $holdDate->endOfDay()]);

        return 'shortened';
    }
}
