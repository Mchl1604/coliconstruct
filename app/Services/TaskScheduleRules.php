<?php

namespace App\Services;

use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Validation\Validator;

/**
 * The "a task must sit inside one of its project's schedule ranges" rule.
 *
 * Extracted from TaskController so the technician portal enforces exactly the
 * same thing rather than a second, drifting copy of it. ScheduleController
 * re-checks the same rule after a reschedule.
 */
class TaskScheduleRules
{
    /**
     * Every date range on a project's schedule, as 'Y-m-d' pairs.
     *
     * A project can have several ranges with gaps between them (July 10-15 and
     * July 20-25, say). Checking only the earliest start and the latest end
     * would wrongly accept July 16-19, so callers must test them individually.
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
     * The range that fully contains the given dates, if any. A task cannot
     * straddle the gap between two ranges.
     *
     * @param  array<int, array{start: string, end: string}>  $ranges
     * @return array{start: string, end: string}|null
     */
    public function rangeCovering(array $ranges, ?string $startDate, ?string $dueDate): ?array
    {
        if (! $startDate || ! $dueDate) {
            return null;
        }

        foreach ($ranges as $range) {
            if ($startDate >= $range['start'] && $dueDate <= $range['end']) {
                return $range;
            }
        }

        return null;
    }

    /**
     * Human-readable list of the allowed windows, for validation messages and
     * the form hints that sit under the date pickers.
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
     * Add the "inside a single schedule range" check to a validator.
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

            if ($this->rangeCovering($ranges, $data['start_date'] ?? null, $data['due_date'] ?? null)) {
                return;
            }

            $message = count($ranges) === 1
                ? 'The task dates must fall inside the project schedule ('.$this->describe($ranges).').'
                : 'The task dates must fall inside a single one of the project\'s schedule ranges ('
                    .$this->describe($ranges).'). A task cannot span the gap between two ranges.';

            $validator->errors()->add('start_date', $message);
            $validator->errors()->add('due_date', $message);
        });
    }
}
