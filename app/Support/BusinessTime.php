<?php

namespace App\Support;

use App\Models\Schedule;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Stored time, read off the office clock.
 *
 * The application stores UTC and that is deliberate - see the note on
 * Schedule::BUSINESS_TIMEZONE. It is the right thing for a timestamp and the
 * wrong thing for a person: between 4 PM and midnight in Manila the server's
 * date is still yesterday, so a project completed after work would be shown as
 * finishing the day before, and a date picker offering "today" would cap at a
 * day the technician cannot pick.
 *
 * Everything a person reads goes through here. What is compared, sorted or
 * written stays in UTC and must not.
 *
 * Two things deliberately do NOT belong here:
 *
 *   - Schedule rows. They store wall-clock time already, carrying no offset,
 *     and converting them would move an 8 AM booking to 4 PM.
 *   - Date-only columns - a birthdate, a task's due date, a report date.
 *     There is no time of day in them to shift, and treating one as an instant
 *     is how a date moves by a day.
 */
class BusinessTime
{
    /**
     * Now, at the office. The clock a page prints and a date input defaults
     * to.
     */
    public static function now(): CarbonImmutable
    {
        return Schedule::businessNow();
    }

    /**
     * Today, at the office. What a "no later than today" bound means.
     */
    public static function today(): CarbonImmutable
    {
        return Schedule::businessToday();
    }

    /**
     * A stored instant, moved onto the office clock.
     */
    public static function at(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->setTimezone(Schedule::BUSINESS_TIMEZONE);
    }

    /**
     * The same, formatted - the form a template actually wants, with the
     * absent case handled rather than left to a null-safe operator and a
     * trailing `?? '—'` at every call site.
     */
    public static function format(
        DateTimeInterface|string|null $value,
        string $format = 'M j, Y',
        string $fallback = '—'
    ): string {
        return self::at($value)?->format($format) ?? $fallback;
    }
}
