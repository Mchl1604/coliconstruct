<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * Putting a technician on a project's team, and taking them off it.
 *
 * A team member is recorded twice. Once against the project
 * (tbl_project_technicians), which is what every screen lists. And once
 * against each of the project's schedules (tbl_schedule_technicians), which is
 * the only thing TechnicianAvailabilityService reads - see its
 * busySchedulesQuery(), which joins through scheduleTechnicians and nothing
 * else.
 *
 * A member holding the first row but not the second is on the team and yet
 * reads as free for the very dates they are booked for, so they can be booked
 * onto a second project over the same days. The two rows therefore have to be
 * written together, always, and that is what this class is for: the wizard,
 * the assigned-team editor and the technician's own schedule page all change
 * teams, and none of them may have its own idea of what changing one means.
 *
 * Which of a project's schedules a joiner is booked onto is decided by the
 * same line the rest of the application draws at today. Joining a team is a
 * decision about the work still to come - it is screened that way, by
 * Schedule::upcomingAvailabilityRanges() - so it books the dates still to
 * come and no others. Writing a row against a week the project finished in
 * August would say the newcomer was on site for it, which is a claim about the
 * past that nobody made and that the record cannot support.
 *
 * Removal draws that same line from the other side, and neither row is ever
 * deleted for it. The membership is closed with a date - see ProjectTechnician,
 * where a row is a span rather than a fact - and only the bookings on ranges
 * still to come are released. This is the whole point of the class as it now
 * stands: the two tables answer two different questions, "who is booked and
 * therefore busy" and "who was here and therefore in the record", and a
 * removal is an answer to the first that must not be mistaken for an answer to
 * the second. It used to be: deleting the membership cascaded through
 * tbl_schedule_technicians and took every date the technician had ever been
 * booked for, so a project's July history disappeared because of an August
 * staffing decision.
 *
 * Extracted for the same reason TechnicianAvailabilityService settles "is this
 * technician free?" and ScheduleModeRules settles "what may a schedule say?".
 *
 * Nothing here opens a transaction: every caller already runs inside one, and
 * a team change is only ever part of a larger action.
 */
class ProjectTeam
{
    /**
     * Put a technician on the team, and on every date the project still has
     * ahead of it.
     *
     * Ranges that have already ended are deliberately skipped. Somebody added
     * today did not work last week, and a row saying they did is wrong twice
     * over: it puts them in the record of a job they were not on, and it makes
     * them read as booked on days they were in fact free - which is a real
     * answer given to anything that asks about those days, the technician's
     * own calendar included.
     *
     * It also has to be skipped for the picker and the save to mean anything.
     * Both now screen a joiner against the project's remaining ranges only, so
     * a technician can be accepted onto a project BECAUSE an old clash no
     * longer counts, and then be booked straight onto the very range that
     * clash sat in. The two halves have to draw the line in the same place.
     *
     * Safe to call for somebody who is already on the team: it adds whatever
     * is missing and leaves the rest alone. It never removes anything, so a
     * member who genuinely worked an earlier range keeps the row that says so.
     *
     * @param  int|null  $addedBy  the account making the addition, for the
     *                              audit trail. Null where no user is behind
     *                              it - a reopen restoring a team, a console
     *                              command.
     *
     * Somebody rejoining a project they were taken off reopens the membership
     * they already had rather than starting a second one. Their old span stays
     * as it is, closed, and the record reads the way it happened: on from
     * March to June, off, on again from today. A second row would say they
     * were on the project twice over from the beginning.
     */
    public function attach(Project $project, int $technicianId, ?int $addedBy = null): ProjectTechnician
    {
        // Written against the model rather than the scoped relation on
        // purpose: Project::projectTechnicians() only sees open memberships,
        // and this has to find the closed one to reopen it.
        $assignment = ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $technicianId)
            ->first();

        if ($assignment === null) {
            $assignment = ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technicianId,
                // The moment, not the day. Every date comparison on this
                // column goes through whereDate() or toDateString() - see
                // ProjectTechnician::coveredOn() - so the time costs nothing
                // there, and the team history is unreadable without it: two
                // changes on the same afternoon are otherwise the same
                // timestamp and sort arbitrarily.
                'joined_at' => Schedule::businessNow(),
                'joined_by' => $addedBy,
            ]);
        } elseif ($assignment->isRemoved()) {
            $assignment->update([
                'joined_at' => Schedule::businessNow(),
                'joined_by' => $addedBy,
                'removed_at' => null,
                'removed_by' => null,
            ]);
        }

        foreach ($this->unfinishedScheduleIds($project) as $scheduleId) {
            $this->link($scheduleId, (int) $assignment->project_technician_id);
        }

        return $assignment;
    }

    /**
     * Take a technician off the team, off the dates still ahead of it, and off
     * any unfinished task they were holding.
     *
     * Nothing is deleted. The membership is closed with a date, and the
     * bookings are released only where releasing them is a statement about the
     * future: this is the exact mirror of attach(), which refuses to write a
     * link onto a range that has already ended because somebody added today
     * did not work last week. The same asymmetry backwards - somebody removed
     * today DID work last week, and the row saying so is the only record of
     * it.
     *
     * That matters more than it sounds. tbl_schedule_technicians hangs off
     * this row by a cascading foreign key, so deleting the membership took
     * every date the technician had ever been booked for with it. A project's
     * July history vanished because of an August staffing decision.
     *
     * A range still running - started before today, ending after it - is
     * released rather than split. Half of it was worked and half was a promise
     * being withdrawn, and keeping the link would leave the technician reading
     * as booked for days they are now free for, which is a live wrong answer
     * rather than a gap in the record. The membership's removed_at is what
     * carries the fact that they were here for the first half.
     *
     * Completed work keeps its technician either way: it is a record of who
     * did it, not a statement about who is available now.
     *
     * @param  int|null  $removedBy  the account making the removal, for the
     *                               audit trail. Null where no user is behind
     *                               it - a console command, a cascade.
     * @return Collection<int, Task> the tasks left without a technician, so a
     *                               caller can say so rather than let the work
     *                               go quietly unowned
     */
    public function detach(Project $project, ProjectTechnician $assignment, ?int $removedBy = null): Collection
    {
        $this->releaseUnfinishedLinks($project, $assignment);

        $released = Task::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $assignment->technician_id)
            ->where('status', '!=', 'completed')
            ->get();

        Task::query()
            ->whereIn('task_id', $released->pluck('task_id'))
            ->update([
                'technician_id' => null,
                'status' => 'unassigned',
            ]);

        $assignment->update([
            'removed_at' => Schedule::businessNow(),
            'removed_by' => $removedBy,
        ]);

        return $released;
    }

    /**
     * Hand back the dates this member was holding that have not been worked,
     * and leave the ones that have.
     *
     * Read as rows and deleted by id rather than by a join, so the decision
     * about which ranges have ended is made by the same isLocked() the rest of
     * this class uses - see unfinishedScheduleIds() for why that cannot become
     * a WHERE clause.
     */
    private function releaseUnfinishedLinks(Project $project, ProjectTechnician $assignment): void
    {
        $keep = Schedule::query()
            ->where('project_id', $project->project_id)
            ->get(['schedule_id', 'start_datetime', 'end_datetime', 'scheduling_mode'])
            ->filter($this->hasEnded(...))
            ->map(fn (Schedule $schedule): int => (int) $schedule->schedule_id)
            ->values()
            ->all();

        ScheduleTechnician::query()
            ->where('project_technician_id', $assignment->project_technician_id)
            ->when($keep !== [], fn ($query) => $query->whereNotIn('schedule_id', $keep))
            ->delete();
    }

    /**
     * Book the whole of the project's current team onto a schedule that has
     * just been created.
     *
     * The mirror image of attach(): one arrives after the dates, the other
     * arrives after the team.
     *
     * This one books the range whatever its dates, and does NOT skip a range
     * that has already ended the way attach() does. The two are not the same
     * act. attach() is asked "should this newcomer be on that old week?", to
     * which the answer is no. This is asked "who is this range for?", and a
     * range only reaches here because somebody deliberately created it - a
     * Super Admin recording days already worked through the past-date
     * override, or a reopen restoring what a project held. Skipping it would
     * leave a range with nobody on it at all, which is not a record of
     * anything.
     */
    public function linkScheduleToTeam(Schedule $schedule, Project $project): void
    {
        foreach ($this->assignmentIds($project) as $projectTechnicianId) {
            $this->link((int) $schedule->schedule_id, $projectTechnicianId);
        }
    }

    /**
     * The schedule links this project should hold but does not - every
     * (schedule, team member) pair with no row between them, on the ranges
     * that have not ended.
     *
     * Reported rather than repaired, so the audit command and the repair that
     * follows it agree on what is missing without either one deciding it for
     * itself.
     *
     * Ended ranges are left out for the same reason attach() will not write
     * them, and the two have to match or they undo each other: the repair
     * command's promise is that every row it inserts is one attach() would
     * write today, so a rule attach() declines to apply cannot be one the
     * repair applies on its behalf. Left in, the audit would report every
     * skipped link as damage and the repair would put it straight back.
     *
     * What that costs is worth naming. A member who really did work an earlier
     * range, and lost their row to the bug this command exists to clean up,
     * will not have it restored - inserting it would be a guess at history
     * rather than a repair of it. The availability damage, which is what the
     * repair is actually for, is unaffected either way: every question the
     * application asks about who is free is a question about today or later,
     * so a row on a week that has ended could not have hidden a double
     * booking and restoring it cannot reveal one.
     *
     * Closed memberships are skipped outright, and that is not a nicety. This
     * walks the project's team against its unfinished ranges and inserts what
     * is missing; a technician who has been taken off the project is missing
     * every one of those links BECAUSE they were taken off, so a repair that
     * did not know the difference would quietly re-book everybody who had ever
     * been removed. The scoped relation is what keeps them out - see
     * Project::projectTechnicians().
     *
     * @return Collection<int, array{schedule_id: int, project_technician_id: int, technician_id: int}>
     */
    public function missingScheduleLinks(Project $project): Collection
    {
        $project->loadMissing(['schedules.scheduleTechnicians', 'projectTechnicians']);

        $missing = collect();

        foreach ($project->schedules->reject($this->hasEnded(...)) as $schedule) {
            $linked = $schedule->scheduleTechnicians
                ->map(fn (ScheduleTechnician $link): int => (int) $link->project_technician_id)
                ->all();

            foreach ($project->projectTechnicians as $assignment) {
                if (in_array((int) $assignment->project_technician_id, $linked, true)) {
                    continue;
                }

                $missing->push([
                    'schedule_id' => (int) $schedule->schedule_id,
                    'project_technician_id' => (int) $assignment->project_technician_id,
                    'technician_id' => (int) $assignment->technician_id,
                ]);
            }
        }

        return $missing;
    }

    private function link(int $scheduleId, int $projectTechnicianId): void
    {
        ScheduleTechnician::firstOrCreate([
            'schedule_id' => $scheduleId,
            'project_technician_id' => $projectTechnicianId,
        ]);
    }

    /**
     * The ranges a joiner is booked onto: the project's, minus the ones that
     * have finished.
     *
     * Read fresh rather than from a loaded relation: a caller that has just
     * created a schedule, or that is part-way through rebuilding a team, would
     * otherwise be working from a picture that is already out of date.
     *
     * Filtered in PHP rather than in the query on purpose. Whether a range has
     * ended is Schedule::isLocked()'s answer, which reads end_datetime through
     * endsOn() - and endsOn() falls back to start_datetime, because a row with
     * no end is a single day. A WHERE clause on end_datetime would quietly get
     * that one wrong, and there is no version of this worth a second
     * definition of "ended". A project holds a handful of ranges, so there is
     * nothing to gain by it either.
     *
     * @return Collection<int, int>
     */
    private function unfinishedScheduleIds(Project $project): Collection
    {
        return Schedule::query()
            ->where('project_id', $project->project_id)
            ->get(['schedule_id', 'start_datetime', 'end_datetime', 'scheduling_mode'])
            ->reject($this->hasEnded(...))
            ->map(fn (Schedule $schedule): int => (int) $schedule->schedule_id)
            ->values();
    }

    /**
     * Whether a range is over, in the one place this class decides it.
     */
    private function hasEnded(Schedule $schedule): bool
    {
        return $schedule->isLocked();
    }

    /**
     * @return Collection<int, int>
     */
    /**
     * The memberships a newly created range is booked onto: the team as it
     * stands, and only that.
     *
     * active() is not optional here. A membership row outlives the membership
     * now - it carries the dates that technician worked - so an unscoped read
     * of this table returns everybody who has ever been on the project, and
     * linkScheduleToTeam() would book every one of them onto a range created
     * after they left. The dates would then appear on their own calendar, and
     * they would read as busy for days they have no business being on.
     *
     * Read fresh rather than through Project::projectTechnicians(), which is
     * scoped the same way but may already be loaded and stale: a caller
     * part-way through rebuilding a team is exactly who calls this.
     */
    private function assignmentIds(Project $project): Collection
    {
        return ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->active()
            ->pluck('project_technician_id')
            ->map(fn ($projectTechnicianId): int => (int) $projectTechnicianId);
    }
}
