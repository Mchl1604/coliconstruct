<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;
use RuntimeException;

/**
 * Changing a project's team from a given day - today, or one still to come.
 *
 * Every screen that changes a team goes through here: the Edit Assigned Team
 * dialog, and Remove From Project on the Technicians page. A change is stated
 * the way an administrator thinks about it - "from Aug 21, the team is this
 * lead and these technicians" - and this class works out what that means for
 * the membership spans, decides whether it is allowed, lists the work it would
 * strand, and then writes it.
 *
 * The rules, all asked of the team as it will read after the change (see
 * ProjectTeamChangePlan):
 *
 *   - The change takes effect today or later. A day already worked is a
 *     record, and it is corrected through the Super Admin's historical flow,
 *     not rewritten by a staffing decision.
 *   - From the effective day onwards there is exactly one lead on every day,
 *     for as long as the project is live - past its last scheduled day too.
 *     A lead leaving therefore always comes with a replacement starting the
 *     same day, and nobody is ever promoted automatically: lead is a rank on
 *     the account, and the administrator chooses who holds it.
 *   - The team never empties.
 *   - Everybody starting is free for the project's remaining dates from the
 *     day they start, and no earlier - somebody busy elsewhere until the
 *     handover is still free to take it over.
 *   - No open task is left with a holder who is not assigned for the whole of
 *     its dates. Each one the change would strand has to be answered for:
 *     given to somebody who is assigned for all of it, unassigned, or - for a
 *     change still to come - kept and flagged until somebody sorts it out.
 *     Nothing is moved on the administrator's behalf.
 */
class ProjectTeamChange
{
    /** Leave the task with its holder, flagged as a conflict. */
    public const KEEP = 'keep';

    /** Take the holder off the task; it shows as Missing Technician. */
    public const UNASSIGN = 'unassign';

    public function __construct(
        private readonly ProjectTeam $team,
        private readonly ProjectTeamRules $teamRules,
        private readonly TaskAssignmentRules $taskRules,
        private readonly TechnicianAvailabilityService $availability,
    ) {}

    // ------------------------------------------------------------------
    // Working it out
    // ------------------------------------------------------------------

    /**
     * The master control: "the team is now this lead and these technicians",
     * taking effect today.
     *
     * Everybody on the team today who is not wanted comes off completely - off
     * today, and any return or later span of theirs called off with it.
     * Everybody wanted who is not on it today either has a span still to come,
     * which is brought forward to today, or starts a new one. Anybody not on the
     * team today and not mentioned - somebody on days off, a stand-in lead
     * booked for next week - is left exactly as they are: those changes are
     * made, and undone, on the Technicians page.
     *
     * @param  iterable<int, mixed>  $technicianIds  the rest of the team; the lead
     *                                               may or may not be among them
     */
    public function plan(Project $project, CarbonImmutable $effective, ?int $leadId, iterable $technicianIds): ProjectTeamChangePlan
    {
        $effective = $effective->startOfDay();
        $effectiveDate = $effective->toDateString();
        $before = $this->spansOf($project);

        $wanted = collect([$leadId, ...collect($technicianIds)->all()])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $onTheDay = $before->filter(fn (ProjectTechnician $span): bool => $span->isCurrent($effectiveDate));

        $closing = $onTheDay
            ->reject(fn (ProjectTechnician $span): bool => $wanted->contains((int) $span->technician_id))
            ->values();

        $leavingIds = $closing->pluck('technician_id')->map(fn ($id): int => (int) $id)->all();

        // Off completely: whatever else they had still to come goes too.
        $cancelling = $before
            ->filter(fn (ProjectTechnician $span): bool => in_array((int) $span->technician_id, $leavingIds, true)
                && $span->startDate() !== null
                && $span->startDate() > $effectiveDate)
            ->values();

        $startingEarlier = collect();
        $joining = collect();

        foreach ($wanted as $technicianId) {
            if ($onTheDay->contains(fn (ProjectTechnician $span): bool => (int) $span->technician_id === $technicianId)) {
                continue;
            }

            $later = $before
                ->filter(fn (ProjectTechnician $span): bool => (int) $span->technician_id === $technicianId
                    && $span->startDate() !== null
                    && $span->startDate() > $effectiveDate)
                ->sortBy(fn (ProjectTechnician $span): string => (string) $span->startDate())
                ->first();

            $later
                ? $startingEarlier->push($later)
                : $joining->push(['technician_id' => $technicianId, 'from' => $effective, 'until' => null]);
        }

        return $this->build(
            ProjectTeamChangePlan::KIND_TEAM,
            $project,
            $effective,
            $before,
            $closing,
            $cancelling,
            $startingEarlier->values(),
            $joining->values(),
            leadId: $leadId,
            technicianIds: $wanted,
        );
    }

    /**
     * Take one technician off a project from a day onward - that day, and every
     * day of theirs after it.
     *
     * A lead coming off needs somebody to take the lead over, from the first
     * day they would otherwise have led: the removal day itself, or - when they
     * are on days off then - the day they were due back.
     */
    public function planRemoval(Project $project, int $technicianId, CarbonImmutable $from, ?int $replacementLeadId = null): ProjectTeamChangePlan
    {
        $from = $from->startOfDay();
        $fromDate = $from->toDateString();
        $before = $this->spansOf($project);
        $theirs = $before->filter(fn (ProjectTechnician $span): bool => (int) $span->technician_id === $technicianId);

        $closing = $theirs->filter(fn (ProjectTechnician $span): bool => $span->isCurrent($fromDate))->values();

        $cancelling = $theirs
            ->filter(fn (ProjectTechnician $span): bool => $span->startDate() !== null && $span->startDate() > $fromDate)
            ->values();

        $joining = collect();

        if ($replacementLeadId) {
            $takesOverOn = $closing->isNotEmpty()
                ? $from
                : CarbonImmutable::parse((string) $cancelling->sortBy(fn (ProjectTechnician $span): string => (string) $span->startDate())->first()?->startDate() ?: $fromDate);

            $joining->push(['technician_id' => $replacementLeadId, 'from' => $takesOverOn, 'until' => null]);
        }

        return $this->build(
            ProjectTeamChangePlan::KIND_REMOVAL,
            $project,
            $from,
            $before,
            $closing,
            $cancelling,
            collect(),
            $joining,
            subjectId: $technicianId,
            leadCoverId: $replacementLeadId,
        );
    }

    /**
     * Take one technician off a project for a run of days - off from $from, back
     * the day after $lastDay.
     *
     * Their span is split around the days: it closes at $from, and a new span
     * opens the day after, running on to wherever the original was due to end.
     * A lead needs a stand-in, who leads for exactly those days.
     *
     * @throws RuntimeException when no single span of theirs holds every one of
     *                          the days
     */
    public function planDaysOff(
        Project $project,
        int $technicianId,
        CarbonImmutable $from,
        CarbonImmutable $lastDay,
        ?int $standInLeadId = null
    ): ProjectTeamChangePlan {
        $from = $from->startOfDay();
        $resumesOn = $lastDay->startOfDay()->addDay();
        $before = $this->spansOf($project);

        $span = $before->first(fn (ProjectTechnician $candidate): bool => (int) $candidate->technician_id === $technicianId
            && $candidate->coversPeriod($from->toDateString(), $lastDay->toDateString()));

        if ($span === null) {
            throw new RuntimeException(sprintf(
                '%s is not assigned to this project for every day from %s to %s.',
                Technician::query()->with('account')->find($technicianId)?->name ?? 'This technician',
                $from->format(BusinessTime::DATE),
                $lastDay->format(BusinessTime::DATE)
            ));
        }

        $joining = collect();

        // Back the day after, for as long as the span they are being taken off
        // was due to run.
        if ($span->endDate() === null || $span->endDate() > $resumesOn->toDateString()) {
            $joining->push([
                'technician_id' => $technicianId,
                'from' => $resumesOn,
                'until' => $span->endDate() === null ? null : CarbonImmutable::parse($span->endDate()),
            ]);
        }

        if ($standInLeadId) {
            $joining->push([
                'technician_id' => $standInLeadId,
                'from' => $from,
                'until' => $span->endDate() !== null && $span->endDate() < $resumesOn->toDateString()
                    ? CarbonImmutable::parse($span->endDate())
                    : $resumesOn,
            ]);
        }

        return $this->build(
            ProjectTeamChangePlan::KIND_DAYS_OFF,
            $project,
            $from,
            $before,
            collect([$span]),
            collect(),
            collect(),
            $joining,
            subjectId: $technicianId,
            resumesOn: $resumesOn,
            leadCoverId: $standInLeadId,
        );
    }

    /**
     * Every span of the project, empty ones aside, with who holds them.
     *
     * @return Collection<int, ProjectTechnician>
     */
    private function spansOf(Project $project): Collection
    {
        return ProjectTechnician::query()
            ->with('technician.account')
            ->where('project_id', $project->project_id)
            ->orderBy('joined_at')
            ->get()
            ->reject(fn (ProjectTechnician $span): bool => $span->isEmptySpan())
            ->values();
    }

    /**
     * The team as it will read once the operations are applied.
     *
     * @param  Collection<int, ProjectTechnician>  $before
     * @param  Collection<int, ProjectTechnician>  $closing
     * @param  Collection<int, ProjectTechnician>  $cancelling
     * @param  Collection<int, ProjectTechnician>  $startingEarlier
     * @param  Collection<int, array{technician_id: int, from: CarbonImmutable, until: ?CarbonImmutable}>  $joining
     * @param  Collection<int, int>|null  $technicianIds
     */
    private function build(
        string $kind,
        Project $project,
        CarbonImmutable $effective,
        Collection $before,
        Collection $closing,
        Collection $cancelling,
        Collection $startingEarlier,
        Collection $joining,
        ?int $leadId = null,
        ?Collection $technicianIds = null,
        ?int $subjectId = null,
        ?CarbonImmutable $resumesOn = null,
        ?int $leadCoverId = null,
    ): ProjectTeamChangePlan {
        $effectiveDate = $effective->toDateString();
        $has = fn (Collection $spans, ProjectTechnician $span): bool => $spans->contains(fn (ProjectTechnician $other): bool => $other->is($span));

        $after = $before
            ->reject(fn (ProjectTechnician $span): bool => $has($cancelling, $span))
            ->map(function (ProjectTechnician $span) use ($closing, $startingEarlier, $effective, $effectiveDate, $has): ?ProjectTechnician {
                $copy = clone $span;

                if ($has($closing, $span)) {
                    // A start that would not have happened by then is called
                    // off outright - see ProjectTeam::close().
                    if ($span->isUpcoming() && $span->startDate() >= $effectiveDate) {
                        return null;
                    }

                    $copy->removed_at = $effective;
                }

                if ($has($startingEarlier, $span)) {
                    $copy->joined_at = $effective;
                }

                return $copy;
            })
            ->filter()
            ->values();

        foreach ($joining as $join) {
            $new = new ProjectTechnician([
                'project_id' => $project->project_id,
                'technician_id' => $join['technician_id'],
                'joined_at' => $join['from'],
                'removed_at' => $join['until'],
            ]);

            $new->setRelation('technician', Technician::query()->with('account')->find($join['technician_id']));

            $after->push($new);
        }

        return new ProjectTeamChangePlan(
            kind: $kind,
            project: $project,
            effective: $effective,
            before: $before,
            after: $after,
            closing: $closing->values(),
            cancelling: $cancelling->values(),
            startingEarlier: $startingEarlier->values(),
            joining: $joining->values(),
            leadId: $leadId,
            technicianIds: $technicianIds ?? collect(),
            subjectId: $subjectId,
            resumesOn: $resumesOn,
            leadCoverId: $leadCoverId,
        );
    }

    /**
     * Add every rule a change has to satisfy to a form's validator.
     *
     * Errors land on the field the person is looking at: the date, the lead
     * select, or the technician picker.
     */
    public function validate(Validator $validator, ProjectTeamChangePlan $plan): void
    {
        $validator->after(function (Validator $validator) use ($plan): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->problems($plan) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    /**
     * The same rules, as field => message, for callers that answer in JSON.
     *
     * @return array<string, string>
     */
    public function problems(ProjectTeamChangePlan $plan): array
    {
        if ($plan->effectiveDate() < ProjectTechnician::today()) {
            return ['effective_date' => 'A team change cannot take effect on a day that has already passed.'];
        }

        if ($plan->kind === ProjectTeamChangePlan::KIND_TEAM) {
            $validator = \Illuminate\Support\Facades\Validator::make([], []);

            $this->teamRules->validate(
                $validator,
                $plan->leadId,
                $plan->technicianIds->all(),
                $plan->teamOnEffectiveDateBefore()->all()
            );

            if ($validator->errors()->isNotEmpty()) {
                return collect($validator->errors()->messages())
                    ->map(fn (array $messages): string => $messages[0])
                    ->all();
            }
        }

        if ($plan->leadCoverId !== null && ($problem = $this->leadCoverProblem($plan))) {
            return ['replacement_lead_id' => $problem];
        }

        $teamOnTheDay = $plan->after->filter(fn (ProjectTechnician $span): bool => $span->isCurrent($plan->effectiveDate()));

        if ($teamOnTheDay->isEmpty()) {
            return ['technicians' => 'A project must keep at least one technician. Assign someone else first.'];
        }

        // Only a problem this change CREATES is a reason to refuse it. A
        // project left without a lead before these rules existed is flagged on
        // its own - see Project::needsRecrew() - and must not make every other
        // change to its team impossible until that is fixed.
        $existing = collect($this->leadProblems($plan->before, $plan->effectiveDate()))->pluck('key')->all();

        $new = collect($this->leadProblems($plan->after, $plan->effectiveDate()))
            ->reject(fn (array $problem): bool => in_array($problem['key'], $existing, true))
            ->first();

        return $new ? ['lead_tech' => $new['message']] : [];
    }

    /**
     * Why the technician chosen to lead in somebody's place - a replacement or a
     * stand-in - cannot, or null when they can.
     */
    private function leadCoverProblem(ProjectTeamChangePlan $plan): ?string
    {
        $cover = Technician::query()->with('account')->find($plan->leadCoverId);

        if ($cover === null) {
            return 'That lead technician no longer exists.';
        }

        if ((int) $cover->technician_id === (int) $plan->subjectId) {
            return 'The lead covering for them must be a different technician.';
        }

        if (! $cover->isLead()) {
            return sprintf('%s is not a Lead Technician.', $cover->name);
        }

        if (! $cover->isAssignable()) {
            return $this->teamRules->unavailableMessage($cover);
        }

        $join = $plan->joining->first(fn (array $join): bool => (int) $join['technician_id'] === (int) $cover->technician_id);

        if ($join === null) {
            return null;
        }

        $clashes = $plan->spansBeforeFor((int) $cover->technician_id)->contains(
            fn (ProjectTechnician $span): bool => $span->overlaps($join['from']->toDateString(), $join['until']?->toDateString())
        );

        return $clashes
            ? sprintf('%s is already on this project for some of those days.', $cover->name)
            : null;
    }

    /**
     * Whoever starts is checked for the project's remaining dates from the
     * day they start. Returned as one sentence, or null when everybody is free.
     */
    public function availabilityConflict(ProjectTeamChangePlan $plan): ?string
    {
        $ranges = Schedule::upcomingAvailabilityRanges($plan->project->schedules()->get());

        if ($ranges === []) {
            return null;
        }

        $conflicts = collect();

        foreach ($plan->after as $span) {
            $isStarting = ! $span->exists
                || $plan->startingEarlier->contains(fn (ProjectTechnician $moved): bool => $moved->is($span));

            if (! $isStarting) {
                continue;
            }

            $clipped = $this->rangesWithin($ranges, (string) $span->startDate(), $span->endDate());

            if ($clipped === []) {
                continue;
            }

            $conflicts = $conflicts->merge(
                $this->availability->findConflicts([(int) $span->technician_id], $clipped, (int) $plan->project->project_id)
            );
        }

        return $conflicts->isEmpty() ? null : $this->availability->conflictMessage($conflicts);
    }

    /**
     * The open tasks this change would strand: their holder is assigned for
     * all of their dates now, and would not be afterwards.
     *
     * A task that was already out of step before the change is not this
     * change's to answer for - it is flagged on the board already.
     *
     * @return Collection<int, array{task: Task, reason: string, options: Collection<int, Technician>, can_keep: bool}>
     */
    public function taskConflicts(ProjectTeamChangePlan $plan): Collection
    {
        $tasks = Task::query()
            ->with('technician.account')
            ->where('project_id', $plan->project->project_id)
            ->whereIn('status', Task::OPEN_STATUSES)
            ->whereNotNull('technician_id')
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('task_id')
            ->get();

        $candidateIds = $plan->after->pluck('technician_id')->map(fn ($id): int => (int) $id)->unique()->values();

        $candidates = Technician::query()
            ->with('account')
            ->whereIn('technician_id', $candidateIds->all())
            ->get()
            ->keyBy(fn (Technician $technician): int => (int) $technician->technician_id);

        return $tasks
            ->map(function (Task $task) use ($plan, $candidates): ?array {
                $holderId = (int) $task->technician_id;
                [$start, $due] = $this->taskDates($task);

                if (! $this->holds($plan->spansBeforeFor($holderId), $start, $due)
                    || $this->holds($plan->spansAfterFor($holderId), $start, $due)) {
                    return null;
                }

                $options = $candidates
                    ->reject(fn (Technician $technician): bool => (int) $technician->technician_id === $holderId)
                    ->filter(fn (Technician $technician): bool => $technician->isAssignable()
                        && $this->holds($plan->spansAfterFor((int) $technician->technician_id), $start, $due))
                    ->sortBy(fn (Technician $technician): string => mb_strtolower($technician->name))
                    ->values();

                return [
                    'task' => $task,
                    'reason' => (string) $this->taskRules->periodRefusal(
                        $task->technician,
                        (int) $plan->project->project_id,
                        $start,
                        $due,
                        $plan->spansAfterFor($holderId)
                    ),
                    'options' => $options,
                    'can_keep' => ! $plan->isImmediate(),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * What the person still has to decide, as sentences - empty when every
     * stranded task has a valid answer.
     *
     * @param  Collection<int, array<string, mixed>>  $conflicts
     * @param  array<int|string, mixed>  $resolutions  task_id => keep | unassign | technician_id
     * @return array<int, string>
     */
    public function unresolved(Collection $conflicts, array $resolutions): array
    {
        $missing = [];
        $invalid = [];

        foreach ($conflicts as $conflict) {
            $task = $conflict['task'];
            $choice = (string) ($resolutions[$task->task_id] ?? '');

            if ($choice === '') {
                $missing[] = $task->task_title;

                continue;
            }

            $valid = $choice === self::UNASSIGN
                || ($choice === self::KEEP && $conflict['can_keep'])
                || (ctype_digit($choice) && $conflict['options']->contains(
                    fn (Technician $technician): bool => (int) $technician->technician_id === (int) $choice
                ));

            if (! $valid) {
                $invalid[] = $task->task_title;
            }
        }

        $messages = [];

        if ($missing !== []) {
            $messages[] = sprintf(
                'Choose what happens to %s before saving: %s.',
                count($missing) === 1 ? 'the task this change affects' : count($missing).' tasks this change affects',
                $this->quotedList($missing)
            );
        }

        if ($invalid !== []) {
            $messages[] = sprintf(
                'The choice for %s is no longer possible. Review the affected tasks and save again.',
                $this->quotedList($invalid)
            );
        }

        return $messages;
    }

    // ------------------------------------------------------------------
    // Doing it
    // ------------------------------------------------------------------

    /**
     * Write the change and carry out the choices made for the stranded tasks.
     *
     * The caller has already validated the plan and its resolutions and holds
     * the transaction.
     *
     * @param  array<int|string, mixed>  $resolutions
     * @return array{unassigned: Collection<int, array{task: Task, holder: string}>, reassigned: Collection<int, array{task: Task, previous: ?User}>, kept: Collection<int, Task>}
     */
    public function apply(ProjectTeamChangePlan $plan, array $resolutions, ?int $actorId): array
    {
        // Read before anything is written: the conflicts are a question about
        // the team as it stands now against the team as it will be.
        $conflicts = $this->taskConflicts($plan);

        foreach ($plan->cancelling as $span) {
            $this->team->cancelStart($span);
        }

        foreach ($plan->closing as $span) {
            $this->team->close($plan->project, $span, $plan->effective, $actorId);
        }

        foreach ($plan->startingEarlier as $span) {
            $this->team->startEarlier($plan->project, $span, $plan->effective, $actorId);
        }

        foreach ($plan->joining as $join) {
            $this->team->open($plan->project, $join['technician_id'], $actorId, $join['from'], $join['until']);
        }

        return $this->resolve($conflicts, $resolutions);
    }

    /**
     * Carry out the choices for stranded tasks.
     *
     * @param  Collection<int, array<string, mixed>>  $conflicts
     * @param  array<int|string, mixed>  $resolutions
     * @return array{unassigned: Collection<int, array{task: Task, holder: string}>, reassigned: Collection<int, array{task: Task, previous: ?User}>, kept: Collection<int, Task>}
     */
    public function resolve(Collection $conflicts, array $resolutions): array
    {
        $unassigned = collect();
        $reassigned = collect();
        $kept = collect();

        foreach ($conflicts as $conflict) {
            $task = $conflict['task'];
            $choice = (string) ($resolutions[$task->task_id] ?? self::UNASSIGN);

            if ($choice === self::KEEP) {
                $kept->push($task);

                continue;
            }

            if ($choice === self::UNASSIGN) {
                $holder = $task->technician?->name ?? 'A technician';

                $task->update(['technician_id' => null, 'status' => 'unassigned']);
                $unassigned->push(['task' => $task, 'holder' => $holder]);

                continue;
            }

            $previous = $task->technician?->account;

            $task->update(['technician_id' => (int) $choice]);
            $task->unsetRelation('technician');

            $reassigned->push(['task' => $task, 'previous' => $previous]);
        }

        return ['unassigned' => $unassigned, 'reassigned' => $reassigned, 'kept' => $kept];
    }

    /**
     * The stranded tasks as the dialogs draw them: each task, why it is
     * stranded, and who could take it over.
     *
     * @param  Collection<int, array<string, mixed>>  $conflicts
     * @return array<int, array<string, mixed>>
     */
    public function conflictsPayload(Collection $conflicts): array
    {
        return $conflicts
            ->map(fn (array $conflict): array => [
                'task_id' => $conflict['task']->task_id,
                'title' => $conflict['task']->task_title,
                'holder' => $conflict['task']->technician?->name,
                'dates' => $conflict['task']->start_date && $conflict['task']->due_date
                    ? CarbonImmutable::parse($conflict['task']->start_date)->format(BusinessTime::DATE)
                        .' - '.CarbonImmutable::parse($conflict['task']->due_date)->format(BusinessTime::DATE)
                    : 'No dates set',
                'reason' => $conflict['reason'],
                'can_keep' => $conflict['can_keep'],
                'options' => $conflict['options']
                    ->map(fn (Technician $technician): array => [
                        'technician_id' => $technician->technician_id,
                        'name' => $technician->name,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Assert that everybody on a project's team, now or due to join it, is free
     * for the given ranges - each technician over the days their own span holds.
     *
     * Somebody leaving on Aug 21 is not refused for a range in September, and
     * somebody starting on Sep 1 is not refused for August. The ranges are the
     * caller's, already narrowed to the part still to be worked.
     *
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode: string}>  $ranges
     *
     * @throws RuntimeException
     */
    public function assertTeamAvailableFor(Project $project, array $ranges, array $excludeScheduleIds = []): void
    {
        if ($ranges === []) {
            return;
        }

        $today = ProjectTechnician::today();

        $conflicts = ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->notEnded()
            ->get()
            ->reject(fn (ProjectTechnician $span): bool => $span->isEmptySpan())
            ->flatMap(function (ProjectTechnician $span) use ($project, $ranges, $today, $excludeScheduleIds): Collection {
                $from = $span->startDate() !== null && $span->startDate() > $today ? $span->startDate() : $today;
                $within = $this->rangesWithin($ranges, $from, $span->endDate());

                return $within === []
                    ? collect()
                    : $this->availability->findConflicts(
                        [(int) $span->technician_id],
                        $within,
                        (int) $project->project_id,
                        $excludeScheduleIds
                    );
            });

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException($this->availability->conflictMessage($conflicts->values()));
        }
    }

    /**
     * The lead on a given day among some spans.
     *
     * @param  Collection<int, ProjectTechnician>  $spans
     */
    public function leadOnDay(Collection $spans, string $day): ?ProjectTechnician
    {
        return $spans->first(fn (ProjectTechnician $span): bool => $span->isCurrent($day)
            && (bool) $span->technician?->isLead());
    }

    /**
     * Tell everybody a saved change concerns.
     *
     * Notifications go out after the transaction commits - see
     * NotificationService::deliver(). A change still to come says the day it
     * takes effect in every message, so nobody reads a scheduled removal as
     * having happened.
     *
     * @param  array{unassigned: Collection<int, array{task: Task, holder: string}>, reassigned: Collection<int, array{task: Task, previous: ?User}>, kept: Collection<int, Task>}  $outcome
     */
    public function notify(ProjectTeamChangePlan $plan, array $outcome): void
    {
        $notifications = app(NotificationService::class);
        $project = $plan->project;
        $effective = $plan->isImmediate() ? null : $plan->effective;

        $project->unsetRelation('projectTechnicians');
        $project->unsetRelation('rosterTechnicians');

        $account = fn (?int $id) => $id ? Technician::query()->with('account')->find($id)?->account : null;

        if ($plan->kind === ProjectTeamChangePlan::KIND_DAYS_OFF) {
            $subject = $account($plan->subjectId);

            if ($subject) {
                $notifications->technicianDaysOffScheduled($project, $subject, $plan->effective, $plan->lastDayOff());
            }

            if ($cover = $account($plan->leadCoverId)) {
                $notifications->standInLeadScheduled(
                    $project,
                    $cover,
                    $plan->effective,
                    $plan->lastDayOff(),
                    $subject?->fullName() ?? 'the lead technician'
                );
            }
        } else {
            $this->notifyTeamChange($notifications, $plan, $effective);
        }

        // Work the departing technicians were holding does not leave with them
        // unless somebody said so, and whoever runs the project is told what
        // became of it.
        $outcome['unassigned']
            ->groupBy('holder')
            ->each(fn (Collection $released, string $holder) => $notifications->tasksUnassignedByTeamChange(
                $project,
                $holder,
                $released->pluck('task')
            ));

        foreach ($outcome['reassigned'] as $reassigned) {
            $notifications->taskReassigned($reassigned['task']->fresh('technician.account'), $reassigned['previous']);
        }
    }

    /**
     * The notices for a change that takes somebody on or off the team.
     */
    private function notifyTeamChange(NotificationService $notifications, ProjectTeamChangePlan $plan, ?CarbonImmutable $effective): void
    {
        $project = $plan->project;

        $leadBefore = $this->leadOnDay($plan->before, $plan->effectiveDate());
        $leadAfter = $this->leadOnDay($plan->after, $plan->effectiveDate());
        $leadBeforeId = $leadBefore ? (int) $leadBefore->technician_id : null;
        $leadAfterId = $leadAfter ? (int) $leadAfter->technician_id : null;

        $accounts = fn (Collection $ids): Collection => Technician::query()
            ->with('account')
            ->whereIn('technician_id', $ids->all())
            ->get()
            ->map(fn (Technician $technician) => $technician->account)
            ->filter()
            ->values();

        if ($leadAfterId !== null && $leadAfterId !== $leadBeforeId && $leadAfter->technician?->account) {
            $notifications->leadAssignedToProject($project, $leadAfter->technician->account, $effective);
        }

        if ($leadBeforeId !== null && $leadAfterId !== $leadBeforeId && $leadBefore->technician?->account) {
            $notifications->leadRemovedFromProject($project, $leadBefore->technician->account, $effective);
        }

        $notifications->techniciansAssignedToProject(
            $project,
            $accounts($plan->addedIds()->reject(fn (int $id): bool => $id === $leadAfterId)),
            $effective
        );

        $notifications->techniciansRemovedFromProject(
            $project,
            $accounts($plan->removedIds()->reject(fn (int $id): bool => $id === $leadBeforeId)),
            $effective
        );
    }

    // ------------------------------------------------------------------
    // Calling a scheduled change off
    // ------------------------------------------------------------------

    /**
     * Cancel a change that has not happened yet.
     *
     * What a cancel means depends on which span it is pressed on:
     *
     *   a scheduled removal   the technician stays. If the removal was the
     *                         start of days off, their return is folded back
     *                         into the span, so it runs on unbroken.
     *   a scheduled start     it does not happen. If it is a technician's
     *                         return from days off, they do not come back.
     *
     * A lead's change comes with somebody leading in their place - a
     * replacement, or a stand-in for their days off - and cancelling one half
     * alone would leave the project with two leads or none. So the other half
     * goes with it, and the caller is told everything that was undone.
     *
     * Refused, with the reason, when putting things back would break a rule:
     * a lead on every day, a technician booked elsewhere for the days they
     * would be given back, or a start that already has work dated to it.
     *
     * $removalOnly cancels just the removal at the end of a span still to come
     * (a return or a start that is itself scheduled to end), keeping the start.
     *
     * @return array{sentences: array<int, string>, notices: array<int, array{technician: ?Technician, what: string}>}
     *
     * @throws RuntimeException
     */
    public function cancelScheduled(Project $project, ProjectTechnician $span, bool $removalOnly = false): array
    {
        $spans = $this->spansOf($project);
        $span = $spans->first(fn (ProjectTechnician $other): bool => $other->is($span));

        if ($span === null || (! $span->isUpcoming() && ! $span->isLeaving())) {
            throw new RuntimeException('Nothing is scheduled for this technician to cancel.');
        }

        // Only the removal at the end of a span still to come - its start
        // stays as it is.
        if ($removalOnly && ($span->isLeaving() || $project->scheduledEndLabel($span) === null)) {
            throw new RuntimeException('Nothing is scheduled for this technician to cancel.');
        }

        $isLead = fn (ProjectTechnician $other): bool => (bool) $other->technician?->isLead();
        $sameTechnician = fn (ProjectTechnician $a, ProjectTechnician $b): bool => ! $a->is($b)
            && (int) $a->technician_id === (int) $b->technician_id;

        /** @var Collection<int, ProjectTechnician> $deletes */
        $deletes = collect();
        /** @var array<int, array{span: ProjectTechnician, until: ?string}> $reopens */
        $reopens = [];
        $sentences = [];
        $notices = [];

        // Fold a leaving span back together with the return after it, if any,
        // and take away whoever was covering the lead in between.
        $undoRemoval = function (ProjectTechnician $leaving) use ($spans, $isLead, $sameTechnician, &$deletes, &$reopens, &$sentences, &$notices): void {
            $return = $spans
                ->filter(fn (ProjectTechnician $other): bool => $sameTechnician($other, $leaving)
                    && $other->startDate() !== null
                    && $other->startDate() >= $leaving->endDate())
                ->sortBy(fn (ProjectTechnician $other): string => (string) $other->startDate())
                ->first();

            $reopens[] = ['span' => $leaving, 'until' => $return?->endDate()];

            if ($return) {
                $deletes->push($return);
            }

            $name = $leaving->technician?->name ?? 'A technician';
            $sentences[] = $return ? $name.' is no longer taking those days off' : $name.' will stay on the team';
            $notices[] = ['technician' => $leaving->technician, 'what' => $return ? 'days off' : 'removal'];

            if ($isLead($leaving)) {
                $cover = $spans->first(fn (ProjectTechnician $other): bool => ! $sameTechnician($other, $leaving)
                    && ! $other->is($leaving)
                    && $isLead($other)
                    && $other->isUpcoming()
                    && $other->startDate() === $leaving->endDate());

                if ($cover) {
                    $deletes->push($cover);
                    $sentences[] = ($cover->technician?->name ?? 'The other lead').' will no longer lead in their place';
                    $notices[] = ['technician' => $cover->technician, 'what' => 'start'];
                }
            }
        };

        if ($span->isLeaving() || $removalOnly) {
            $undoRemoval($span);
        } else {
            $deletes->push($span);

            $earlier = $spans->first(fn (ProjectTechnician $other): bool => $sameTechnician($other, $span)
                && $other->endDate() !== null
                && $other->endDate() < (string) $span->startDate());

            $name = $span->technician?->name ?? 'A technician';

            if ($earlier) {
                $sentences[] = $name.' will not come back to the project';
                $notices[] = ['technician' => $span->technician, 'what' => 'return'];
            } else {
                $sentences[] = $name.' will no longer join';
                $notices[] = ['technician' => $span->technician, 'what' => 'start'];

                // A lead starting the day another lead leaves is covering for
                // them: the leaving lead's change is undone with it.
                if ($isLead($span)) {
                    $covered = $spans->first(fn (ProjectTechnician $other): bool => ! $other->is($span)
                        && $isLead($other)
                        && $other->isLeaving()
                        && $other->endDate() === $span->startDate());

                    if ($covered) {
                        $deletes = $deletes->reject(fn (ProjectTechnician $other): bool => $other->is($span))->values();
                        $sentences = [];
                        $notices = [];
                        $undoRemoval($covered);
                    }
                }
            }
        }

        $after = $spans
            ->reject(fn (ProjectTechnician $other): bool => $deletes->contains(fn (ProjectTechnician $gone): bool => $gone->is($other)))
            ->map(function (ProjectTechnician $other) use ($reopens): ProjectTechnician {
                $copy = clone $other;

                foreach ($reopens as $reopen) {
                    if ($reopen['span']->is($other)) {
                        $copy->removed_at = $reopen['until'];
                    }
                }

                return $copy;
            })
            ->values();

        $from = collect($deletes->map(fn (ProjectTechnician $gone): ?string => $gone->startDate()))
            ->merge(collect($reopens)->map(fn (array $reopen): ?string => $reopen['span']->endDate()))
            ->filter()
            ->min();

        $existing = collect($this->leadProblems($spans, $from))->pluck('key')->all();

        $problem = collect($this->leadProblems($after, $from))
            ->reject(fn (array $candidate): bool => in_array($candidate['key'], $existing, true))
            ->first();

        if ($problem) {
            throw new RuntimeException($problem['message']);
        }

        foreach ($deletes as $gone) {
            $this->assertHoldsNoWorkForItsTime($project, $gone, $after);
        }

        foreach ($reopens as $reopen) {
            $this->assertFreeAfterRemoval($project, $reopen['span'], $reopen['until']);
        }

        foreach ($deletes as $gone) {
            $this->team->cancelStart($gone);
        }

        foreach ($reopens as $reopen) {
            $this->team->cancelRemoval($project, $reopen['span'], $reopen['until']);
        }

        return ['sentences' => $sentences, 'notices' => $notices];
    }

    /**
     * Cancel everything a project still has scheduled for after a given day -
     * what completing or cancelling a project does, so a finished job keeps the
     * team it actually ended with rather than changing weeks after it stopped.
     *
     * No rules are applied: the project is closing, there are no days left to
     * cover, and nobody is being given new work.
     *
     * @return array{removals: Collection<int, ProjectTechnician>, starts: Collection<int, ProjectTechnician>}
     */
    public function cancelScheduledAfterClosing(Project $project): array
    {
        $spans = ProjectTechnician::query()
            ->with('technician.account')
            ->where('project_id', $project->project_id)
            ->get()
            ->reject(fn (ProjectTechnician $span): bool => $span->isEmptySpan());

        $starts = $spans->filter(fn (ProjectTechnician $span): bool => $span->isUpcoming())->values();
        $removals = $spans->filter(fn (ProjectTechnician $span): bool => $span->isLeaving())->values();

        foreach ($starts as $start) {
            $this->team->cancelStart($start);
        }

        foreach ($removals as $removal) {
            // The bookings are not written back: a closed project occupies
            // nobody, and ProjectCompletion has already released its dates.
            $removal->update(['removed_at' => null, 'removed_by' => null, 'removal_recorded_at' => null]);
        }

        return ['removals' => $removals, 'starts' => $starts];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Every day from $from onwards that would not have exactly one lead, as
     * keyed problems - the key lets a caller tell a problem a change creates
     * from one the project already had.
     *
     * Only the days where something changes are looked at: $from itself, and
     * every start and removal after it. Between two of those the team is the
     * same every day, so the answer is too.
     *
     * @param  Collection<int, ProjectTechnician>  $spans
     * @return array<int, array{key: string, message: string}>
     */
    private function leadProblems(Collection $spans, ?string $from): array
    {
        if ($from === null) {
            return [];
        }

        $spans = $spans
            ->reject(fn (ProjectTechnician $span): bool => $span->isEmptySpan())
            ->filter(fn (ProjectTechnician $span): bool => $span->endDate() === null || $span->endDate() > $from)
            ->values();

        $isLead = fn (ProjectTechnician $span): bool => (bool) $span->technician?->isLead();

        $days = collect([$from])
            ->merge($spans->map(fn (ProjectTechnician $span): ?string => $span->startDate()))
            ->merge($spans->map(fn (ProjectTechnician $span): ?string => $span->endDate()))
            ->filter(fn (?string $day): bool => $day !== null && $day >= $from)
            ->unique()
            ->sort()
            ->values();

        $problems = [];

        foreach ($days as $day) {
            $leads = $spans->filter(fn (ProjectTechnician $span): bool => $span->coveredOn($day) && $isLead($span))->values();

            if ($leads->count() === 1) {
                continue;
            }

            if ($leads->isEmpty()) {
                $outgoing = $spans->first(fn (ProjectTechnician $span): bool => $span->endDate() === $day && $isLead($span));

                $problems[] = [
                    'key' => 'none|'.$day,
                    'message' => $outgoing
                        ? sprintf(
                            '%s stops leading this project on %s. Choose a lead technician who takes over that day.',
                            $outgoing->technician?->name ?? 'The lead technician',
                            BusinessTime::format($day)
                        )
                        : sprintf(
                            'This project would have no lead technician from %s. Choose one who takes over that day.',
                            BusinessTime::format($day)
                        ),
                ];

                continue;
            }

            $scheduled = $leads->first(fn (ProjectTechnician $span): bool => $span->exists && $span->isUpcoming());

            $problems[] = [
                'key' => 'many|'.$day,
                'message' => sprintf(
                    '%s would both lead this project from %s. A project has one lead technician%s.',
                    $leads->map(fn (ProjectTechnician $span): string => $span->technician?->name ?? 'A lead technician')->join(', ', ' and '),
                    BusinessTime::format($day),
                    $scheduled ? ' - cancel '.($scheduled->technician?->name ?? 'the other lead')."'s scheduled start first" : ''
                ),
            ];
        }

        return $problems;
    }

    /**
     * A task's dates as 'Y-m-d', or nulls.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function taskDates(Task $task): array
    {
        $day = fn ($value): ?string => $value ? CarbonImmutable::parse($value)->toDateString() : null;

        return [$day($task->start_date), $day($task->due_date)];
    }

    /**
     * Whether these spans let their technician hold a task over these dates -
     * the same strict rule TaskAssignmentRules::periodRefusal() applies.
     *
     * @param  Collection<int, ProjectTechnician>  $spans
     */
    private function holds(Collection $spans, ?string $start, ?string $due): bool
    {
        $spans = $spans->reject(fn (ProjectTechnician $span): bool => $span->isEmptySpan());

        if ($start === null || $due === null) {
            return $spans->contains(fn (ProjectTechnician $span): bool => $span->endDate() === null);
        }

        return $spans->contains(fn (ProjectTechnician $span): bool => $span->coversPeriod($start, $due));
    }

    /**
     * The availability ranges narrowed to the days between $from and $until
     * (exclusive; null runs on). A whole-day range is cut to fit; a partial day
     * is kept whole or dropped, because it is a single date.
     *
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode: string}>  $ranges
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode: string}>
     */
    public function rangesWithin(array $ranges, string $from, ?string $until): array
    {
        $first = CarbonImmutable::parse($from)->startOfDay();
        $last = $until === null ? null : CarbonImmutable::parse($until)->startOfDay()->subDay();

        $within = [];

        foreach ($ranges as $range) {
            if ($range['mode'] === Schedule::MODE_PARTIAL_DAY) {
                $day = $range['start']->startOfDay();

                if ($day->gte($first) && ($last === null || $day->lte($last))) {
                    $within[] = $range;
                }

                continue;
            }

            $start = $range['start']->lt($first) ? $first : $range['start'];
            $end = $last !== null && $range['end']->gt($last) ? $last : $range['end'];

            if ($start->lte($end)) {
                $within[] = ['start' => $start, 'end' => $end, 'mode' => $range['mode']];
            }
        }

        return $within;
    }

    /**
     * A scheduled start cannot be called off out from under work already
     * dated to it.
     *
     * @param  Collection<int, ProjectTechnician>  $spans
     */
    private function assertHoldsNoWorkForItsTime(Project $project, ProjectTechnician $start, Collection $remaining): void
    {
        $others = $remaining->filter(fn (ProjectTechnician $other): bool => (int) $other->technician_id === (int) $start->technician_id
            && ! $other->is($start));

        $stranded = Task::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $start->technician_id)
            ->whereIn('status', Task::OPEN_STATUSES)
            ->get()
            ->filter(function (Task $task) use ($start, $others): bool {
                [$taskStart, $taskDue] = $this->taskDates($task);

                return $this->holds(collect([$start]), $taskStart, $taskDue)
                    && ! $this->holds($others, $taskStart, $taskDue);
            });

        if ($stranded->isEmpty()) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s already holds %s dated to their time on this project (%s). Reassign %s before cancelling.',
            $start->technician?->name ?? 'This technician',
            $stranded->count() === 1 ? 'a task' : $stranded->count().' tasks',
            $this->quotedList($stranded->pluck('task_title')->all()),
            $stranded->count() === 1 ? 'it' : 'them'
        ));
    }

    /**
     * Giving a leaving technician back the days after their removal is only
     * possible while they are still free for them.
     */
    private function assertFreeAfterRemoval(Project $project, ProjectTechnician $removal, ?string $until = null): void
    {
        $ranges = $this->rangesWithin(
            Schedule::upcomingAvailabilityRanges($project->schedules()->get()),
            (string) $removal->endDate(),
            $until
        );

        if ($ranges === []) {
            return;
        }

        $conflicts = $this->availability->findConflicts([(int) $removal->technician_id], $ranges, (int) $project->project_id);

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException($this->availability->conflictMessage($conflicts));
        }
    }

    /**
     * "A", "A" and "B", "A", "B" and "C" - titles as a sentence reads them.
     *
     * @param  array<int, string>  $titles
     */
    private function quotedList(array $titles): string
    {
        return collect($titles)->map(fn (string $title): string => '"'.$title.'"')->join(', ', ' and ');
    }
}
