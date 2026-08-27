<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use Carbon\CarbonImmutable;

/**
 * Dividing a project's bookings at the day it was put on hold.
 *
 * A hold used to take every date the project held, which threw away the record
 * of work that had already happened along with the promises that had not. The
 * two are not the same thing: days already worked are history and belong to
 * the project's record, while days still to come are what the project intends
 * to do next.
 *
 * So the hold date is the line. Every booking is measured against it on its
 * own, and the day of the hold itself is kept on the near side, because work
 * was done on it:
 *
 *     Aug 10 - Aug 12   ends before the line          kept as it is
 *     Aug 14 - Aug 16   ends on the line              kept as it is
 *     Aug 15 - Aug 18   crosses the line              split into Aug 15 - Aug 16
 *                                                     worked and Aug 17 - Aug 18
 *                                                     preserved
 *     Aug 20 - Aug 25   starts after the line         preserved as it is
 *
 * The days still to come are PRESERVED rather than deleted. They used to be
 * deleted outright, on the reasoning that a promise being withdrawn should not
 * keep sitting on the calendar - but that also threw away the only record of
 * what the project was going to do, so resuming meant rebuilding a schedule
 * from memory. Keeping them makes the hold what it says it is: a pause. They
 * are the project's PROPOSED schedule, and Resume is what asks whether the
 * calendar can still honour it - see ProjectScheduleRecovery.
 *
 * Preserving them does not hold anybody. The crew is released by the hold's
 * status change, not by deleting rows: a held project is Unscheduled, which is
 * not one of Project::ACTIVE_PROJECT_STATUSES, so
 * TechnicianAvailabilityService does not count its bookings at all and those
 * days read as free for other work from the moment the hold is placed. That is
 * also precisely why resuming has to be screened rather than assumed - see
 * ProjectController::resume().
 *
 * A shortened row is the same row with a nearer end, never a copy: the booking
 * that ran up to the hold is the booking that already existed, and the crew
 * listed on it stays listed on it. The tail put into a row of its own carries
 * exactly the same crew, read from the original row rather than from the
 * project's current team - those are the same list in ordinary use, and where
 * they differ the schedule is the one telling the truth about who was booked
 * for those days.
 *
 * Gaps between separate bookings are left as gaps. Nothing here merges rows.
 *
 * Partial days need no special case. Each occupies a single date, so it falls
 * wholly on one side of the line and its hours are never touched: a row can
 * only be split if it ends after a line it starts before, which a one-date row
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
     * @return array{kept: int, shortened: int, preserved: int} what became of
     *                                                          the bookings, for the caller's message and audit line
     */
    public function apply(Project $project, ?CarbonImmutable $onHoldDate = null): array
    {
        $holdDate = ($onHoldDate ?? Schedule::businessToday())->startOfDay();

        $summary = ['kept' => 0, 'shortened' => 0, 'preserved' => 0];

        // Read as rows rather than through the project's relation: the
        // relation may already be loaded, and a stale copy would be measured
        // against the line instead of what is actually stored.
        $schedules = Schedule::query()
            ->where('project_id', $project->project_id)
            ->orderBy('schedule_id')
            ->get();

        foreach ($schedules as $schedule) {
            foreach ($this->cut($schedule, $holdDate) as $outcome) {
                $summary[$outcome]++;
            }
        }

        // The project may have been handed to this with its bookings loaded;
        // whoever reads them next must see what is there now, not what was.
        $project->unsetRelation('schedules');
        $project->unsetRelation('schedule');

        return $summary;
    }

    /**
     * What becomes of one booking. A row that crosses the line becomes two, so
     * this reports a list rather than a single answer.
     *
     * @return array<int, string> any of 'kept', 'shortened', 'preserved'
     */
    private function cut(Schedule $schedule, CarbonImmutable $holdDate): array
    {
        $start = $schedule->startsOn();
        $end = $schedule->endsOn();

        // Ended before the hold, or on the day of it. Either way it is work
        // that happened, and it is left exactly as it stands - times, mode,
        // crew and all.
        if ($end->lessThanOrEqualTo($holdDate)) {
            return ['kept'];
        }

        // Every day of it is still to come, including one starting the day
        // after the hold. Left untouched as the project's proposal for when
        // the work resumes.
        if ($start->greaterThan($holdDate)) {
            return ['preserved'];
        }

        // Started on or before the hold and runs past it. The part that was
        // worked keeps the original row, up to and including the day of the
        // hold; the part still to come moves into a row of its own so it can
        // be preserved, screened and resolved as a range in its own right.
        $this->splitAt($schedule, $holdDate, $end);

        return ['shortened', 'preserved'];
    }

    /**
     * Shorten the row to the days already worked, and put the days still to
     * come into a row of their own carrying the same crew.
     */
    private function splitAt(Schedule $schedule, CarbonImmutable $holdDate, CarbonImmutable $end): void
    {
        $tail = Schedule::create([
            'project_id' => $schedule->project_id,
            'start_datetime' => $holdDate->addDay()->startOfDay(),
            'end_datetime' => $end->endOfDay(),
            'scheduling_mode' => $schedule->scheduling_mode,
            'status' => $schedule->status,
            'remarks' => $schedule->remarks,
        ]);

        $schedule->scheduleTechnicians()
            ->pluck('project_technician_id')
            ->each(function ($projectTechnicianId) use ($tail): void {
                ScheduleTechnician::create([
                    'schedule_id' => $tail->schedule_id,
                    'project_technician_id' => $projectTechnicianId,
                ]);
            });

        $schedule->update(['end_datetime' => $holdDate->endOfDay()]);
    }
}
