<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\TargetDateHistory;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A project's target completion date, and the record of every value it held.
 *
 * The date is a promise, not a booking: it reserves nobody and nothing reads
 * it to decide availability. What it does carry is accountability - the client
 * sees it, so every move is written down with who moved it and why, inside
 * the same transaction as the move itself.
 */
class TargetDateChange
{
    /** The longest reason a change may carry. */
    public const MAX_REASON = 255;

    /** "Oct 30, 2026", or "Not set" for a project without one. */
    public static function format(CarbonInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return 'Not set';
        }

        return CarbonImmutable::parse($date)->format(BusinessTime::DATE);
    }

    /**
     * Whether two dates are the same day. Saving the date a project already
     * has is not a change and must not write history.
     */
    public static function sameDate(CarbonInterface|string|null $current, CarbonInterface|string|null $new): bool
    {
        $currentBlank = $current === null || $current === '';
        $newBlank = $new === null || $new === '';

        if ($currentBlank || $newBlank) {
            return $currentBlank && $newBlank;
        }

        return CarbonImmutable::parse($current)->toDateString() === CarbonImmutable::parse($new)->toDateString();
    }

    /**
     * Why a target date cannot be used, or null when it can.
     *
     * Asked when a date is entered or changed - never when the schedule
     * moves. Work that later runs past the target is allowed, and is exactly
     * what the Overdue badge is there to show.
     */
    public function problemWith(CarbonImmutable $target, ?CarbonImmutable $lastWorkDay): ?string
    {
        if ($target->lt(Schedule::businessToday())) {
            return 'Target date cannot be in the past.';
        }

        if ($lastWorkDay !== null && $target->lt($lastWorkDay->startOfDay())) {
            return sprintf('Target date is before the last work day (%s).', self::format($lastWorkDay));
        }

        return null;
    }

    /**
     * The first entry: the date the project was created with.
     */
    public function recordInitial(Project $project, ?User $actor): TargetDateHistory
    {
        return $this->write($project, null, $project->target_end_date, null, $actor);
    }

    /**
     * An entry for a date that actually changed.
     */
    public function recordChange(
        Project $project,
        CarbonInterface|string|null $previousDate,
        CarbonInterface|string $newDate,
        ?string $reason,
        ?User $actor
    ): TargetDateHistory {
        return $this->write($project, $previousDate, $newDate, $reason, $actor);
    }

    /**
     * The sentence the audit trail gets about a change.
     */
    public function describe(Project $project, CarbonInterface|string|null $previousDate, CarbonInterface|string $newDate, string $reason): string
    {
        return sprintf(
            "Changed the target date of '%s' from %s to %s. Reason: %s",
            $project->reference_no,
            self::format($previousDate),
            self::format($newDate),
            $reason
        );
    }

    private function write(
        Project $project,
        CarbonInterface|string|null $previousDate,
        CarbonInterface|string $newDate,
        ?string $reason,
        ?User $actor
    ): TargetDateHistory {
        return TargetDateHistory::create([
            'project_id' => $project->project_id,
            'previous_date' => $previousDate === null || $previousDate === ''
                ? null
                : CarbonImmutable::parse($previousDate)->toDateString(),
            'new_date' => CarbonImmutable::parse($newDate)->toDateString(),
            'reason' => $reason,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->fullName() ?? 'System',
            'actor_role' => $actor?->role,
            'created_at' => now(),
        ]);
    }
}
