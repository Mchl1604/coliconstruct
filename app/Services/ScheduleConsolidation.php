<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use Illuminate\Support\Collection;

/**
 * Two bookings that run into each other are one booking.
 *
 * A project scheduled Aug 20-21 and again Aug 22-24 is not booked twice; it is
 * booked Aug 20-24. Stored as two rows it reads as two visits on every screen
 * that shows it - two bars on the calendar, two lines in the schedule panel,
 * two entries in every "booked ..." sentence - and describes a break between
 * the 21st and the 22nd that does not exist.
 *
 * So ranges are merged when they touch, at the point they are written. Doing
 * it in storage rather than in each view is what makes every reader inherit
 * it: the calendar, the task date pickers, the availability checker and the
 * client's own page all read rows, and none of them has to learn the rule.
 *
 * Continuous means the next range starts the day after the previous one ends.
 * A gap of even one day is a real gap and is left alone:
 *
 *   Aug 20-21, Aug 22-24   ->  Aug 20-24    (touching)
 *   Aug 20-22, Aug 23-25,
 *   Aug 28-30              ->  Aug 20-25, Aug 28-30
 *   Aug 20-22, Aug 24-26   ->  unchanged    (Aug 23 is a gap)
 *
 * Partial days never merge, with each other or with anything else. A partial
 * day books hours on one date and leaves the rest of it free; folding one into
 * a whole-day range would claim a day nobody booked, and folding two together
 * would claim the night between them.
 *
 * A range that has already ended is not exempt. Aug 18-20 and Aug 21-25 are one
 * booking whether or not the first half has been worked, so they are merged and
 * the surviving row's end moves - which is a write to a row the editor draws
 * locked. That is deliberate: the lock is about what a PERSON may retype, and
 * two touching bookings being one booking is a fact about the data that holds
 * either way. The merged row simply stops reading as ended, because it has not.
 */
class ScheduleConsolidation
{
    /**
     * Merge every continuous run of whole-day ranges on a project.
     *
     * The earliest row of each run survives and is stretched to cover the
     * whole of it; the rest are deleted. Keeping the earliest rather than
     * making a new row means the booking holds onto its own id, so anything
     * already pointing at it still does.
     *
     * Safe to call when there is nothing to do, which is most of the time.
     *
     * @return int how many rows were absorbed
     */
    public function consolidate(Project $project): int
    {
        $schedules = Schedule::query()
            ->where('project_id', $project->project_id)
            ->orderBy('start_datetime')
            ->orderBy('schedule_id')
            ->get();

        // Partial days are dropped here and never merge - not with each other,
        // and not with a whole-day range next to them. A partial day books
        // hours on one date; a whole-day range books every hour of every day
        // it covers. Joining the two would silently turn a morning into a
        // whole day, claiming time nobody booked and marking the crew busy for
        // it. Two partial days on consecutive dates are left apart for the
        // same reason: merging them would claim the night between.
        //
        // A whole-day pair separated by a partial day is not continuous
        // either - Aug 20-21, a partial Aug 22, then Aug 23-24 stays three
        // rows, because Aug 22 is only part booked.
        $wholeDays = $schedules->filter(fn (Schedule $schedule): bool => $schedule->isDateBased())->values();

        if ($wholeDays->count() < 2) {
            return 0;
        }

        $absorbed = 0;

        foreach ($this->runs($wholeDays) as $run) {
            $absorbed += $this->mergeRun($run);
        }

        if ($absorbed > 0) {
            // Rows have gone; callers go on to read the relation.
            $project->unsetRelation('schedules');
        }

        return $absorbed;
    }

    /**
     * Group the ranges into runs of touching ones.
     *
     * A run of one is left in the list and costs nothing - mergeRun() returns
     * immediately for it - which keeps this method about grouping and nothing
     * else.
     *
     * @param  Collection<int, Schedule>  $schedules  ordered by start date
     * @return array<int, array<int, Schedule>>
     */
    private function runs(Collection $schedules): array
    {
        $runs = [];
        $current = [$schedules->first()];

        foreach ($schedules->skip(1) as $schedule) {
            /** @var Schedule $previous */
            $previous = $current[count($current) - 1];

            if ($this->isContinuous($previous, $schedule)) {
                $current[] = $schedule;

                continue;
            }

            $runs[] = $current;
            $current = [$schedule];
        }

        $runs[] = $current;

        return $runs;
    }

    /**
     * Whether the second range carries straight on from the first.
     *
     * The `lte` rather than `equalTo` covers a range that starts inside the
     * one before it as well as one that starts the day after. Overlaps are
     * refused when a schedule is submitted, so in practice this is adjacency -
     * but a run that somehow does overlap is still one stretch of booked time,
     * and splitting hairs about it here would leave the worse shape stored.
     */
    private function isContinuous(Schedule $previous, Schedule $next): bool
    {
        return $next->startsOn()->lte($previous->endsOn()->addDay());
    }

    /**
     * Stretch the first row of a run over the whole of it and delete the rest.
     *
     * @param  array<int, Schedule>  $run
     * @return int how many rows were absorbed
     */
    private function mergeRun(array $run): int
    {
        if (count($run) < 2) {
            return 0;
        }

        $survivor = array_shift($run);

        // The furthest end in the run, not the last row's: a range wholly
        // inside another one would otherwise pull the end backwards.
        $end = collect($run)
            ->map(fn (Schedule $schedule) => $schedule->endsOn())
            ->push($survivor->endsOn())
            ->max();

        foreach ($run as $absorbed) {
            // The crew booked on the absorbed days keeps its booking, on the
            // row that now covers those days. Normally the same people are on
            // every row of a project, but a row written by a date removal
            // carries whoever was on the original - so the survivor takes the
            // union rather than assuming they match.
            $this->moveTechnicians($absorbed, $survivor);

            // schedule_technicians rows still pointing at this one go with it,
            // by cascade.
            $absorbed->delete();
        }

        $survivor->update(['end_datetime' => $end->endOfDay()]);

        return count($run);
    }

    /**
     * Give the survivor every technician the absorbed row was booking.
     */
    private function moveTechnicians(Schedule $absorbed, Schedule $survivor): void
    {
        $alreadyBooked = ScheduleTechnician::query()
            ->where('schedule_id', $survivor->schedule_id)
            ->pluck('project_technician_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        ScheduleTechnician::query()
            ->where('schedule_id', $absorbed->schedule_id)
            ->pluck('project_technician_id')
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => in_array($id, $alreadyBooked, true))
            ->each(fn (int $id) => ScheduleTechnician::create([
                'schedule_id' => $survivor->schedule_id,
                'project_technician_id' => $id,
            ]));
    }
}
