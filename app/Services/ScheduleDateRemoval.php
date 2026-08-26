<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Taking a single calendar date off a schedule.
 *
 * A schedule row is a solid block of booked time - that invariant is what lets
 * TechnicianAvailabilityService expand a row into days without asking anything
 * else, and what lets the calendar draw it as one bar. Removing a date from
 * the middle of a range would put a hole in that block, so instead the range
 * is expressed as the two solid blocks that remain:
 *
 *     Aug 10 - Aug 15, remove Aug 13
 *       ->  Aug 10 - Aug 12   (the original row, shortened)
 *           Aug 14 - Aug 15   (a new row, carrying the same booking)
 *
 * Nothing downstream has to learn a new idea: two rows with a gap between them
 * is a shape the system has always supported, and every reader - availability,
 * the calendars, the task date rules, the portal, the reports - already
 * handles it.
 *
 * The endpoints are simpler. Removing the first or last day of a range only
 * moves that end inwards, and a row covering a single date - which every
 * partial day does - has nothing left once its date goes, so it is deleted.
 *
 * This class decides the shape and nothing else. Whether the removal is
 * allowed, what it does to the project's status and tasks, and who is told
 * about it are the caller's business.
 */
class ScheduleDateRemoval
{
    /**
     * Take one date off one schedule.
     *
     * @return bool whether the row was removed outright, which is how a caller
     *              knows the project may now hold no dates at all
     *
     * @throws RuntimeException when the schedule does not cover the date
     */
    public function remove(Schedule $schedule, CarbonImmutable $date): bool
    {
        $day = $date->startOfDay();
        $start = $schedule->startsOn();
        $end = $schedule->endsOn();

        if ($day->lt($start) || $day->gt($end)) {
            throw new RuntimeException(sprintf(
                'The schedule for %s does not cover %s.',
                $schedule->describe(),
                $day->format(BusinessTime::DATE)
            ));
        }

        // Hours booked on one date, or a whole day booked on its own: there is
        // no part of either left once that date is taken away. The
        // schedule_technicians rows go with it, by cascade.
        if ($schedule->isPartialDay() || $schedule->spansSingleDay()) {
            $schedule->delete();

            return true;
        }

        if ($day->equalTo($start)) {
            $schedule->update(['start_datetime' => $start->addDay()->startOfDay()]);

            return false;
        }

        if ($day->equalTo($end)) {
            $schedule->update(['end_datetime' => $end->subDay()->endOfDay()]);

            return false;
        }

        $this->split($schedule, $day, $end);

        return false;
    }

    /**
     * Shorten the row to everything before the date, and put everything after
     * it into a row of its own.
     *
     * The new row is booked to exactly who the original was booked to, read
     * from the original rather than from the project's current team: those are
     * the same list in ordinary use, and where they differ the schedule is the
     * one telling the truth about who was booked for these days.
     */
    private function split(Schedule $schedule, CarbonImmutable $day, CarbonImmutable $end): void
    {
        $tail = Schedule::create([
            'project_id' => $schedule->project_id,
            'start_datetime' => $day->addDay()->startOfDay(),
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

        $schedule->update(['end_datetime' => $day->subDay()->endOfDay()]);
    }
}
