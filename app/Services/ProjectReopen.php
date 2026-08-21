<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Putting a project that is waiting on its client back to work.
 *
 * The one action allowed on a project in Awaiting Client Confirmation, and an
 * administrator's alone. It exists because "the work is finished" is sometimes
 * wrong: a client raises something, or the crew finds more to do, and the
 * project has to resume rather than be closed and re-created.
 *
 * Two rules shape the whole of it.
 *
 * A Completed project is never reopened. Both routes into Completed are
 * settled - the client agreed, or seven days went by - and a record that can
 * be reopened afterwards is not a record. That is asserted here rather than
 * left to whichever page drew the button.
 *
 * The released dates do not come back. Asking for completion gave up every
 * booked day past the completion date, and those days were free for other work
 * from that moment - somebody may well have taken them. Reopening therefore
 * demands a NEW schedule, screened against everything currently booked, rather
 * than restoring rows whose claim on the calendar has already been let go.
 */
class ProjectReopen
{
    public function __construct(
        private readonly ProjectTeam $projectTeam,
        private readonly TechnicianAvailabilityService $availability,
        private readonly ScheduleModeRules $scheduleRules,
        private readonly ScheduleConsolidation $consolidation
    ) {}

    /**
     * Reopen a project onto a new schedule.
     *
     * Runs inside the caller's transaction, and every check that could refuse
     * the reopen runs before anything is written - so a project is never left
     * reading as Ongoing with no dates behind it.
     *
     * @param  array{mode: string, start: CarbonImmutable, end: CarbonImmutable}  $entry
     *                                                                                    The new schedule, already interpreted by ScheduleModeRules.
     *
     * @throws RuntimeException when the project may not be reopened, or the
     *                          new schedule cannot be honoured
     */
    public function reopen(Project $project, array $entry, string $reason, ?User $actor = null): Schedule
    {
        $this->assertReopenable($project);
        $this->assertPartialDayAllowed($project, $entry['mode']);
        $this->assertNoSelfOverlap($project, $entry);
        $this->assertTeamAvailable($project, $entry);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $entry['start'],
            'end_datetime' => $entry['end'],
            'scheduling_mode' => $entry['mode'],
            'status' => 'scheduled',
            'remarks' => 'Added when the project was reopened',
        ]);

        // Without this the crew would sit on the team while reading as free
        // for the very dates they have just been given, and could be booked
        // onto a second project over them. The two rows are always written
        // together - see ProjectTeam.
        $this->projectTeam->linkScheduleToTeam($schedule, $project);

        $project->update([
            // Ongoing rather than Pending whichever way the dates fall: an
            // administrator reopening a project is saying work resumes, and
            // the daily status pass will not touch it again either way.
            'status' => 'ongoing',
            'on_hold' => false,
            'reopened_at' => CarbonImmutable::now(),
            'reopened_by' => $actor?->id,
            'reopen_reason' => $reason,
            // The confirmation clock stops. The completion report itself -
            // the date, the summary, the remarks and the photographs - is
            // deliberately kept: it describes a visit that really happened,
            // and completing the project again writes a fresh report beside
            // rather than over the evidence.
            'completion_requested_at' => null,
            'completion_requested_by' => null,
            'completion_reminder_sent_at' => null,
        ]);

        $project->unsetRelation('schedules');

        // Work resuming the day after the recorded work ended is one stretch
        // of booked time, not two. Consolidating may absorb the row just
        // created into the earlier one, so the booking that now covers these
        // dates is read back rather than assumed to be the one written.
        $this->consolidation->consolidate($project);

        return $this->bookingCovering($project, $entry['start']) ?? $schedule->refresh();
    }

    /**
     * The row that now holds the given day, after any merging.
     */
    private function bookingCovering(Project $project, CarbonImmutable $day): ?Schedule
    {
        return Schedule::query()
            ->where('project_id', $project->project_id)
            ->whereDate('start_datetime', '<=', $day->toDateString())
            ->whereDate('end_datetime', '>=', $day->toDateString())
            ->orderBy('start_datetime')
            ->first();
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    private function assertReopenable(Project $project): void
    {
        if ($project->isCompleted()) {
            throw new RuntimeException(
                'Completed projects cannot be reopened - create a new project instead.'
            );
        }

        if (! $project->canBeReopened()) {
            throw new RuntimeException(sprintf(
                'Only a project awaiting client confirmation can be reopened. This one is %s.',
                $project->statusLabel()
            ));
        }

        if ($project->is_archived) {
            throw new RuntimeException('This project is archived and cannot be reopened.');
        }
    }

    /**
     * Partial days are a Residential offering, exactly as they are on every
     * other screen that writes a schedule.
     */
    private function assertPartialDayAllowed(Project $project, string $mode): void
    {
        if ($mode !== Schedule::MODE_PARTIAL_DAY || $project->isResidential()) {
            return;
        }

        throw new RuntimeException(
            'This is a Commercial project. Partial Day scheduling is for Residential projects only.'
        );
    }

    /**
     * The new dates must not land on top of the days the project kept.
     *
     * Those are the days the crew actually worked, and booking over them would
     * claim the same time twice.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $entry
     */
    private function assertNoSelfOverlap(Project $project, array $entry): void
    {
        $project->loadMissing('schedules');

        $clash = $project->schedules->first(
            fn (Schedule $schedule): bool => $this->scheduleRules->overlaps($entry, $schedule->occupiedInterval())
        );

        if ($clash) {
            throw new RuntimeException(sprintf(
                'This project is already scheduled for %s. Choose later dates.',
                $clash->describe()
            ));
        }
    }

    /**
     * Every technician on the team has to be free for the whole of the new
     * range, judged against everything currently booked.
     *
     * The project's own remaining rows are excluded, and the ranges released
     * at completion are simply not there to be found - so a reopen is screened
     * against the calendar as it stands today, which is the only version of it
     * that is true.
     *
     * @param  array{mode: string, start: CarbonImmutable, end: CarbonImmutable}  $entry
     */
    private function assertTeamAvailable(Project $project, array $entry): void
    {
        $project->loadMissing('projectTechnicians');

        $technicianIds = $project->projectTechnicians
            ->pluck('technician_id')
            ->filter()
            ->unique()
            ->values();

        if ($technicianIds->isEmpty()) {
            return;
        }

        $this->availability->assertContinuouslyAvailable(
            $technicianIds,
            [$entry],
            $project->project_id
        );
    }
}
