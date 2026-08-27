<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleCorrection;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Editing a schedule that has already run.
 *
 * A Super Admin may correct history - that is an administrative function, and
 * shutting it off would leave a wrong record wrong forever. What may not happen
 * is a day in the past quietly appearing on a project with nobody's name
 * against it: the schedule would then say the job was on site that day while
 * tbl_schedule_technicians says nobody was, and every report drawn from it
 * would be wrong in a way nothing on screen explains.
 *
 * So a submission is measured against what is stored, day by day, and sorted
 * into four cases rather than the one blunt "is this row old?" the validator
 * used to ask:
 *
 *   unchanged   the stored range is resubmitted as it stands. Nothing was
 *               newly promised, so nothing is asked. Its crew is left alone.
 *   extended    a stored range reaches further back than it did. Only the days
 *               it gained are new historical work.
 *   created     a range with no stored row behind it lands on days that have
 *               gone. All of those days are new historical work.
 *   moved       a stored range now occupies different days. The days it took
 *               up are new historical work; the days it gave up are not, and
 *               nobody is asked about them.
 *
 * "New" is measured against the PROJECT, not against the single row: a range
 * shortened at one end while another is lengthened to cover the same days has
 * added nothing, and asking who worked days the project already held would be
 * asking about work that is already on the record.
 *
 * Days given up are never asked about and never refused. They are recorded -
 * see ScheduleCorrection - because shortening a range that has run is still a
 * change to the record of what happened, and an audit has to be able to see it.
 */
class HistoricalScheduleCorrection
{
    /**
     * The same guard TechnicianAvailabilityService keeps: a typo'd year must
     * not spin the day-by-day walk for an unreasonable length of time.
     */
    private const MAX_DAYS_PER_RANGE = 3660;

    public function __construct(private readonly ProjectTeam $projectTeam) {}

    /**
     * What a submission does to the days this project has already worked.
     *
     * `$ranges` are resolved rows in the shape ScheduleModeRules hands back,
     * each carrying the `schedule_id` it belongs to, or null for a new one.
     *
     * `$survivingScheduleIds` are the stored rows the save will keep even
     * though the form did not mention them - the editor draws a row it may not
     * change read-only and submits nothing for it, and the save keeps it. Their
     * days are still held by the project, so they are not days given up.
     *
     * @param  Collection<int, array{schedule_id: ?int, mode: string, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     * @param  array<int, int>  $survivingScheduleIds
     * @return array{
     *     added: array<int, string>,
     *     removed: array<int, string>,
     *     ranges: array<int, array{added: array<int, string>, removed: array<int, string>}>,
     *     dropped: array<int, array{schedule: Schedule, removed: array<int, string>}>
     * }
     */
    public function assess(Project $project, Collection $ranges, array $survivingScheduleIds = []): array
    {
        $storedPast = [];
        $storedPastByRow = [];

        foreach ($project->schedules as $schedule) {
            $days = $this->pastDatesOfSchedule($schedule);
            $storedPastByRow[(int) $schedule->schedule_id] = $days;
            $storedPast = array_merge($storedPast, $days);
        }

        $storedPast = $this->unique($storedPast);

        $submittedPast = [];
        $submittedPastByRange = [];

        foreach ($ranges as $index => $range) {
            $days = $this->pastDatesOf($range);
            $submittedPastByRange[$index] = $days;
            $submittedPast = array_merge($submittedPast, $days);
        }

        // A row nobody submitted but that the save will keep still holds its
        // days, so they count as held on both sides of the comparison.
        foreach ($survivingScheduleIds as $scheduleId) {
            $submittedPast = array_merge($submittedPast, $storedPastByRow[(int) $scheduleId] ?? []);
        }

        $submittedPast = $this->unique($submittedPast);

        $added = $this->unique(array_diff($submittedPast, $storedPast));
        $removed = $this->unique(array_diff($storedPast, $submittedPast));

        $perRange = [];

        foreach ($submittedPastByRange as $index => $days) {
            $storedForRow = $storedPastByRow[(int) ($ranges[$index]['schedule_id'] ?? 0)] ?? [];

            $perRange[$index] = [
                // Each added day belongs to exactly one submitted row - the
                // rows of one project may not overlap - so intersecting the
                // project-wide answer with this row's days splits it cleanly.
                'added' => $this->unique(array_intersect($days, $added)),
                'removed' => $this->unique(array_intersect($storedForRow, $removed)),
            ];
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'ranges' => $perRange,
            'dropped' => $this->droppedRows($project, $ranges, $survivingScheduleIds, $storedPastByRow, $removed),
        ];
    }

    /**
     * The days a resolved range occupies that have already gone.
     *
     * Today is not one of them. A booking made for today is a booking for work
     * happening now, which is a promise like any other - the same line
     * Schedule::lockState() draws.
     *
     * @param  array{mode?: string, start: CarbonImmutable, end: CarbonImmutable}  $range
     * @return array<int, string>
     */
    public function pastDatesOf(array $range): array
    {
        $today = Schedule::businessToday();
        $start = $range['start']->startOfDay();

        // A partial day books hours on one date and never spans two, whatever
        // its end says.
        $end = ($range['mode'] ?? Schedule::MODE_DATE_BASED) === Schedule::MODE_PARTIAL_DAY
            ? $start
            : $range['end']->startOfDay();

        $dates = [];
        $day = $start;

        for ($guard = 0; $guard <= self::MAX_DAYS_PER_RANGE && $day->lte($end); $guard++) {
            if ($day->lt($today)) {
                $dates[] = $day->toDateString();
            }

            $day = $day->addDay();
        }

        return $dates;
    }

    /**
     * @return array<int, string>
     */
    public function pastDatesOfSchedule(Schedule $schedule): array
    {
        return $this->pastDatesOf($schedule->toAvailabilityRange());
    }

    /**
     * Who may be named for the given past dates, split into the people the
     * record already supports and everybody else.
     *
     * A member counts when their membership span covers EVERY one of the dates
     * being attributed. Somebody who joined half way through them was not on
     * the project for the rest, and naming them for the lot would put them in a
     * week they were not in - so they are offered on the other list, where
     * choosing them is a deliberate act that widens their membership to match.
     *
     * A member is SUGGESTED when they are also on the project as it stands
     * today, which the editor fills in for them. Most corrections are the crew
     * that is on the job now recording days they worked and nobody booked, so
     * the common answer should not have to be typed - and a suggestion is only
     * ever that: the chips can be taken off before anything is recorded.
     *
     * Somebody who covers the dates but has since LEFT is listed and not
     * suggested. They may well have worked those days - that is why they are
     * offered at all - but filling their name in would be the editor asserting
     * something about a person who has been taken off the project, which is
     * exactly the kind of quiet claim this step exists to prevent.
     *
     * @param  array<int, string>  $dates  ascending
     * @return array{members: array<int, array<string, mixed>>, others: array<int, array<string, mixed>>}
     */
    public function candidates(Project $project, array $dates): array
    {
        $project->loadMissing('teamHistory.technician.account');

        $members = [];
        $memberIds = [];

        foreach ($project->teamHistory as $assignment) {
            $technician = $assignment->technician;

            if (! $technician || ! $this->coversAll($assignment, $dates)) {
                continue;
            }

            $technicianId = (int) $technician->technician_id;

            if (in_array($technicianId, $memberIds, true)) {
                continue;
            }

            $memberIds[] = $technicianId;
            $members[] = $this->candidatePayload(
                $technician,
                $assignment->isRemoved()
                    ? 'On the team then, removed '.BusinessTime::format($assignment->removed_at)
                    : 'On the team for these dates',
                ! $assignment->isRemoved()
            );
        }

        usort($members, fn (array $first, array $second): int => strcmp(
            mb_strtolower($first['name']),
            mb_strtolower($second['name'])
        ));

        $others = Technician::query()
            ->assignable()
            ->with('account:id,user_code,name,first_name,middle_name,last_name,email,role,profile_photo_path,status,is_archived')
            ->get()
            ->reject(fn (Technician $technician): bool => in_array((int) $technician->technician_id, $memberIds, true))
            ->map(fn (Technician $technician): array => $this->candidatePayload(
                $technician,
                $this->whyNotAMember($project, (int) $technician->technician_id, $dates)
            ))
            ->sortBy(fn (array $candidate): string => mb_strtolower($candidate['name']))
            ->values()
            ->all();

        return ['members' => $members, 'others' => $others];
    }

    /**
     * One name as the search box reads it.
     *
     * The role travels with it because the crew on a day is not a flat list:
     * one of them led it, and the correction is refused without exactly one
     * Lead Technician - see assertOneLead(). The code and the address travel
     * because those are the other two things somebody knows an account by, and
     * the search matches on all three the way the activity-log export's does.
     *
     * `suggested` is what the editor fills in before anybody types.
     *
     * @return array<string, mixed>
     */
    private function candidatePayload(Technician $technician, string $note, bool $suggested = false): array
    {
        return [
            'id' => (int) $technician->technician_id,
            'name' => $technician->name,
            'code' => $technician->account?->user_code,
            'email' => $technician->account?->email,
            'role' => $technician->account?->role,
            'role_label' => $technician->account?->roleLabel(),
            'is_lead' => $technician->isLead(),
            'suggested' => $suggested,
            'avatar_url' => $technician->account?->avatarUrl(),
            'note' => $note,
        ];
    }

    /**
     * What the record already says about the people being named for days that
     * have gone.
     *
     * This is step three of the correction: the days are known, the Super
     * Admin has said who worked them, and before any of it is written the
     * system asks whether those same people are already down as being
     * somewhere else on those same days.
     *
     * It is a QUESTION, not a refusal. A clash in the future is a booking that
     * cannot be made; a clash in the past is two records that disagree, and
     * the whole point of this screen is that a Super Admin is the one who gets
     * to say which is right. So a clash is reported, shown, and confirmed -
     * never silently rejected. See ScheduleController::update(), which refuses
     * only an UNCONFIRMED clash.
     *
     * Each technician is asked about separately, because the answer differs
     * per person: naming three people for a day where one of them is already
     * booked elsewhere must flag that one and leave the other two alone.
     *
     * Partial-day hours are honoured because the requests handed to the
     * availability service carry the mode and times of the range that produced
     * each day - so an afternoon correction against somebody's booked morning
     * is not a clash, and against their booked afternoon it is. That is the
     * existing overlap rule, not a second one: see
     * TechnicianAvailabilityService::historicalOccupancy().
     *
     * @param  array<int, string>  $dates  the newly added past days, ascending
     * @param  array<int, int>  $technicianIds  who is being named
     * @param  Collection<int, array{schedule_id: ?int, mode: string, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     * @param  array<int, array{added: array<int, string>, removed: array<int, string>}>  $rangeAssessment
     * @return array<int, array{
     *     technician_id: int,
     *     technician: string,
     *     entries: array<int, array{date: string, date_label: string, project_id: int, project: string, reference: ?string, schedule_id: int, schedule: string, status: string}>
     * }>
     */
    public function conflictsFor(
        Project $project,
        array $dates,
        array $technicianIds,
        Collection $ranges,
        array $rangeAssessment
    ): array {
        $technicianIds = collect($technicianIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($dates === [] || $technicianIds->isEmpty()) {
            return [];
        }

        $requests = $this->historicalRequests($dates, $ranges, $rangeAssessment);

        if ($requests === []) {
            return [];
        }

        $found = app(TechnicianAvailabilityService::class)->historicalOccupancy(
            $technicianIds,
            $requests,
            // The project's own bookings are not a clash with itself. This is
            // the days it is claiming measured against everybody ELSE'S.
            (int) $project->project_id
        );

        if ($found === []) {
            return [];
        }

        // Looked up rather than taken from the caller, so the read-only
        // pre-flight and the save both name people the same way without one of
        // them having to build memberships first.
        $names = Technician::query()
            ->whereIn('technician_id', $technicianIds->all())
            ->with('account:id,name,first_name,middle_name,last_name')
            ->get()
            ->mapWithKeys(fn (Technician $technician): array => [
                (int) $technician->technician_id => $technician->name,
            ]);

        $schedules = Schedule::query()
            ->with('project:project_id,name,reference_no,status')
            ->whereIn('schedule_id', collect($found)->flatten(1)->pluck('schedule_id')->unique()->all())
            ->get()
            ->keyBy('schedule_id');

        $conflicts = [];

        foreach ($found as $technicianId => $hits) {
            $entries = [];

            foreach ($hits as $hit) {
                $schedule = $schedules->get($hit['schedule_id']);
                $other = $schedule?->project;

                $entries[] = [
                    'date' => $hit['date'],
                    'date_label' => BusinessTime::format($hit['date']),
                    'project_id' => $hit['project_id'],
                    'project' => $other?->name ?? 'Another project',
                    'reference' => $other?->reference_no,
                    'schedule_id' => $hit['schedule_id'],
                    // The booking that is already there, in the words the rest
                    // of the system describes a booking in.
                    'schedule' => $schedule?->describe() ?? '',
                    'status' => $other ? $other->statusLabel() : '',
                ];
            }

            $conflicts[] = [
                'technician_id' => (int) $technicianId,
                'technician' => $names[(int) $technicianId] ?? 'Technician',
                'entries' => $entries,
            ];
        }

        usort($conflicts, fn (array $first, array $second): int => strcmp(
            mb_strtolower($first['technician']),
            mb_strtolower($second['technician'])
        ));

        return $conflicts;
    }

    /**
     * The newly added past days, as availability requests carrying the hours
     * of the range each one came from.
     *
     * A day is only checked against the shape it is actually being booked in.
     * A whole-day correction asks about the whole day; a partial-day one asks
     * about its hours and nothing else, which is what stops an afternoon
     * correction reading as a clash with a booked morning.
     *
     * @param  array<int, string>  $dates
     * @param  Collection<int, array{schedule_id: ?int, mode: string, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     * @param  array<int, array{added: array<int, string>, removed: array<int, string>}>  $rangeAssessment
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode: string}>
     */
    private function historicalRequests(array $dates, Collection $ranges, array $rangeAssessment): array
    {
        $wanted = array_flip($dates);
        $requests = [];

        foreach ($ranges as $index => $range) {
            $added = $rangeAssessment[$index]['added'] ?? [];

            foreach ($added as $date) {
                if (! isset($wanted[$date])) {
                    continue;
                }

                if ($range['mode'] === Schedule::MODE_PARTIAL_DAY) {
                    // One date, and the hours it was booked for.
                    $requests[] = [
                        'start' => $range['start'],
                        'end' => $range['end'],
                        'mode' => Schedule::MODE_PARTIAL_DAY,
                    ];

                    continue;
                }

                // A whole-day request confined to the single day being added,
                // rather than the whole range: the rest of the range may be
                // days the project already held, and those are not being newly
                // claimed.
                $day = CarbonImmutable::parse($date);

                $requests[] = [
                    'start' => $day->startOfDay(),
                    'end' => $day->endOfDay(),
                    'mode' => Schedule::MODE_DATE_BASED,
                ];
            }
        }

        return $requests;
    }

    /**
     * A confirmed clash, flattened for the audit row.
     *
     * Written to the correction so that somebody reading the record later can
     * see what the system objected to and that a person overruled it on
     * purpose - see the `conflicts` column on tbl_schedule_corrections.
     *
     * @param  array<int, array<string, mixed>>  $conflicts  as conflictsFor() returns
     * @return array<int, array<string, mixed>>
     */
    public function describeConflicts(array $conflicts, ?User $actor): array
    {
        $rows = [];

        foreach ($conflicts as $conflict) {
            foreach ($conflict['entries'] as $entry) {
                $rows[] = [
                    'technician_id' => $conflict['technician_id'],
                    'technician' => $conflict['technician'],
                    'date' => $entry['date'],
                    'conflicting_project_id' => $entry['project_id'],
                    'conflicting_project' => $entry['project'],
                    'conflicting_reference' => $entry['reference'],
                    'conflicting_schedule_id' => $entry['schedule_id'],
                    'conflicting_schedule' => $entry['schedule'],
                    // Stated rather than implied: the row exists BECAUSE
                    // somebody was asked and said yes.
                    'confirmed' => true,
                    'confirmed_by_id' => $actor?->id,
                    'confirmed_by' => $actor?->fullName(),
                    'confirmed_at' => Schedule::businessNow()->toDateTimeString(),
                ];
            }
        }

        return $rows;
    }

    /**
     * "John Smith on Aug 20, 2026 (Harbour Fit-Out)" for each clash, for the
     * activity log and the toast.
     *
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    public function describeConflictSummary(array $conflicts): string
    {
        $parts = [];

        foreach ($conflicts as $conflict) {
            foreach ($conflict['entries'] as $entry) {
                $parts[] = sprintf(
                    '%s on %s (%s)',
                    $conflict['technician'],
                    $entry['date_label'],
                    $entry['reference'] ?: $entry['project']
                );
            }
        }

        return implode('; ', $parts);
    }

    /**
     * Turn the chosen technicians into memberships that can carry the work.
     *
     * Every id is checked against the same rule the picker drew: a member whose
     * span covers the dates is taken as they are, and anybody else is refused
     * unless the correction explicitly says they are being added for it - which
     * is the Super Admin stating that this person really was on site, and their
     * membership is widened to say so.
     *
     * @param  array<int, string>  $dates  the days being attributed, ascending
     * @param  array<int, int>  $technicianIds
     * @return Collection<int, ProjectTechnician>
     *
     * @throws RuntimeException
     */
    public function attribute(
        Project $project,
        array $dates,
        array $technicianIds,
        bool $allowNonMembers,
        ?int $actorId = null
    ): Collection {
        $wanted = collect($technicianIds)
            ->map(fn ($technicianId): int => (int) $technicianId)
            ->filter()
            ->unique()
            ->values();

        if ($dates === []) {
            return collect();
        }

        if ($wanted->isEmpty()) {
            throw new RuntimeException(sprintf(
                '%s %s in the past and %s not scheduled for this project. Say who worked %s before saving.',
                $this->describeDates($dates),
                count($dates) === 1 ? 'is' : 'are',
                count($dates) === 1 ? 'was' : 'were',
                count($dates) === 1 ? 'it' : 'them'
            ));
        }

        $technicians = Technician::query()
            ->with('account')
            ->whereIn('technician_id', $wanted->all())
            ->get()
            ->keyBy(fn (Technician $technician): int => (int) $technician->technician_id);

        if ($wanted->reject(fn (int $technicianId): bool => $technicians->has($technicianId))->isNotEmpty()) {
            throw new RuntimeException('One of the technicians named for these dates no longer exists.');
        }

        $project->loadMissing('teamHistory');

        // Everything that can refuse this crew is asked before anything is
        // written, and in that order: a name that does not belong on these days
        // is the specific complaint and is made first, then the shape of the
        // crew as a whole. A correction refused half way through would
        // otherwise have already widened somebody's membership on its way to
        // saying no.
        foreach ($wanted as $technicianId) {
            if ($this->membershipFor($project, $technicianId, $dates) || $allowNonMembers) {
                continue;
            }

            throw new RuntimeException(sprintf(
                '%s was not on this project for %s. Add them through the historical correction to record it.',
                $technicians->get($technicianId)->name,
                $this->describeDates($dates)
            ));
        }

        $this->assertOneLead($technicians, $dates);

        $first = CarbonImmutable::parse($dates[0])->startOfDay();
        $last = CarbonImmutable::parse($dates[count($dates) - 1])->startOfDay();

        return $wanted->map(function (int $technicianId) use (
            $project,
            $dates,
            $actorId,
            $first,
            $last
        ): ProjectTechnician {
            $assignment = $this->membershipFor($project, $technicianId, $dates);

            if ($assignment) {
                return $assignment;
            }

            $membership = $this->projectTeam->coverHistoricalWork(
                $project,
                $technicianId,
                $first,
                $last,
                $actorId
            );

            // The next technician is looked up against a team that now
            // includes this one.
            $project->unsetRelation('teamHistory');
            $project->loadMissing('teamHistory');

            return $membership;
        });
    }

    /**
     * This technician's membership of the project, if one of them covers every
     * date being attributed.
     *
     * @param  array<int, string>  $dates
     */
    private function membershipFor(Project $project, int $technicianId, array $dates): ?ProjectTechnician
    {
        return $project->teamHistory
            ->first(fn (ProjectTechnician $membership): bool => (int) $membership->technician_id === $technicianId
                && $this->coversAll($membership, $dates));
    }

    /**
     * A crew has a lead, and one lead.
     *
     * The same rule ProjectTeamRules applies to a team being built, applied to
     * a crew being recorded: `lead_technician` is a rank rather than a slot, so
     * days recorded with nobody of that rank on them read as days nobody was in
     * charge of, and days recorded with two of them leave which one led it to
     * row order. Neither is a thing the record should be able to say.
     *
     * @param  Collection<int, Technician>  $technicians
     * @param  array<int, string>  $dates
     *
     * @throws RuntimeException
     */
    private function assertOneLead(Collection $technicians, array $dates): void
    {
        $leads = $technicians->filter(fn (Technician $technician): bool => $technician->isLead())->values();

        if ($leads->count() === 1) {
            return;
        }

        if ($leads->isEmpty()) {
            throw new RuntimeException(sprintf(
                'Name the Lead Technician who worked %s. A day on the record has one.',
                $this->describeDates($dates)
            ));
        }

        throw new RuntimeException(sprintf(
            '%s are both Lead Technicians. Only one of them can have led %s.',
            $leads->map(fn (Technician $technician): string => $technician->name)->join(', ', ' and '),
            $this->describeDates($dates)
        ));
    }

    /**
     * Book the named crew onto the range that now carries the historical days.
     *
     * Only ever adds. A range extended backwards keeps whoever was already on
     * it - those people worked the days it already covered, and the correction
     * says nothing about them.
     *
     * @param  Collection<int, ProjectTechnician>  $crew
     */
    public function link(Schedule $schedule, Collection $crew): void
    {
        $alreadyBooked = ScheduleTechnician::query()
            ->where('schedule_id', $schedule->schedule_id)
            ->pluck('project_technician_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($crew as $assignment) {
            $assignmentId = (int) $assignment->project_technician_id;

            if (in_array($assignmentId, $alreadyBooked, true)) {
                continue;
            }

            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignmentId,
            ]);

            $alreadyBooked[] = $assignmentId;
        }
    }

    /**
     * The chosen crew in the shape the audit row stores them.
     *
     * @param  Collection<int, ProjectTechnician>  $crew
     * @return array<int, array{technician_id: int, name: string}>
     */
    public function describeCrew(Collection $crew): array
    {
        return $crew
            ->map(fn (ProjectTechnician $assignment): array => [
                'technician_id' => (int) $assignment->technician_id,
                'name' => $assignment->technician?->name ?? 'Unknown technician',
            ])
            ->values()
            ->all();
    }

    /**
     * Write the audit rows for one save.
     *
     * @param  array<int, array{
     *     schedule_id: ?int,
     *     original_range: ?string,
     *     new_range: ?string,
     *     added: array<int, string>,
     *     removed: array<int, string>,
     *     technicians: array<int, array{technician_id: int, name: string}>
     * }>  $entries
     * @param  array<int, array<string, mixed>>  $conflicts  clashes a Super Admin
     *                                                       confirmed, flattened by describeConflicts()
     */
    public function record(Project $project, array $entries, ?User $actor, array $conflicts = []): void
    {
        $now = Schedule::businessNow();

        foreach ($entries as $entry) {
            if ($entry['added'] === [] && $entry['removed'] === []) {
                continue;
            }

            // A confirmed clash belongs on the rows that actually added days:
            // it is a statement about work newly claimed, and a row that only
            // gave days up has nothing to answer for. Narrowed to the days
            // this row added, so a correction spanning two rows does not file
            // the same clash under both.
            $addedHere = array_flip($entry['added']);
            $rowConflicts = array_values(array_filter(
                $conflicts,
                fn (array $conflict): bool => isset($addedHere[$conflict['date']])
            ));

            ScheduleCorrection::create([
                'project_id' => $project->project_id,
                'schedule_id' => $entry['schedule_id'],
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->fullName() ?? 'System',
                'actor_role' => $actor?->role,
                'original_range' => $entry['original_range'],
                'new_range' => $entry['new_range'],
                'added_dates' => array_values($entry['added']),
                'removed_dates' => array_values($entry['removed']),
                'technicians' => array_values($entry['technicians']),
                'conflicts' => $rowConflicts === [] ? null : $rowConflicts,
                'created_at' => $now,
            ]);
        }
    }

    /**
     * A list of dates as a person would say it: runs of consecutive days are
     * one phrase, so three days in a row read "Aug 17-19, 2026" rather than as
     * three separate dates.
     *
     * @param  array<int, string>  $dates
     */
    public function describeDates(array $dates): string
    {
        $runs = array_map(
            fn (array $run): string => $this->describeRun($run),
            $this->runsOf($dates)
        );

        if ($runs === []) {
            return '';
        }

        if (count($runs) === 1) {
            return $runs[0];
        }

        $last = array_pop($runs);

        return implode(', ', $runs).' and '.$last;
    }

    /**
     * The stored rows this save deletes outright, with the past days each of
     * them gives up.
     *
     * @param  Collection<int, array{schedule_id: ?int, mode: string, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     * @param  array<int, int>  $survivingScheduleIds
     * @param  array<int, array<int, string>>  $storedPastByRow
     * @param  array<int, string>  $removed
     * @return array<int, array{schedule: Schedule, removed: array<int, string>}>
     */
    private function droppedRows(
        Project $project,
        Collection $ranges,
        array $survivingScheduleIds,
        array $storedPastByRow,
        array $removed
    ): array {
        $submittedIds = $ranges->pluck('schedule_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $keptIds = array_map('intval', $survivingScheduleIds);

        $dropped = [];

        foreach ($project->schedules as $schedule) {
            $scheduleId = (int) $schedule->schedule_id;

            if (in_array($scheduleId, $submittedIds, true) || in_array($scheduleId, $keptIds, true)) {
                continue;
            }

            $givenUp = $this->unique(array_intersect($storedPastByRow[$scheduleId] ?? [], $removed));

            if ($givenUp === []) {
                continue;
            }

            $dropped[] = ['schedule' => $schedule, 'removed' => $givenUp];
        }

        return $dropped;
    }

    /**
     * Whether a membership span covers every one of the given dates.
     *
     * @param  array<int, string>  $dates
     */
    private function coversAll(ProjectTechnician $assignment, array $dates): bool
    {
        if ($dates === []) {
            return false;
        }

        foreach ($dates as $date) {
            if (! $assignment->coveredOn($date)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why somebody is on the "add them deliberately" list rather than on the
     * straightforward one.
     *
     * @param  array<int, string>  $dates  ascending
     */
    private function whyNotAMember(Project $project, int $technicianId, array $dates): string
    {
        $membership = $project->teamHistory
            ->first(fn (ProjectTechnician $assignment): bool => (int) $assignment->technician_id === $technicianId);

        if (! $membership) {
            return 'Not on this project';
        }

        if ($membership->isRemoved()
            && CarbonImmutable::parse($membership->removed_at)->lte(CarbonImmutable::parse($dates[0]))) {
            return 'Left this project on '.BusinessTime::format($membership->removed_at);
        }

        return $membership->joined_at
            ? 'Joined this project on '.BusinessTime::format($membership->joined_at)
            : 'Not on this project for all of these dates';
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<int, array<int, string>>
     */
    private function runsOf(array $dates): array
    {
        $dates = $this->unique($dates);

        if ($dates === []) {
            return [];
        }

        $runs = [];
        $current = [array_shift($dates)];

        foreach ($dates as $date) {
            $previous = CarbonImmutable::parse($current[count($current) - 1]);

            if ($previous->addDay()->toDateString() === $date) {
                $current[] = $date;

                continue;
            }

            $runs[] = $current;
            $current = [$date];
        }

        $runs[] = $current;

        return $runs;
    }

    /**
     * @param  array<int, string>  $run
     */
    private function describeRun(array $run): string
    {
        $first = CarbonImmutable::parse($run[0]);
        $last = CarbonImmutable::parse($run[count($run) - 1]);

        if ($first->equalTo($last)) {
            return $first->format(BusinessTime::DATE);
        }

        // "Aug 17-19, 2026" while the run stays inside one month, and the full
        // pair once it crosses one - "Aug 30 - Sep 2, 2026".
        if ($first->format('Y-m') === $last->format('Y-m')) {
            return sprintf('%s-%s, %s', $first->format('M j'), $last->format('j'), $last->format('Y'));
        }

        return sprintf('%s - %s', $first->format('M j'), $last->format(BusinessTime::DATE));
    }

    /**
     * @param  array<int, string>  $dates
     * @return array<int, string>
     */
    private function unique(array $dates): array
    {
        $unique = array_values(array_unique($dates));

        sort($unique);

        return $unique;
    }
}
