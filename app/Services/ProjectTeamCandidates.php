<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Technician;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who can be put on a project's team, in the order a scheduler wants to see
 * them.
 *
 * Availability is delegated to TechnicianAvailabilityService so the picker
 * shows exactly what the save would accept - screening on the first date range
 * alone used to offer people the server then rejected.
 *
 * On top of that it ranks: a technician whose skills cover one of the
 * project's types is suggested first, everyone else free follows, and the ones
 * who are booked come last so they can be shown but not chosen.
 */
class ProjectTeamCandidates
{
    public function __construct(
        private readonly TechnicianAvailabilityService $availability
    ) {}

    /**
     * Every technician who could appear in the picker, richest first.
     *
     * @return Collection<int, array{
     *     id: int,
     *     name: string,
     *     role: ?string,
     *     available: bool,
     *     selectable: bool,
     *     suggested: bool,
     *     assigned: bool,
     *     skills: array<int, string>,
     *     matched_skills: array<int, string>,
     *     reason: string
     * }>
     */
    public function forProject(Project $project): Collection
    {
        $technicians = Technician::query()
            ->with(['account:id,name,role', 'skills'])
            ->whereHas('account', fn ($query) => $query->whereIn('role', ['technician', 'lead_technician']))
            ->get();

        if ($technicians->isEmpty()) {
            return collect();
        }

        $assignedIds = $project->projectTechnicians
            ->pluck('technician_id')
            ->map(fn ($technicianId): int => (int) $technicianId)
            ->all();

        $busyDates = $this->busyDates($project, $technicians->pluck('technician_id'));
        $wantedSkills = $this->wantedSkills($project);

        return $technicians
            ->map(function (Technician $technician) use ($assignedIds, $busyDates, $wantedSkills): array {
                $technicianId = (int) $technician->technician_id;
                $skills = $technician->skills->pluck('skill_name')->values()->all();
                $matched = array_values(array_filter(
                    $skills,
                    fn (string $skill): bool => in_array($this->normalise($skill), $wantedSkills, true)
                ));

                // A booking made BY this project is excluded from the check, so
                // a clash here is always with somebody else's work.
                $available = ! isset($busyDates[$technicianId]);
                $assigned = in_array($technicianId, $assignedIds, true);

                return [
                    'id' => $technicianId,
                    'name' => $technician->name,
                    'role' => $technician->account?->role,
                    'available' => $available,
                    // Someone already on the team can always stay on it, even
                    // if a clash slipped in through another route - otherwise
                    // the form could not be saved at all.
                    'selectable' => $available || $assigned,
                    'suggested' => $available && $matched !== [],
                    'assigned' => $assigned,
                    'skills' => $skills,
                    'matched_skills' => $matched,
                    'reason' => $available
                        ? ''
                        : 'Booked on '.$this->availability->describeDates($busyDates[$technicianId]),
                ];
            })
            // Suggested, then merely free, then booked - alphabetical inside
            // each band, expressed as one sortable key.
            ->sortBy(fn (array $candidate): string => sprintf(
                '%d %s',
                $candidate['suggested'] ? 0 : ($candidate['available'] ? 1 : 2),
                mb_strtolower($candidate['name'])
            ))
            ->values();
    }

    /**
     * Conflicting dates per technician across every one of the project's date
     * ranges, in one pass.
     *
     * @param  Collection<int, mixed>  $technicianIds
     * @return array<int, array<int, string>>
     */
    private function busyDates(Project $project, Collection $technicianIds): array
    {
        $ranges = $project->schedules
            ->map(fn ($schedule): array => [
                'start' => CarbonImmutable::parse($schedule->start_datetime)->startOfDay(),
                'end' => CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->startOfDay(),
            ])
            ->all();

        if ($ranges === []) {
            // Nothing is scheduled yet, so nobody can clash with it.
            return [];
        }

        return $this->availability
            ->findConflicts($technicianIds, $ranges, (int) $project->project_id)
            ->mapWithKeys(fn (array $conflict): array => [
                $conflict['technician_id'] => $conflict['dates'],
            ])
            ->all();
    }

    /**
     * The project's types, normalised for comparison against skill names.
     *
     * @return array<int, string>
     */
    private function wantedSkills(Project $project): array
    {
        return $project->projectTypes
            ->pluck('type_name')
            ->map(fn (string $typeName): string => $this->normalise($typeName))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
