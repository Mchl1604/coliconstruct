<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTechnician;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One proposed change to a project's team, worked out and not yet written.
 *
 * Four kinds of change reach here, and they differ only in how they are
 * stated:
 *
 *   team      Project Details' Assigned Team, the master control: "the team is
 *             now this lead and these technicians". Whoever is taken off comes
 *             off completely, today.
 *   removal   The Technicians page: "take this technician off the project from
 *             this day onward".
 *   days_off  The Technicians page: "take this technician off the project for
 *             these days" - off from the first, back the day after the last.
 *   day_on    The Technicians page: "put this technician on the project for
 *             this one day" - on for that day, off again the day after.
 *
 * Each turns into the same four things that happen to spans - which come off
 * on the effective day, which spans still to come are called off, whose start
 * is brought forward, and which new spans open - and into the team as it will
 * read once they have: every span with those changes applied, unsaved.
 * Everything that has to be judged before a save - the lead rule,
 * availability, which tasks it strands - is judged against that picture, so the
 * question "is this change allowed?" and the change itself are always about
 * the same thing.
 *
 * Built by ProjectTeamChange; nothing else constructs one.
 */
class ProjectTeamChangePlan
{
    public const KIND_TEAM = 'team';

    public const KIND_REMOVAL = 'removal';

    public const KIND_DAYS_OFF = 'days_off';

    public const KIND_DAY_ON = 'day_on';

    /**
     * @param  Collection<int, ProjectTechnician>  $before  every span as it is now
     * @param  Collection<int, ProjectTechnician>  $after  every span as it will be
     *                                                     (unsaved copies; a new
     *                                                     span has no id)
     * @param  Collection<int, ProjectTechnician>  $closing  spans that come off on
     *                                                       the effective day
     * @param  Collection<int, ProjectTechnician>  $cancelling  spans still to come
     *                                                          that are called off
     * @param  Collection<int, ProjectTechnician>  $startingEarlier  scheduled starts
     *                                                               brought forward
     *                                                               to the effective
     *                                                               day
     * @param  Collection<int, array{technician_id: int, from: CarbonImmutable, until: ?CarbonImmutable}>  $joining
     *                                                                                                               new spans; `until`
     *                                                                                                               is the first day
     *                                                                                                               not covered
     * @param  Collection<int, int>  $technicianIds  the whole team a `team` change
     *                                               asks for; empty for the others
     */
    public function __construct(
        public readonly string $kind,
        public readonly Project $project,
        public readonly CarbonImmutable $effective,
        public readonly Collection $before,
        public readonly Collection $after,
        public readonly Collection $closing,
        public readonly Collection $cancelling,
        public readonly Collection $startingEarlier,
        public readonly Collection $joining,
        public readonly ?int $leadId = null,
        public readonly Collection $technicianIds = new Collection,
        public readonly ?int $subjectId = null,
        public readonly ?CarbonImmutable $resumesOn = null,
        public readonly ?int $leadCoverId = null,
    ) {}

    public function effectiveDate(): string
    {
        return $this->effective->toDateString();
    }

    /**
     * Whether the change takes effect today rather than on a day still to come.
     */
    public function isImmediate(): bool
    {
        return $this->effectiveDate() <= ProjectTechnician::today();
    }

    /**
     * The last day of a days-off change - the day before they are back.
     */
    public function lastDayOff(): ?CarbonImmutable
    {
        return $this->resumesOn?->subDay();
    }

    /**
     * Who is on the team on the effective date as things stand, by technician.
     *
     * @return Collection<int, int>
     */
    public function teamOnEffectiveDateBefore(): Collection
    {
        return $this->before
            ->filter(fn (ProjectTechnician $span): bool => $span->isCurrent($this->effectiveDate()))
            ->pluck('technician_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * Technicians taken off the project by this change - not including one
     * given days off, who is coming back.
     *
     * @return Collection<int, int>
     */
    public function removedIds(): Collection
    {
        if ($this->kind === self::KIND_DAYS_OFF) {
            return collect();
        }

        return $this->closing
            ->merge($this->cancelling)
            ->pluck('technician_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * Technicians put on the project by this change - not including one coming
     * back from days off, nor a stand-in covering them.
     *
     * @return Collection<int, int>
     */
    public function addedIds(): Collection
    {
        if ($this->kind === self::KIND_DAYS_OFF) {
            return collect();
        }

        return $this->joining
            ->pluck('technician_id')
            ->merge($this->startingEarlier->pluck('technician_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public function changesAnything(): bool
    {
        return $this->closing->isNotEmpty()
            || $this->cancelling->isNotEmpty()
            || $this->startingEarlier->isNotEmpty()
            || $this->joining->isNotEmpty();
    }

    /**
     * One technician's spans as they will be.
     *
     * @return Collection<int, ProjectTechnician>
     */
    public function spansAfterFor(int $technicianId): Collection
    {
        return $this->after->filter(fn (ProjectTechnician $span): bool => (int) $span->technician_id === $technicianId)->values();
    }

    /**
     * One technician's spans as they are.
     *
     * @return Collection<int, ProjectTechnician>
     */
    public function spansBeforeFor(int $technicianId): Collection
    {
        return $this->before->filter(fn (ProjectTechnician $span): bool => (int) $span->technician_id === $technicianId)->values();
    }
}
