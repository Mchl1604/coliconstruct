<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\Technician;
use Illuminate\Support\Collection;

/**
 * Who can be put on a project's team, in the order a scheduler wants to see
 * them.
 *
 * Availability is delegated to TechnicianAvailabilityService so the picker
 * shows exactly what the save would accept - screening on the first date range
 * alone used to offer people the server then rejected.
 *
 * The question asked is about the work still to come: only the project's
 * ranges that have not finished are screened against, so a week it spent in
 * August cannot refuse somebody for a job whose remaining dates are in
 * September. Every one of those remaining ranges is checked - see
 * Schedule::upcomingAvailabilityRanges().
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
        // The picker cards show each technician's picture, so the account has
        // to bring its photo path and name parts along with the role - and
        // `status` / `is_archived` besides, because whether somebody may be
        // given work is read off them. A column left out of this list reads as
        // null on the model, and a null status is not an active one, so
        // omitting them would report every technician as switched off.
        $technicians = Technician::query()
            ->with([
                'account:id,name,first_name,middle_name,last_name,role,profile_photo_path,status,is_archived',
                'skills',
            ])
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
                $free = ! isset($busyDates[$technicianId]);
                $assigned = in_array($technicianId, $assignedIds, true);
                // An account that has been switched off cannot open the
                // project or close a task, so it is not work anybody can be
                // given - whatever the calendar says about their dates.
                $employable = $technician->isAssignable();
                $available = $free && $employable;

                return [
                    'id' => $technicianId,
                    'name' => $technician->name,
                    'role' => $technician->account?->role,
                    'role_label' => $technician->account?->roleLabel(),
                    // Their own picture, or the default avatar - never null
                    // for a technician, who is always an internal account.
                    'avatar_url' => $technician->account?->avatarUrl(),
                    'available' => $available,
                    // Someone already on the team can always stay on it, even
                    // if a clash slipped in through another route - otherwise
                    // the form could not be saved at all.
                    'selectable' => $available || $assigned,
                    'suggested' => $available && $matched !== [],
                    'assigned' => $assigned,
                    'skills' => $skills,
                    'matched_skills' => $matched,
                    // Why they cannot be picked, in the order the reasons
                    // matter: a switched-off account is a fact about the
                    // person and outranks anything the calendar says about
                    // their week.
                    'reason' => match (true) {
                        ! $employable => 'Account is no longer active',
                        ! $free => 'Booked on '.$this->availability->describeDates($busyDates[$technicianId]),
                        default => '',
                    },
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
        // Each schedule asks only about what it actually occupies: a whole-day
        // range about every day it covers, a partial day about its hours. So a
        // technician booked elsewhere in the afternoon is still offered for a
        // project that runs in the morning.
        //
        // Only the ranges that have not finished are asked about. Putting
        // somebody on a team is a decision about the work still to come, and a
        // week the project spent in August cannot be staffed differently now -
        // screening against it only refused technicians over dates nobody can
        // change. Every remaining range is still checked, and a range that
        // started before today keeps the days it has left; see
        // Schedule::toUpcomingAvailabilityRange().
        $ranges = Schedule::upcomingAvailabilityRanges($project->schedules);

        if ($ranges === []) {
            // Nothing is scheduled yet, or nothing is left to schedule for, so
            // there is nothing anybody can clash with.
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
