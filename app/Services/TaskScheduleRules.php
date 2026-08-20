<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * The "a task must start and finish on a day the project is booked" rule.
 *
 * A project can hold several date ranges with gaps between them - booked
 * Aug 10-15 and Aug 25-30, say. A task may SPAN such a gap: start Aug 14,
 * finish Aug 26. A task is a piece of work with a deadline, not a claim on a
 * day, and "start when we arrive, finish before we leave" has to be
 * expressible on a project that runs in two visits.
 *
 * What it may not do is BEGIN or END on a day nobody is booked. Aug 20 is not
 * a day this project exists on: nobody is on site, so a task cannot sensibly
 * start then, and a deadline that falls then is a deadline on a day no work
 * can be done. Both endpoints therefore have to land inside one of the ranges;
 * everything between them is free to be gap.
 *
 *   booked   Aug 10-15        Aug 25-30
 *   Aug 14 -> Aug 26          allowed   - spans the gap, both ends booked
 *   Aug 12 -> Aug 14          allowed   - inside one range
 *   Aug 20 -> Aug 26          refused   - starts on an unbooked day
 *   Aug 14 -> Aug 20          refused   - ends on an unbooked day
 *
 * Extracted from TaskController so the technician portal enforces exactly the
 * same thing rather than a second, drifting copy of it. ScheduleController
 * re-checks the same rule after a reschedule, and after a date is removed.
 */
class TaskScheduleRules
{
    /**
     * Every date range on a project's schedule, as 'Y-m-d' pairs.
     *
     * Still the ranges themselves rather than the period they span: this is
     * what the screens show a person, and "booked Aug 10-15 and Aug 25-30" is
     * more use to them than the outer bound alone.
     *
     * @return array<int, array{start: string, end: string}>
     */
    public function ranges(int $projectId): array
    {
        return Schedule::query()
            ->where('project_id', $projectId)
            ->orderBy('start_datetime')
            ->get(['start_datetime', 'end_datetime'])
            ->map(fn (Schedule $schedule): array => [
                'start' => Carbon::parse($schedule->start_datetime)->format('Y-m-d'),
                'end' => Carbon::parse($schedule->end_datetime ?? $schedule->start_datetime)->format('Y-m-d'),
            ])
            ->values()
            ->all();
    }

    /**
     * The period the project is scheduled over: its earliest booked date and
     * its latest, gaps included.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     * @return array{start: string, end: string}|null null when nothing is booked
     */
    public function window(array $ranges): ?array
    {
        if ($ranges === []) {
            return null;
        }

        return [
            'start' => collect($ranges)->min(fn (array $range): string => $range['start']),
            'end' => collect($ranges)->max(fn (array $range): string => $range['end']),
        ];
    }

    /**
     * Whether one date falls on a day the project is actually booked.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     */
    public function isBookedDate(array $ranges, ?string $date): bool
    {
        if (! $date) {
            return false;
        }

        foreach ($ranges as $range) {
            if ($date >= $range['start'] && $date <= $range['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a task's dates are allowed: both endpoints on booked days, and
     * finishing no earlier than it starts.
     *
     * The days in between are deliberately not checked - that is what lets a
     * task span the gap between two visits.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     */
    public function windowCovers(array $ranges, ?string $startDate, ?string $dueDate): bool
    {
        if (! $startDate || ! $dueDate || $ranges === []) {
            return false;
        }

        if ($dueDate < $startDate) {
            return false;
        }

        return $this->isBookedDate($ranges, $startDate)
            && $this->isBookedDate($ranges, $dueDate);
    }

    /**
     * Take the dates off every task the project's current schedule no longer
     * covers, and hand back the ones that lost them.
     *
     * A task's dates only mean anything while the project is booked on them.
     * The moment the schedule stops covering a task's start or its deadline,
     * that task is pointing at days nobody is on site - so it goes back to
     * Unassigned rather than keeping a date the task form itself would now
     * refuse. Whoever re-schedules the project gives it a new one.
     *
     * The test is windowCovers(), the same one the task forms validate
     * against, and the ranges are read from the database rather than passed
     * in - this always measures tasks against what the project actually holds
     * now, never against what a caller believed it was about to hold.
     *
     * Every caller that changes a project's dates goes through here: editing a
     * schedule, removing a single date, and putting the project on hold. They
     * used to have an answer each - the hold blanked every open task outright,
     * which threw away dates that were still perfectly valid inside the days
     * it kept.
     *
     * @return Collection<int, Task> the tasks whose dates were cleared, so a
     *                               caller can say so rather than leave the
     *                               work to be noticed missing
     */
    public function unassignStrandedDates(int $projectId): Collection
    {
        $ranges = $this->ranges($projectId);

        return Task::query()
            ->where('project_id', $projectId)
            ->whereNotNull('start_date')
            ->whereNotNull('due_date')
            ->get()
            ->filter(function (Task $task) use ($ranges): bool {
                $stillCovered = $this->windowCovers(
                    $ranges,
                    Carbon::parse($task->start_date)->toDateString(),
                    Carbon::parse($task->due_date)->toDateString()
                );

                if ($stillCovered) {
                    return false;
                }

                $task->update([
                    'start_date' => null,
                    'due_date' => null,
                ]);

                return true;
            })
            ->values();
    }

    /**
     * Human-readable list of the booked ranges, for the form hints that sit
     * under the date pickers.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     */
    public function describe(array $ranges): string
    {
        return collect($ranges)
            ->map(fn (array $range): string => Carbon::parse($range['start'])->format('M j, Y')
                .' - '
                .Carbon::parse($range['end'])->format('M j, Y'))
            ->join('; ');
    }

    /**
     * "Aug 10, 2026 - Aug 30, 2026" - the period a task may be given dates in,
     * which is what a validation message has to talk about.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     */
    public function describeWindow(array $ranges): string
    {
        $window = $this->window($ranges);

        if (! $window) {
            return '';
        }

        return Carbon::parse($window['start'])->format('M j, Y')
            .' - '
            .Carbon::parse($window['end'])->format('M j, Y');
    }

    /**
     * The hint under the date pickers, in one place so the two portals and the
     * Tasks page cannot describe the same rule three different ways.
     *
     * A project booked in one stretch needs no explanation - the picker simply
     * offers those days. One booked in several does: the gap days are greyed
     * out, and somebody who wants a task running across a gap has to be told
     * that is allowed rather than left assuming it is not.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     */
    public function describeSelectable(array $ranges): string
    {
        if ($ranges === []) {
            return 'No schedule set, so this project cannot take dated tasks yet.';
        }

        $booked = 'Booked: '.$this->describe($ranges).'.';

        if (count($ranges) === 1) {
            return $booked;
        }

        return $booked.' A task must start and be due on a booked day, but may run across the gap between them.';
    }

    /**
     * Add the "starts and finishes on a booked day" check to a validator.
     *
     * The two endpoints are reported separately, so somebody who picked a good
     * start and a bad deadline is told which of the two to change rather than
     * being handed one message about both.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     */
    public function attach(Validator $validator, array $ranges): void
    {
        $validator->after(function (Validator $validator) use ($ranges): void {
            if ($validator->errors()->hasAny(['start_date', 'due_date'])) {
                return;
            }

            $data = $validator->getData();
            $startDate = $data['start_date'] ?? null;
            $dueDate = $data['due_date'] ?? null;

            if (! $startDate || ! $dueDate) {
                return;
            }

            if ($ranges === []) {
                $message = 'This project has no scheduled dates, so a task cannot be given any.';

                $validator->errors()->add('start_date', $message);
                $validator->errors()->add('due_date', $message);

                return;
            }

            $booked = 'The project is booked '.$this->describe($ranges).'.';

            if (! $this->isBookedDate($ranges, $startDate)) {
                $validator->errors()->add(
                    'start_date',
                    'A task must start on a day the project is booked. '.$booked
                );
            }

            if (! $this->isBookedDate($ranges, $dueDate)) {
                $validator->errors()->add(
                    'due_date',
                    'A task must be due on a day the project is booked. '.$booked
                );
            }

            // Only worth saying once both dates are otherwise usable; a bad
            // date is the more useful complaint.
            if ($validator->errors()->hasAny(['start_date', 'due_date'])) {
                return;
            }

            if ($dueDate < $startDate) {
                $validator->errors()->add('due_date', 'The task cannot be due before it starts.');
            }
        });
    }
}
