<?php

namespace App\Support;

/**
 * The code a record is shown and quoted by, built from its numeric key.
 *
 * A bare auto-increment ("7") reads as a row number rather than something a
 * person can say out loud or type into a search box. One place decides the
 * prefix and the width, so every table, dialog and PDF - and the search that
 * has to match what they print - agree on TECH-0007.
 *
 * The width matches the account codes already in use (EMP-0001, CLI-0001).
 */
class DisplayCode
{
    public const TECHNICIAN = 'TECH';

    /**
     * Deliberately not PRJ: that prefix belongs to a project's reference
     * number (PRJ-20260816-00001), and the two sit side by side in the
     * projects table.
     */
    public const PROJECT = 'PROJ';

    public const REPORT = 'RPT';

    private const WIDTH = 4;

    /**
     * The placeholder every table already uses for a missing value.
     */
    public const NONE = '—';

    public static function format(string $prefix, int|string|null $id): string
    {
        if ($id === null || $id === '') {
            return self::NONE;
        }

        return $prefix.'-'.str_pad((string) (int) $id, self::WIDTH, '0', STR_PAD_LEFT);
    }

    /**
     * The key hiding inside a typed code, so a search for "RPT-0007" finds
     * the same record as "rpt 7" or a plain "7".
     *
     * Null when the term is not a code at all, which is how a caller knows to
     * leave the id column out of the query instead of matching everything.
     */
    public static function toId(string $prefix, ?string $value): ?int
    {
        $matched = preg_match(
            '/^\s*(?:'.preg_quote($prefix, '/').'[\s-]*)?0*(\d+)\s*$/i',
            (string) $value,
            $matches
        );

        return $matched === 1 ? (int) $matches[1] : null;
    }
}
