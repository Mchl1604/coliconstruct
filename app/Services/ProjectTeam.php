<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

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
     *                             audit trail. Null where no user is behind
     *                             it - a reopen restoring a team, a console
     *                             command.
     *
     * Somebody rejoining a project they were taken off starts a NEW span. The
     * closed one is left exactly as it is, so the record reads the way it
     * happened: on from March to June, off, on again from today.
     *
     * It used to reopen the old row instead, moving its joined_at up to today.
     * That wiped the first span out of history: every reader asks whether a
     * span covers a date, so March to June stopped belonging to anybody - the
     * Schedule page's day panel, the technician schedule report and the exports
     * all dropped the person from weeks they had genuinely worked, while the
     * booking links for those weeks sat there pointing at a span that no longer
     * reached them.
     * @param  CarbonImmutable|null  $from  the day the span opens - a later day
     *                                      schedules the start. Null, or today,
     *                                      opens it now.
     */
    public function attach(
        Project $project,
        int $technicianId,
        ?int $addedBy = null,
        ?CarbonImmutable $from = null
    ): ProjectTechnician {
        // A span that has not ended - current or still to come - counts as
        // already being on the team. An ended one is history and is never
        // touched here.
        $assignment = ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $technicianId)
            ->notEnded()
            ->orderBy('joined_at')
            ->first();

        if ($assignment === null) {
            $assignment = ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technicianId,
                'team_role' => $this->currentRoleOf($technicianId),
                'joined_at' => $this->moment($from),
                'joined_by' => $addedBy,
            ]);
        }

        $this->linkUnfinishedRanges($project, $assignment);

        return $assignment;
    }

    /**
     * Start a new span, whatever the technician already holds.
     *
     * For a caller that has already worked out that no span of theirs covers
     * the day - ProjectTeamChange, putting somebody back on from a date after a
     * removal that is still to come. attach() would find that leaving span and
     * return it; this opens the next one.
     */
    public function open(
        Project $project,
        int $technicianId,
        ?int $addedBy = null,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $until = null
    ): ProjectTechnician {
        // $until is the first day the span no longer covers: a stand-in lead
        // covering somebody's days off, or a technician's return that ends
        // where their original span was already due to end.
        $assignment = ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technicianId,
            'team_role' => $this->currentRoleOf($technicianId),
            'joined_at' => $this->moment($from),
            'joined_by' => $addedBy,
            'removed_at' => $until?->startOfDay(),
            'removed_by' => $until ? $addedBy : null,
            'removal_recorded_at' => $until ? Schedule::businessNow() : null,
        ]);

        $this->linkUnfinishedRanges($project, $assignment);

        return $assignment;
    }

    /**
     * Bring a span that has not started yet forward to an earlier day.
     *
     * Somebody due to join on Sep 1 who is now wanted from Aug 25 keeps the
     * one span, opened sooner - not a second span beside the first.
     */
    public function startEarlier(
        Project $project,
        ProjectTechnician $assignment,
        CarbonImmutable $from,
        ?int $by = null
    ): void {
        $assignment->update([
            'joined_at' => $this->moment($from),
            'joined_by' => $by,
        ]);

        $this->linkUnfinishedRanges($project, $assignment);
    }

    /**
     * End a span on a given day - today, or one still to come.
     *
     * $effective is the first day the technician is no longer on the team. A
     * span that would not have started by then records nothing - a start that
     * is being called off before it happens - so it is deleted along with its
     * bookings rather than left as an empty row in the history.
     *
     * Nothing else is deleted. Bookings on ranges that begin on or after the
     * removal are released, because none of their days belong to this span.
     * A range that began before the removal keeps its link: the days before
     * the removal were this technician's, and the span's end is what stops the
     * rest being theirs - see Project::crewOn() and the availability walk,
     * which both apply it.
     *
     * Tasks are not touched here. What happens to the work somebody was
     * holding is the administrator's decision - see ProjectTeamChange.
     */
    public function close(
        Project $project,
        ProjectTechnician $assignment,
        CarbonImmutable $effective,
        ?int $by = null
    ): void {
        $effectiveDate = $effective->toDateString();

        if ($assignment->startDate() !== null
            && $assignment->startDate() >= $effectiveDate
            && $assignment->isUpcoming()) {
            ScheduleTechnician::query()
                ->where('project_technician_id', $assignment->project_technician_id)
                ->delete();

            $assignment->delete();

            return;
        }

        $this->releaseLinksFrom($project, $assignment, $effectiveDate);

        $assignment->update([
            'removed_at' => $this->moment($effective),
            'removed_by' => $by,
            'removal_recorded_at' => Schedule::businessNow(),
        ]);
    }

    /**
     * Take back a removal that has not happened yet.
     *
     * The span runs on again as if the removal had never been scheduled, and
     * the bookings it released on the days after it are written back.
     */
    public function cancelRemoval(Project $project, ProjectTechnician $assignment, ?string $until = null): void
    {
        // $until is where the span now ends instead - the end of the return
        // span it is being folded back together with, when the removal was the
        // start of some days off. Null runs it on.
        $assignment->update([
            'removed_at' => $until === null ? null : CarbonImmutable::parse($until)->startOfDay(),
            'removed_by' => $until === null ? null : $assignment->removed_by,
            'removal_recorded_at' => $until === null ? null : $assignment->removal_recorded_at,
        ]);

        $this->linkUnfinishedRanges($project, $assignment);
    }

    /**
     * Call off a start that has not happened yet: the span recorded nothing,
     * so it goes, with its bookings.
     */
    public function cancelStart(ProjectTechnician $assignment): void
    {
        ScheduleTechnician::query()
            ->where('project_technician_id', $assignment->project_technician_id)
            ->delete();

        $assignment->delete();
    }

    /**
     * A day as the column stores it. Today is the moment, not midnight: two
     * changes on the same afternoon are otherwise the same timestamp and the
     * team history sorts them arbitrarily. Every date comparison on these
     * columns reads the date part alone - see ProjectTechnician::coveredOn().
     */
    private function moment(?CarbonImmutable $day): CarbonImmutable
    {
        $today = Schedule::businessToday();

        if ($day === null || $day->startOfDay()->lte($today)) {
            return Schedule::businessNow();
        }

        return $day->startOfDay();
    }

    /**
     * Make a membership cover days that have already been worked.
     *
     * The backwards twin of attach(), and deliberately not the same thing.
     * attach() answers "who is joining this project?" and opens a span at
     * today, because a newcomer did not work last week. This answers "who was
     * on site on those days?" - asked only by a Super Admin correcting the
     * record through the historical flow - and the answer is a claim about a
     * week that has gone, so the span has to reach back over it or the very
     * booking being recorded would sit outside the membership carrying it.
     *
     * Three shapes, and the difference between them matters:
     *
     *   no membership at all   a span is opened over exactly those days and
     *                          closed the day after them. Recording that
     *                          somebody worked in July is not a decision to put
     *                          them on the team today, and leaving the span
     *                          open would do exactly that - they would appear
     *                          on the crew, be screened for the project's
     *                          remaining dates, and be booked onto them.
     *   an open membership     joined_at is moved back to cover the days. They
     *                          are on the team and stay on it.
     *   a closed membership    joined_at is moved back the same way, and the
     *                          close is pushed past the days when it sat before
     *                          them. Somebody who left in June and is now
     *                          recorded as working in July was there in July.
     *
     * Nothing is booked here. Which schedule rows the crew is written against
     * is the correction's own decision - see HistoricalScheduleCorrection.
     *
     * A technician can hold several spans on one project - on, off, on again -
     * and those spans must never overlap, or the same person is on the crew
     * twice for one day. So the span widened is the nearest one that can be
     * widened without running into another; failing that, the days are given a
     * closed span of their own; and if even that would overlap, the correction
     * is refused rather than written as two claims on the same days.
     *
     * @param  CarbonImmutable  $from  the first day being recorded
     * @param  CarbonImmutable  $to  the last day being recorded
     * @param  int|null  $actorId  the Super Admin making the correction
     *
     * @throws RuntimeException when the days cannot be recorded without
     *                          overlapping a span the technician already holds
     */
    public function coverHistoricalWork(
        Project $project,
        int $technicianId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $actorId = null
    ): ProjectTechnician {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        $memberships = ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $technicianId)
            ->get();

        foreach ($this->nearestFirst($memberships, $from, $to) as $assignment) {
            $changes = [];

            if ($assignment->joined_at === null
                || CarbonImmutable::parse($assignment->joined_at)->gt($from)) {
                $changes['joined_at'] = $from;
                $changes['joined_by'] = $actorId;
            }

            if ($assignment->removed_at !== null
                && CarbonImmutable::parse($assignment->removed_at)->lte($to)) {
                $changes['removed_at'] = $to->addDay();
                $changes['removed_by'] = $actorId;
            }

            $widened = [
                $changes['joined_at'] ?? $assignment->joined_at,
                array_key_exists('removed_at', $changes) ? $changes['removed_at'] : $assignment->removed_at,
            ];

            if ($this->overlapsAny($widened, $memberships->reject(fn (ProjectTechnician $other): bool => $other->is($assignment)))) {
                continue;
            }

            if ($changes !== []) {
                $assignment->update($changes);
            }

            return $assignment;
        }

        // Exclusive, the way every membership close is - see
        // ProjectTechnician::coveredOn() - so the last recorded day is still
        // inside the span.
        $span = [$from, $to->addDay()];

        if ($this->overlapsAny($span, $memberships)) {
            throw new RuntimeException(
                'These dates overlap a period this technician already has on the project. Record them in smaller parts.'
            );
        }

        return ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technicianId,
            'team_role' => $this->currentRoleOf($technicianId),
            'joined_at' => $span[0],
            'joined_by' => $actorId,
            'removed_at' => $span[1],
            'removed_by' => $actorId,
        ]);
    }

    /**
     * A technician's spans, the ones closest to the recorded days first.
     *
     * A span that already touches or overlaps the days is distance zero; any
     * other is as far away as the gap between them. Widening the nearest one is
     * what a single-span technician has always had done to them, so nothing
     * changes for anybody who has only ever held one.
     *
     * @param  Collection<int, ProjectTechnician>  $memberships
     * @return Collection<int, ProjectTechnician>
     */
    private function nearestFirst(Collection $memberships, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $memberships
            ->sortBy(function (ProjectTechnician $membership) use ($from, $to): int {
                if ($membership->removed_at !== null && CarbonImmutable::parse($membership->removed_at)->startOfDay()->lt($from)) {
                    return (int) CarbonImmutable::parse($membership->removed_at)->startOfDay()->diffInDays($from);
                }

                if ($membership->joined_at !== null && CarbonImmutable::parse($membership->joined_at)->startOfDay()->gt($to->addDay())) {
                    return (int) $to->addDay()->diffInDays(CarbonImmutable::parse($membership->joined_at)->startOfDay());
                }

                return 0;
            })
            ->values();
    }

    /**
     * Whether a span, as [joined, removed), shares a day with any of the given
     * memberships. Null ends run forever in their direction; days are compared
     * rather than moments, exactly as ProjectTechnician::coveredOn() does.
     *
     * A span that opens and closes on the same day covers no day at all - the
     * technician added by mistake and taken straight back off - so it can
     * neither overlap anything nor be overlapped.
     *
     * @param  array{0: mixed, 1: mixed}  $span
     * @param  Collection<int, ProjectTechnician>  $memberships
     */
    private function overlapsAny(array $span, Collection $memberships): bool
    {
        $day = fn ($value): ?string => $value === null ? null : CarbonImmutable::parse($value)->toDateString();
        $isEmpty = fn (?string $start, ?string $end): bool => $start !== null && $end !== null && $start >= $end;

        [$start, $end] = [$day($span[0]), $day($span[1])];

        if ($isEmpty($start, $end)) {
            return false;
        }

        return $memberships->contains(function (ProjectTechnician $other) use ($day, $isEmpty, $start, $end): bool {
            $otherStart = $day($other->joined_at);
            $otherEnd = $day($other->removed_at);

            if ($isEmpty($otherStart, $otherEnd)) {
                return false;
            }

            $startsBeforeOtherEnds = $otherEnd === null || $start === null || $start < $otherEnd;
            $otherStartsBeforeEnd = $end === null || $otherStart === null || $otherStart < $end;

            return $startsBeforeOtherEnds && $otherStartsBeforeEnd;
        });
    }

    /**
     * The role a technician's account holds right now, as a membership records
     * it - or null for an account that no longer holds a technician role.
     */
    private function currentRoleOf(int $technicianId): ?string
    {
        $role = Technician::query()
            ->with('account:id,role')
            ->find($technicianId)
            ?->account
            ?->role;

        return in_array($role, User::TECHNICIAN_ROLES, true) ? $role : null;
    }

    /**
     * Release the bookings a span holds on ranges that begin on or after the
     * day it ends.
     *
     * The line is drawn at where each range STARTS. A range that began before
     * the removal is part record and part promise: its days before the removal
     * were this technician's, and the link is the only thing that says by whom
     * - deleting it would discard real history, which is what the cascade
     * delete used to do. The days from the removal on are released by the span
     * closing rather than by the link: removed_at bounds which of the range's
     * days belong to this person, and every reader that asks about a date -
     * Project::crewOn(), the availability walk - applies it.
     *
     * A range that begins on or after the removal holds none of this span's
     * days, so its link records nothing and is deleted outright. For a removal
     * effective today that is every range that has not started, which is the
     * line this class has always drawn.
     */
    private function releaseLinksFrom(Project $project, ProjectTechnician $assignment, string $effectiveDate): void
    {
        $release = Schedule::query()
            ->where('project_id', $project->project_id)
            ->get(['schedule_id', 'start_datetime', 'end_datetime', 'scheduling_mode'])
            ->filter(fn (Schedule $schedule): bool => $schedule->startsOn()->toDateString() >= $effectiveDate)
            ->map(fn (Schedule $schedule): int => (int) $schedule->schedule_id)
            ->values()
            ->all();

        if ($release === []) {
            return;
        }

        ScheduleTechnician::query()
            ->where('project_technician_id', $assignment->project_technician_id)
            ->whereIn('schedule_id', $release)
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
        foreach ($this->spansForNewRange($project, $schedule) as $assignment) {
            $this->link((int) $schedule->schedule_id, (int) $assignment->project_technician_id);
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
     * Project::rosterTechnicians().
     *
     * Within the spans that have not ended, a range is only missing a link from
     * a span that holds some of its days. A member leaving on Aug 21 is not
     * missing from a range that starts on Sep 1, and one starting on Sep 1 is
     * not missing from a range that finishes in August.
     *
     * @return Collection<int, array{schedule_id: int, project_technician_id: int, technician_id: int}>
     */
    public function missingScheduleLinks(Project $project): Collection
    {
        $project->loadMissing(['schedules.scheduleTechnicians', 'rosterTechnicians']);

        $missing = collect();

        foreach ($project->schedules->reject($this->hasEnded(...)) as $schedule) {
            $linked = $schedule->scheduleTechnicians
                ->map(fn (ScheduleTechnician $link): int => (int) $link->project_technician_id)
                ->all();

            foreach ($project->rosterTechnicians as $assignment) {
                if (in_array((int) $assignment->project_technician_id, $linked, true)
                    || ! $this->spanReachesRange($assignment, $schedule)) {
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

    /**
     * Write the links missingScheduleLinks() reports, and say how many were
     * actually new.
     *
     * The write half of that method, kept beside it so the two cannot disagree
     * about what a correct set of links is: whatever the audit reports as
     * missing is exactly what this inserts, and nothing else. The repair
     * command and Resume both go through here rather than each deciding for
     * itself.
     *
     * Resume is the reason this exists on the service rather than only in the
     * command. A held project keeps its ranges but stops occupying anybody, so
     * lifting the hold has to put the crew back onto the ranges coming with
     * it - and doing that by asking what is missing, rather than by assuming
     * nothing was, is what makes the step idempotent. A project whose links
     * are already complete comes back with none written and none duplicated.
     *
     * Ranges that have ended are left alone, and so is anybody whose
     * membership of the team is closed - both because missingScheduleLinks()
     * excludes them, and for the reasons set out there.
     *
     * @return int how many rows were inserted
     */
    public function restoreScheduleLinks(Project $project): int
    {
        // Read fresh: a caller that has just moved, merged or split a range -
        // which is exactly what Resume has done by this point - would
        // otherwise be measured against the relation as it was before.
        $project->unsetRelation('schedules');
        $project->unsetRelation('rosterTechnicians');

        $inserted = 0;

        foreach ($this->missingScheduleLinks($project) as $missing) {
            $row = ScheduleTechnician::firstOrCreate([
                'schedule_id' => $missing['schedule_id'],
                'project_technician_id' => $missing['project_technician_id'],
            ]);

            if ($row->wasRecentlyCreated) {
                $inserted++;
            }
        }

        if ($inserted > 0) {
            $project->unsetRelation('schedules');
        }

        return $inserted;
    }

    private function link(int $scheduleId, int $projectTechnicianId): void
    {
        ScheduleTechnician::firstOrCreate([
            'schedule_id' => $scheduleId,
            'project_technician_id' => $projectTechnicianId,
        ]);
    }

    /**
     * Whether a range is over, in the one place this class decides it.
     */
    private function hasEnded(Schedule $schedule): bool
    {
        return $schedule->isLocked();
    }

    /**
     * Book a span onto every unfinished range that holds some of its days.
     *
     * Ranges that have already ended are skipped - see attach() for why - and
     * so is any range that sits entirely outside the span: a member leaving on
     * Aug 21 is not booked onto September, and one starting on Sep 1 is not
     * booked onto August.
     */
    private function linkUnfinishedRanges(Project $project, ProjectTechnician $assignment): void
    {
        Schedule::query()
            ->where('project_id', $project->project_id)
            ->get(['schedule_id', 'start_datetime', 'end_datetime', 'scheduling_mode'])
            ->reject($this->hasEnded(...))
            ->filter(fn (Schedule $schedule): bool => $this->spanReachesRange($assignment, $schedule))
            ->each(fn (Schedule $schedule) => $this->link(
                (int) $schedule->schedule_id,
                (int) $assignment->project_technician_id
            ));
    }

    /**
     * Whether a span holds at least one of a range's days.
     */
    private function spanReachesRange(ProjectTechnician $assignment, Schedule $schedule): bool
    {
        return $assignment->overlaps(
            $schedule->startsOn()->toDateString(),
            $schedule->endsOn()->addDay()->toDateString()
        );
    }

    /**
     * The spans a newly created range is booked onto.
     *
     * Only spans that have not ended. A membership row outlives the membership
     * now - it carries the dates that technician worked - so an unscoped read
     * returns everybody who has ever been on the project, and every one of them
     * would be booked onto a range created after they left: the dates would
     * appear on their own calendar, and they would read as busy for days they
     * have no business being on.
     *
     * Of those, a span is skipped only when it plainly holds none of the
     * range's days - a removal taking effect before the range begins, or a
     * start still to come that falls after it ends. The team as it stands
     * today is always booked, whatever the range's dates: a range created in
     * the past reaches here only because a Super Admin is recording work
     * already done, or a reopen is restoring what a project held, and leaving
     * it with nobody on it would not be a record of anything.
     *
     * Read fresh rather than through a loaded relation: a caller part-way
     * through rebuilding a team is exactly who calls this.
     *
     * @return Collection<int, ProjectTechnician>
     */
    private function spansForNewRange(Project $project, Schedule $schedule): Collection
    {
        $rangeStart = $schedule->startsOn()->toDateString();
        $rangeEnd = $schedule->endsOn()->toDateString();

        return ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->notEnded()
            ->get()
            ->reject(fn (ProjectTechnician $assignment): bool => $assignment->isEmptySpan()
                || ($assignment->endDate() !== null && $assignment->endDate() <= $rangeStart)
                || ($assignment->isUpcoming() && $assignment->startDate() > $rangeEnd))
            ->values();
    }
}
