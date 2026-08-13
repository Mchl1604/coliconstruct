<?php

namespace App\Services;

use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Validation\Validator;

/**
 * The "a task must sit inside its project's scheduled period" rule.
 *
 * A project can hold several date ranges with gaps between them - booked
 * Aug 10-15 and Aug 25-30, say. A task is measured against the whole period
 * those ranges span, from the earliest booked date to the latest: Aug 10
 * through Aug 30. Anything starting on or after the first and ending on or
 * before the last is allowed, whether or not every day in between is booked.
 *
 * A task is a piece of work with a deadline, not a claim on a day. Fitting
 * one inside a single range used to be the rule, which made a perfectly
 * sensible task - "start when we arrive, finish before we leave" - impossible
 * to express on a project that runs in two visits, and made a task vanish from
 * the board whenever a range it happened to sit in was split.
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
     * Whether the given dates sit inside that period.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     */
    public function windowCovers(array $ranges, ?string $startDate, ?string $dueDate): bool
    {
        if (! $startDate || ! $dueDate) {
            return false;
        }

        $window = $this->window($ranges);

        if (! $window) {
            return false;
        }

        return $startDate >= $window['start'] && $dueDate <= $window['end'];
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
     * Add the "inside the project's scheduled period" check to a validator.
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

            if ($this->windowCovers($ranges, $data['start_date'] ?? null, $data['due_date'] ?? null)) {
                return;
            }

            $message = 'The task dates must fall inside the project\'s scheduled period ('
                .$this->describeWindow($ranges).').';

            $validator->errors()->add('start_date', $message);
            $validator->errors()->add('due_date', $message);
        });
    }
}
