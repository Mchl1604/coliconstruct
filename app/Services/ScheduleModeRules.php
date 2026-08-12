<?php

namespace App\Services;

use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RuntimeException;
use Throwable;

/**
 * The "what a schedule may say" rules, in one place.
 *
 * Three screens write schedules - the project wizard, the schedules page, and
 * the calendar - and every one of them has to agree about what a partial day
 * means, which hours may be booked, and what counts as the past. Extracted the
 * same way TaskScheduleRules was, so those three cannot drift apart.
 *
 * Each entry arrives in one shape, whatever screen it came from:
 *
 *   date-based    ['scheduling_mode' => 'date_based',
 *                  'start_date' => 'Y-m-d', 'end_date' => 'Y-m-d']
 *
 *   partial day   ['scheduling_mode' => 'partial_day',
 *                  'project_date' => 'Y-m-d',
 *                  'start_time' => 'HH:MM', 'end_time' => 'HH:MM']
 *
 * An entry with no mode is date-based, so a request written before partial
 * days existed still validates and still saves exactly as it did.
 */
class ScheduleModeRules
{
    /**
     * Shape-only rules. Whether a field is required depends on the mode, so
     * that part is decided per entry in validateEntry() where the mode is
     * known - a wildcard rule cannot ask that question clearly.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(string $prefix = ''): array
    {
        return [
            $prefix.'scheduling_mode' => ['nullable', Rule::in(Schedule::SCHEDULING_MODES)],
            $prefix.'start_date' => ['nullable', 'date'],
            $prefix.'end_date' => ['nullable', 'date'],
            $prefix.'project_date' => ['nullable', 'date'],
            $prefix.'start_time' => ['nullable', 'string'],
            $prefix.'end_time' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(string $prefix = ''): array
    {
        return [
            $prefix.'scheduling_mode.in' => 'Choose either Date-Based or Partial Day scheduling.',
        ];
    }

    /**
     * The mode an entry is asking for, defaulting to whole days.
     *
     * @param  array<string, mixed>  $entry
     */
    public function modeFor(array $entry): string
    {
        $mode = $entry['scheduling_mode'] ?? Schedule::MODE_DATE_BASED;

        return in_array($mode, Schedule::SCHEDULING_MODES, true)
            ? $mode
            : Schedule::MODE_DATE_BASED;
    }

    public function isPartialDay(array $entry): bool
    {
        return $this->modeFor($entry) === Schedule::MODE_PARTIAL_DAY;
    }

    /**
     * Check one entry and hand back what it means, or null when it is not
     * usable - in which case the reason has been added to the validator
     * against the field that caused it.
     *
     * `$keyPrefix` is '' for a form whose fields sit at the top level, and
     * 'ranges.0.' for one row of the schedules page.
     *
     * `$isExisting` marks a row that is already saved. Those keep whatever
     * dates they hold, so an edit to one schedule is not blocked by another
     * having quietly slipped into the past.
     *
     * @param  array<string, mixed>  $entry
     * @return array{mode: string, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function validateEntry(
        Validator $validator,
        array $entry,
        string $keyPrefix = '',
        bool $partialDayAllowed = true,
        bool $isExisting = false
    ): ?array {
        $mode = $this->modeFor($entry);

        if ($mode === Schedule::MODE_PARTIAL_DAY && ! $partialDayAllowed) {
            $validator->errors()->add(
                $keyPrefix.'scheduling_mode',
                'Partial Day scheduling is available on Residential projects only.'
            );

            return null;
        }

        return $mode === Schedule::MODE_PARTIAL_DAY
            ? $this->validatePartialDay($validator, $entry, $keyPrefix, $isExisting)
            : $this->validateDateBased($validator, $entry, $keyPrefix, $isExisting);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{mode: string, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function validateDateBased(
        Validator $validator,
        array $entry,
        string $keyPrefix,
        bool $isExisting
    ): ?array {
        $startDate = $this->date($entry['start_date'] ?? null);
        $endDate = $this->date($entry['end_date'] ?? null);

        if (! $startDate) {
            $validator->errors()->add($keyPrefix.'start_date', 'A start date is required.');
        }

        if (! $endDate) {
            $validator->errors()->add($keyPrefix.'end_date', 'An end date is required.');
        }

        if (! $startDate || ! $endDate) {
            return null;
        }

        if ($endDate->lt($startDate)) {
            $validator->errors()->add(
                $keyPrefix.'end_date',
                'The end date must be on or after the start date.'
            );

            return null;
        }

        if (! $isExisting && $startDate->lt(Schedule::businessToday())) {
            $validator->errors()->add($keyPrefix.'start_date', 'The start date cannot be in the past.');

            return null;
        }

        return [
            'mode' => Schedule::MODE_DATE_BASED,
            'start' => $startDate->startOfDay(),
            'end' => $endDate->endOfDay(),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{mode: string, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function validatePartialDay(
        Validator $validator,
        array $entry,
        string $keyPrefix,
        bool $isExisting
    ): ?array {
        $date = $this->date($entry['project_date'] ?? null);
        $startTime = is_string($entry['start_time'] ?? null) ? $entry['start_time'] : null;
        $endTime = is_string($entry['end_time'] ?? null) ? $entry['end_time'] : null;

        if (! $date) {
            $validator->errors()->add($keyPrefix.'project_date', 'A project date is required.');
        }

        foreach ([['start_time', $startTime], ['end_time', $endTime]] as [$field, $time]) {
            if ($time === null || $time === '') {
                $validator->errors()->add(
                    $keyPrefix.$field,
                    $field === 'start_time' ? 'A start time is required.' : 'An end time is required.'
                );

                continue;
            }

            if (! Schedule::isWorkingHour($time)) {
                $validator->errors()->add(
                    $keyPrefix.$field,
                    sprintf(
                        'Choose a time on the hour between %s and %s.',
                        $this->hourLabel(Schedule::WORKING_HOUR_START),
                        $this->hourLabel(Schedule::WORKING_HOUR_END)
                    )
                );
            }
        }

        if ($validator->errors()->hasAny([
            $keyPrefix.'project_date',
            $keyPrefix.'start_time',
            $keyPrefix.'end_time',
        ])) {
            return null;
        }

        $start = $date->setTimeFromTimeString($startTime);
        $end = $date->setTimeFromTimeString($endTime);

        if ($start->gte($end)) {
            $validator->errors()->add(
                $keyPrefix.'end_time',
                'The end time must be later than the start time.'
            );

            return null;
        }

        // Stated separately from isWorkingHour() so the reason reads as the
        // rule it broke rather than as a generic out-of-range complaint.
        if ($end->hour > Schedule::WORKING_HOUR_END
            || ($end->hour === Schedule::WORKING_HOUR_END && $end->minute > 0)) {
            $validator->errors()->add(
                $keyPrefix.'end_time',
                'The end time cannot be later than '.$this->hourLabel(Schedule::WORKING_HOUR_END).'.'
            );

            return null;
        }

        if (! $isExisting) {
            if ($date->lt(Schedule::businessToday())) {
                $validator->errors()->add($keyPrefix.'project_date', 'The project date cannot be in the past.');

                return null;
            }

            // Today is bookable, but only for hours that have not gone by.
            if ($start->lt(Schedule::businessNow())) {
                $validator->errors()->add($keyPrefix.'start_time', 'That time has already passed today.');

                return null;
            }
        }

        return [
            'mode' => Schedule::MODE_PARTIAL_DAY,
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Whether two entries claim any of the same time.
     *
     * Half-open, matching TechnicianAvailabilityService: a schedule ending at
     * 10:00 AM and one starting at 10:00 AM do not collide.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $first
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $second
     */
    public function overlaps(array $first, array $second): bool
    {
        return $first['start']->lt($second['end']) && $second['start']->lt($first['end']);
    }

    /**
     * Whether an already-saved schedule may change to the requested mode.
     *
     * Mode belongs to the individual schedule row, so this only ever asks
     * about the one being changed and never touches, merges or removes any
     * other row on the project.
     *
     * Hours can only be attached to a schedule that covers a single day: a
     * range spanning several days gives no answer to "which of those days did
     * you mean?", and guessing one would silently discard the rest.
     *
     * @throws RuntimeException
     */
    public function assertConvertible(Schedule $schedule, string $requestedMode): void
    {
        if ($requestedMode === $schedule->scheduling_mode) {
            return;
        }

        if ($requestedMode !== Schedule::MODE_PARTIAL_DAY) {
            // Partial day -> date-based drops the times and keeps the date.
            // Nothing becomes ambiguous, so there is nothing to refuse here;
            // the widened booking is re-checked for conflicts by the caller.
            return;
        }

        if (! $schedule->spansSingleDay()) {
            throw new RuntimeException(sprintf(
                'The schedule for %s covers more than one day, so it cannot be changed to Partial Day. '
                    .'Split it into separate one-day schedules first, or add a new Partial Day schedule instead.',
                $schedule->describe()
            ));
        }
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function hourLabel(int $hour): string
    {
        return CarbonImmutable::today()->setTime($hour, 0)->format('g:i A');
    }
}
