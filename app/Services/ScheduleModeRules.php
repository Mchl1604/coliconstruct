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
 *
 * How much of a saved booking may still change depends on where it sits
 * relative to today - see Schedule::lockState(), which draws the same line
 * ScheduleHoldCutoff does:
 *
 *   future   start is today or later    everything may change
 *   active   started, has not ended     the start is already worked, so it is
 *                                       frozen; the end may move, but never
 *                                       back past today
 *   locked   ended before today         nothing may change
 *
 * A Super Admin may set those aside for a booking they have confirmed they
 * mean to correct. Nothing else is set aside with them: an overridden booking
 * is still checked for overlaps, for partial-day eligibility and for the
 * availability of everybody on it, because a correction that double-books a
 * technician is not a correction.
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
     * `$isExisting` marks a row that is already saved, and `$existing` is that
     * row when the caller has it. With the row in hand its lock state decides
     * what may change. Without it, `$isExisting` alone still means "leave its
     * dates alone", which is what the screening endpoints want.
     *
     * `$mayOverrideLock` is the Super Admin's confirmed correction. It lifts
     * the lock rules and nothing else.
     *
     * @param  array<string, mixed>  $entry
     * @return array{mode: string, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function validateEntry(
        Validator $validator,
        array $entry,
        string $keyPrefix = '',
        bool $partialDayAllowed = true,
        bool $isExisting = false,
        ?Schedule $existing = null,
        bool $mayOverrideLock = false
    ): ?array {
        $mode = $this->modeFor($entry);

        if ($mode === Schedule::MODE_PARTIAL_DAY && ! $partialDayAllowed) {
            $validator->errors()->add(
                $keyPrefix.'scheduling_mode',
                'Partial Day scheduling is for Residential projects only.'
            );

            return null;
        }

        return $mode === Schedule::MODE_PARTIAL_DAY
            ? $this->validatePartialDay($validator, $entry, $keyPrefix, $isExisting, $existing, $mayOverrideLock)
            : $this->validateDateBased($validator, $entry, $keyPrefix, $isExisting, $existing, $mayOverrideLock);
    }

    /**
     * The refusal an Admin reads when they try to change a booking that has
     * ended, which names who can.
     */
    public function lockedMessage(): string
    {
        return 'This date range has already ended. Super Admin access is required to make changes.';
    }

    /**
     * The same bounds for a range that has not been saved yet.
     *
     * A new booking is a promise about work still to come, so the floor is
     * today at both ends - validateDateBased() and validatePartialDay() refuse
     * a past date on a new row, and a picker that offers one is offering a
     * date the save will bounce. The editor used to hand its new rows no floor
     * at all, so last month was there to be clicked.
     *
     * Shaped exactly like editabilityOf() so the row component can hand either
     * to the same pickers without asking which it got.
     *
     * @return array{editable: bool, startFrozen: bool, earliestStart: CarbonImmutable, earliestEnd: CarbonImmutable}
     */
    public function limitsForNewRange(): array
    {
        $today = Schedule::businessToday();

        return [
            'editable' => true,
            'startFrozen' => false,
            'earliestStart' => $today,
            'earliestEnd' => $today,
        ];
    }

    /**
     * Whether a saved booking may be changed at all, and how much of it.
     *
     * Returned rather than thrown so a caller can ask before it offers a
     * control - the editor draws a locked row read-only rather than letting
     * somebody fill in a form this would refuse, and hands the same bounds to
     * its date pickers.
     *
     *   editable      false only for a locked row nobody may override
     *   startFrozen   the start is already worked and may not move at all
     *   earliestStart the earliest the start may be set to, null for no bound
     *   earliestEnd   the earliest the end may be set to, null for no bound
     *
     * The two overrides differ, which is the point of them. Correcting a
     * booking that has ENDED means its dates were wrong, so any dates may
     * replace them - that is what a correction is. Overriding one that is
     * UNDER WAY only releases its frozen start, and releases it forwards:
     * stretching a live booking back over days nobody worked would be
     * inventing history rather than fixing it.
     *
     * @return array{editable: bool, startFrozen: bool, earliestStart: ?CarbonImmutable, earliestEnd: ?CarbonImmutable}
     */
    public function editabilityOf(Schedule $schedule, bool $mayOverrideLock = false): array
    {
        $today = Schedule::businessToday();

        return match ($schedule->lockState()) {
            Schedule::LOCK_LOCKED => $mayOverrideLock
                ? ['editable' => true, 'startFrozen' => false, 'earliestStart' => null, 'earliestEnd' => null]
                : ['editable' => false, 'startFrozen' => true, 'earliestStart' => null, 'earliestEnd' => null],

            // Started already: those days are worked, so the start stays put,
            // and the end may not retreat past today either - pulling it back
            // would discard days the crew was on site for.
            //
            // The override floor is where the booking ALREADY starts, not
            // today. Forward means forward from where it is: a booking that
            // began on the 20th may stay on the 20th or be moved later, and
            // may not be stretched back to the 19th. Using today here instead
            // would refuse the start it already holds - which would make an
            // override stricter than no override, and leave a Super Admin
            // unable to extend the end of a live booking at all.
            Schedule::LOCK_ACTIVE => $mayOverrideLock
                ? [
                    'editable' => true,
                    'startFrozen' => false,
                    'earliestStart' => $schedule->startsOn(),
                    'earliestEnd' => $today,
                ]
                : ['editable' => true, 'startFrozen' => true, 'earliestStart' => null, 'earliestEnd' => $today],

            default => [
                'editable' => true,
                'startFrozen' => false,
                'earliestStart' => $today,
                'earliestEnd' => null,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{mode: string, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function validateDateBased(
        Validator $validator,
        array $entry,
        string $keyPrefix,
        bool $isExisting,
        ?Schedule $existing = null,
        bool $mayOverrideLock = false
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

        // A saved row resubmitted exactly as it stands is not a change at all,
        // whatever its age. This is what lets a form carrying an untouched
        // booking be saved.
        $unchanged = $existing !== null
            && $existing->isDateBased()
            && $existing->startsOn()->equalTo($startDate)
            && $existing->endsOn()->equalTo($endDate);

        if ($existing !== null && ! $unchanged) {
            if (! $this->assertMayChange($validator, $existing, $keyPrefix, 'start_date', $mayOverrideLock)) {
                return null;
            }

            $limits = $this->editabilityOf($existing, $mayOverrideLock);

            // Days already worked are the record. The start of a booking under
            // way stays where it is.
            if ($limits['startFrozen'] && ! $startDate->equalTo($existing->startsOn())) {
                $validator->errors()->add(
                    $keyPrefix.'start_date',
                    'This schedule has started. Super Admin access is required to move its start date.'
                );

                return null;
            }

            if ($limits['earliestStart'] && $startDate->lt($limits['earliestStart'])) {
                $validator->errors()->add(
                    $keyPrefix.'start_date',
                    'Choose a start date of today or later.'
                );

                return null;
            }

            if ($limits['earliestEnd'] && $endDate->lt($limits['earliestEnd'])) {
                $validator->errors()->add(
                    $keyPrefix.'end_date',
                    'Choose an end date of today or later.'
                );

                return null;
            }

            return [
                'mode' => Schedule::MODE_DATE_BASED,
                'start' => $startDate->startOfDay(),
                'end' => $endDate->endOfDay(),
            ];
        }

        // Everything below is a booking with no saved row to measure against:
        // a brand new one, or one a screening endpoint is asking about. Nothing
        // may be newly promised for a day that has gone.
        $mayKeepPastDates = $unchanged || ($isExisting && $existing === null);

        if (! $mayKeepPastDates && $startDate->lt(Schedule::businessToday())) {
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
        bool $isExisting,
        ?Schedule $existing = null,
        bool $mayOverrideLock = false
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

            // Only the shape of the time here. Whether the hour is one this
            // system will book is asked below, and only of a row that is
            // actually being changed - see there.
            if (! Schedule::isOnTheHour($time)) {
                $validator->errors()->add(
                    $keyPrefix.$field,
                    'Choose a time on the hour.'
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

        // The same rule as a whole-day range: hours already sat through cannot
        // be re-promised, but a row nobody is moving keeps them.
        $unchanged = $existing !== null
            && $existing->isPartialDay()
            && CarbonImmutable::parse($existing->start_datetime)->equalTo($start)
            && CarbonImmutable::parse($existing->end_datetime ?? $existing->start_datetime)->equalTo($end);

        // The configured window binds what is being PROMISED, not what has
        // already been promised. Narrowing it to nine o'clock does not move a
        // booking somebody already made for eight, and must not refuse the
        // form that booking is sitting on - a range resubmitted exactly as it
        // stands is not a new promise about those hours. Change one minute of
        // it and it is, and then the window applies like anything else.
        if (! $unchanged) {
            $bounds = Schedule::partialDayHourBounds();

            foreach ([['start_time', $startTime], ['end_time', $endTime]] as [$field, $time]) {
                if (! Schedule::isWorkingHour($time)) {
                    $validator->errors()->add(
                        $keyPrefix.$field,
                        sprintf(
                            'Choose a time on the hour between %s and %s.',
                            $bounds['start_label'],
                            $bounds['end_label']
                        )
                    );
                }
            }

            if ($validator->errors()->hasAny([$keyPrefix.'start_time', $keyPrefix.'end_time'])) {
                return null;
            }

            // Stated separately from isWorkingHour() so the reason reads as
            // the rule it broke rather than as a generic out-of-range
            // complaint.
            if ($end->hour > $bounds['end'] || ($end->hour === $bounds['end'] && $end->minute > 0)) {
                $validator->errors()->add(
                    $keyPrefix.'end_time',
                    'The end time cannot be later than '.$bounds['end_label'].'.'
                );

                return null;
            }
        }

        if ($existing !== null && ! $unchanged
            && ! $this->assertMayChange($validator, $existing, $keyPrefix, 'project_date', $mayOverrideLock)) {
            return null;
        }

        $mayKeepPastDates = $unchanged || $mayOverrideLock || ($isExisting && $existing === null);

        if (! $mayKeepPastDates) {
            if ($date->lt(Schedule::businessToday())) {
                $validator->errors()->add(
                    $keyPrefix.'project_date',
                    $existing !== null
                        ? 'Choose a date of today or later.'
                        : 'The project date cannot be in the past.'
                );

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
     * Refuse a change to a booking that has ended, unless it is a Super
     * Admin's confirmed correction.
     *
     * Reported against the field the person is looking at rather than gathered
     * into one message about the whole form, the same way every other rule
     * here reports.
     *
     * @return bool whether the change may go ahead
     */
    private function assertMayChange(
        Validator $validator,
        Schedule $existing,
        string $keyPrefix,
        string $field,
        bool $mayOverrideLock
    ): bool {
        if ($this->editabilityOf($existing, $mayOverrideLock)['editable']) {
            return true;
        }

        $validator->errors()->add($keyPrefix.$field, $this->lockedMessage());

        return false;
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
                'The schedule for %s covers more than one day. Split it into single days first.',
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
}
