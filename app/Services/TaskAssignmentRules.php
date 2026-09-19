<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Who a task may be given to.
 *
 * Being on the project's team is the first half of the question and every task
 * form has always asked it. This is the second half: the person has to be able
 * to receive the work. An account that has been deactivated or archived cannot
 * sign in, so it cannot open the project, cannot close the task, and cannot
 * read the notification saying it has one - handing it a job creates work that
 * nobody is going to do and nobody has been told about.
 *
 * A deactivated technician deliberately stays ON the team, because taking
 * somebody off a project releases their tasks and is a decision for a person
 * to make rather than a side effect of an account being switched off - see
 * ProjectTeamRules, which draws the same line for teams. So they go on being
 * listed everywhere the crew is listed. What changes is only that they can no
 * longer be handed anything new.
 *
 * The one exception is the task they are already holding. Editing its title or
 * its dates re-submits whoever owns it, and refusing that would make an
 * inactive technician's tasks uneditable - which is the opposite of what is
 * wanted, since moving that work to somebody else is exactly what a lead is
 * being asked to do. Keeping the current owner is therefore always allowed;
 * only a change TO an inactive technician is refused.
 *
 * Extracted for the same reason TaskScheduleRules was: the Super Admin board
 * and the technician portal both assign tasks, and a rule enforced by one and
 * not the other is the shape almost every bug in this area has taken.
 */
class TaskAssignmentRules
{
    /**
     * Whether this technician may be handed a task now.
     */
    public function canReceiveWork(?Technician $technician): bool
    {
        return $technician !== null && $technician->isAssignable();
    }

    /**
     * Why they cannot, in the words the administrator who switched the account
     * off would recognise.
     */
    public function refusal(Technician $technician): string
    {
        $account = $technician->account;

        $reason = match (true) {
            $account === null => 'has no account',
            (bool) $account->is_archived => 'has been archived',
            ! $account->isActive() => 'has been deactivated',
            default => 'can no longer be used',
        };

        return sprintf(
            "%s's account %s and cannot be given tasks.",
            $technician->name,
            $reason
        );
    }

    /**
     * Why this technician cannot hold a task over these dates on this project,
     * or null when they can.
     *
     * The rule is strict: ONE span of theirs must hold every day from the
     * task's start to its due date - see ProjectTechnician::coversPeriod(). A
     * task with no dates can only sit with somebody whose membership has no end
     * scheduled, because nothing else says the work falls inside their time on
     * the project.
     *
     * The reasons are told apart, because each sends the reader to a different
     * fix: somebody who leaves before the deadline, somebody who has not
     * joined yet, somebody who is off the project part of the way through.
     *
     * @param  Collection<int, ProjectTechnician>|null  $spans  this technician's
     *                                                          spans on the
     *                                                          project, when the
     *                                                          caller has them -
     *                                                          including spans a
     *                                                          planned change
     *                                                          has not written
     *                                                          yet
     */
    public function periodRefusal(
        Technician $technician,
        int $projectId,
        ?string $start,
        ?string $due,
        ?Collection $spans = null
    ): ?string {
        $spans = ($spans ?? ProjectTechnician::query()
            ->where('project_id', $projectId)
            ->where('technician_id', $technician->technician_id)
            ->get())
            ->reject(fn (ProjectTechnician $span): bool => $span->isEmptySpan())
            ->values();

        $name = $technician->name;

        if ($spans->isEmpty()) {
            return 'Pick a technician who is assigned to this project.';
        }

        if ($start === null || $due === null) {
            return $spans->contains(fn (ProjectTechnician $span): bool => $span->endDate() === null)
                ? null
                : sprintf('%s is not assigned to this project with no end date, so this task needs dates first.', $name);
        }

        if ($spans->contains(fn (ProjectTechnician $span): bool => $span->coversPeriod($start, $due))) {
            return null;
        }

        $holdingStart = $spans->first(fn (ProjectTechnician $span): bool => $span->coveredOn($start));
        $holdingDue = $spans->first(fn (ProjectTechnician $span): bool => $span->coveredOn($due));

        if ($holdingStart && $holdingDue) {
            return sprintf(
                '%s is off this project from %s to %s, which this task runs across.',
                $name,
                BusinessTime::format($holdingStart->endDate()),
                BusinessTime::format(CarbonImmutable::parse($holdingDue->startDate())->subDay())
            );
        }

        // Days off at one end of the task: say so, rather than calling the
        // return a join or the break an end.
        $offUntil = fn (ProjectTechnician $span): ?ProjectTechnician => $spans
            ->filter(fn (ProjectTechnician $later): bool => $span->endDate() !== null && $later->startDate() > $span->endDate())
            ->sortBy(fn (ProjectTechnician $later): string => $later->startDate())
            ->first();

        if ($holdingStart && ($return = $offUntil($holdingStart))) {
            return sprintf(
                '%s is off this project from %s to %s, and this task is due %s.',
                $name,
                BusinessTime::format($holdingStart->endDate()),
                BusinessTime::format(CarbonImmutable::parse($return->startDate())->subDay()),
                BusinessTime::format($due)
            );
        }

        $breakBefore = $holdingDue
            ? $spans
                ->filter(fn (ProjectTechnician $earlier): bool => $earlier->endDate() !== null && $earlier->endDate() < $holdingDue->startDate())
                ->sortByDesc(fn (ProjectTechnician $earlier): string => $earlier->endDate())
                ->first()
            : null;

        if ($holdingDue && $breakBefore) {
            return sprintf(
                '%s is off this project from %s to %s, and this task starts %s.',
                $name,
                BusinessTime::format($breakBefore->endDate()),
                BusinessTime::format(CarbonImmutable::parse($holdingDue->startDate())->subDay()),
                BusinessTime::format($start)
            );
        }

        if ($holdingStart) {
            return sprintf(
                '%s is assigned to this project until %s, and this task is due %s.',
                $name,
                BusinessTime::format($holdingStart->lastDay()),
                BusinessTime::format($due)
            );
        }

        if ($holdingDue) {
            return sprintf(
                '%s joins this project on %s, and this task starts %s.',
                $name,
                BusinessTime::format($holdingDue->startDate()),
                BusinessTime::format($start)
            );
        }

        // Wholly after their time on the project ends, with no return.
        $ended = $spans->every(fn (ProjectTechnician $span): bool => $span->endDate() !== null && $span->endDate() <= $start)
            ? $spans->sortByDesc(fn (ProjectTechnician $span): string => $span->endDate())->first()
            : null;

        if ($ended) {
            return sprintf(
                '%s is assigned to this project until %s, and this task starts %s.',
                $name,
                BusinessTime::format($ended->lastDay()),
                BusinessTime::format($start)
            );
        }

        return sprintf(
            '%s is not assigned to this project between %s and %s.',
            $name,
            BusinessTime::format($start),
            BusinessTime::format($due)
        );
    }

    /**
     * Add the assignment-period check to a task form's validator.
     *
     * Runs after the dates' own rules, so a task with a bad or unbooked date is
     * told about the date once rather than about the technician as well. The
     * refusal goes on the technician field: changing who holds the task and
     * changing its dates are both ways out, and the reason names the dates.
     *
     * An edit that leaves both the holder and the dates exactly as they were
     * is never refused. A task whose holder has since been taken off the
     * project stays editable - its wording, its phase - and the refusal
     * applies the moment either the person or the dates are changed.
     */
    public function attachPeriodRule(
        Validator $validator,
        Project $project,
        ?Task $task = null,
        string $key = 'technician_id'
    ): void {
        $validator->after(function (Validator $validator) use ($project, $task, $key): void {
            if ($validator->errors()->hasAny([$key, 'start_date', 'due_date'])) {
                return;
            }

            $data = $validator->getData();
            $technicianId = (int) ($data[$key] ?? 0);
            $start = $this->dateOrNull($data['start_date'] ?? null);
            $due = $this->dateOrNull($data['due_date'] ?? null);

            if ($technicianId === 0) {
                return;
            }

            if ($task !== null
                && $technicianId === (int) $task->technician_id
                && $start === $this->dateOrNull($task->start_date)
                && $due === $this->dateOrNull($task->due_date)) {
                // Keeping the holder is allowed only while they are still on
                // the project. Somebody taken off it altogether can no longer
                // close the task, so an edit hands it to somebody who can.
                if ($task->holderRemovedFromProject()) {
                    $validator->errors()->add($key, sprintf(
                        '%s was removed from this project. Assign this task to a technician on the team.',
                        $task->technician?->name ?? 'This technician'
                    ));
                }

                return;
            }

            $technician = Technician::query()->with('account')->find($technicianId);

            if ($technician === null) {
                return;
            }

            $refusal = $this->periodRefusal($technician, (int) $project->project_id, $start, $due);

            if ($refusal !== null) {
                $validator->errors()->add($key, $refusal);
            }
        });
    }

    /**
     * Every span each of these technicians has held on the project, as the
     * pickers hand them to the browser: [{start, end}] with `end` the LAST day
     * covered, inclusive, or null while nothing ends it.
     *
     * The browser uses these to grey out the days a chosen technician is not
     * assigned for, and to switch off the technicians who cannot hold the dates
     * already picked. The server applies the same rule on the way back in.
     *
     * @param  iterable<int, int>  $technicianIds
     * @return array<int, array<int, array{start: ?string, end: ?string}>>
     */
    public function periodsFor(int $projectId, iterable $technicianIds): array
    {
        return ProjectTechnician::query()
            ->where('project_id', $projectId)
            ->whereIn('technician_id', collect($technicianIds)->map(fn ($id): int => (int) $id)->all())
            ->orderBy('joined_at')
            ->get()
            ->reject(fn (ProjectTechnician $span): bool => $span->isEmptySpan())
            ->groupBy('technician_id')
            ->map(fn (Collection $spans): array => $spans
                ->map(fn (ProjectTechnician $span): array => [
                    'start' => $span->startDate(),
                    'end' => $span->lastDay()?->toDateString(),
                ])
                ->values()
                ->all())
            ->all();
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Add the check to a task form's validator.
     *
     * Runs after the field's own rules, so a submission with no technician at
     * all - or one who is not on the project - is complained about once, by
     * the rule that actually owns that complaint.
     *
     * @param  int|null  $currentTechnicianId  who holds the task already, so an
     *                                         edit that leaves the owner alone is
     *                                         not refused for their account
     */
    public function attach(
        Validator $validator,
        ?int $currentTechnicianId = null,
        string $key = 'technician_id'
    ): void {
        $validator->after(function (Validator $validator) use ($currentTechnicianId, $key): void {
            if ($validator->errors()->has($key)) {
                return;
            }

            $technicianId = (int) ($validator->getData()[$key] ?? 0);

            if ($technicianId === 0 || $technicianId === $currentTechnicianId) {
                return;
            }

            $technician = Technician::query()
                ->with('account')
                ->find($technicianId);

            // A missing record is the `exists` rule's complaint to make, not
            // this one's - saying it twice only crowds the form.
            if ($technician === null || $this->canReceiveWork($technician)) {
                return;
            }

            $validator->errors()->add($key, $this->refusal($technician));
        });
    }
}
