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
     * The one format every date shown to a person is written in: "Jan 1, 2026".
     *
     * Abbreviated month, no leading zero on the day, always the year. The
     * whole system reads dates in this one shape - a table, a card, a modal, a
     * filter and an exported file all say the same thing the same way - so
     * this constant is the single place it is decided.
     *
     * Stored values are untouched by it. A date column is still a date and an
     * ISO string still leaves the server as one; this is display only.
     */
    public const DATE = 'M j, Y';

    /**
     * The same date with the time of day: "Jan 1, 2026 3:04 PM". Used wherever
     * an entry is an instant rather than a day - an audit row, a sign-in, an
     * enquiry - and the date half of it matches DATE exactly.
     */
    public const DATE_TIME = 'M j, Y g:i A';

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
     * An office wall-clock moment, as the UTC instant a stored timestamp is
     * compared with.
     *
     * The reverse of at(). A filter for "today" or "September" is written in
     * office days, but created_at and friends are UTC: between midnight and
     * 8 AM in Manila the two disagree about which day it is, so a window has
     * to be moved onto the stored clock before it is used in a query - never
     * the column moved onto the office clock.
     */
    public static function toStored(DateTimeInterface|string $wallClock): CarbonImmutable
    {
        $wall = $wallClock instanceof DateTimeInterface
            ? $wallClock->format('Y-m-d H:i:s')
            : $wallClock;

        return CarbonImmutable::parse($wall, Schedule::BUSINESS_TIMEZONE)->utc();
    }

    /**
     * A pair of office wall-clock bounds, moved onto the stored clock. Nulls
     * pass through, so an open-ended window stays open-ended.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function storedRange(
        DateTimeInterface|string|null $start,
        DateTimeInterface|string|null $end
    ): array {
        return [
            $start === null ? null : self::toStored($start),
            $end === null ? null : self::toStored($end),
        ];
    }

    /**
     * The same, formatted - the form a template actually wants, with the
     * absent case handled rather than left to a null-safe operator and a
     * trailing `?? '—'` at every call site.
     */
    public static function format(
        DateTimeInterface|string|null $value,
        string $format = self::DATE,
        string $fallback = '—'
    ): string {
        return self::at($value)?->format($format) ?? $fallback;
    }
}
