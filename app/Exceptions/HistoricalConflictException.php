<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A historical correction names somebody the record already has working
 * elsewhere on the same day, and nobody has confirmed it yet.
 *
 * Thrown rather than returned so it unwinds the save's transaction the way
 * every other refusal on that screen does - nothing is half-written while the
 * question is put. It is not really an error, though, and the screen must not
 * present it as one: the answer may well be "yes, that is what happened", and
 * the correction then goes through untouched. See
 * HistoricalScheduleCorrection::conflictsFor(), which explains why a clash in
 * the past is a question where a clash in the future is a refusal.
 *
 * The clashes travel on the exception so the editor can show exactly what it
 * is asking about: which technician, which day, which other project, and the
 * booking that is already there.
 */
class HistoricalConflictException extends RuntimeException
{
    /**
     * @param  array<int, array{technician_id: int, technician: string, entries: array<int, array<string, mixed>>}>  $conflicts
     */
    public function __construct(private readonly array $conflicts)
    {
        parent::__construct('This correction clashes with work already on the record.');
    }

    /**
     * @return array<int, array{technician_id: int, technician: string, entries: array<int, array<string, mixed>>}>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }
}
