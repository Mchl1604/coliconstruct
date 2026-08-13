<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The teams that could be copied onto another project.
 *
 * A crew that has worked well together is worth reusing, and picking the same
 * five people out of a list one at a time is how a scheduler loses an
 * afternoon. So every project that has a team is offered as a source, and the
 * people on it are checked against the destination's dates before anybody is
 * copied anywhere.
 *
 * Availability is TechnicianAvailabilityService's answer, asked the same way
 * ProjectTeamCandidates asks it - each technician against every range the
 * destination holds, ignoring what the destination itself has booked. Nothing
 * here decides who is free; it only says who was asked about and what came
 * back.
 *
 * Only the team is ever read. A source project's dates, tasks, reports,
 * status, client, documents and notes are none of the destination's business,
 * and this returns none of them beyond what the modal shows to help a person
 * recognise the project they mean.
 */
class ImportableTeamSources
{
    /**
     * Statuses whose projects are listed under "Completed & Cancelled".
     *
     * Closed work is still worth importing from - it is where the crews that
     * have already finished a job together are recorded - so it is offered,
     * just not first.
     *
     * @var array<int, string>
     */
    public const CLOSED_STATUSES = ['completed', 'cancelled'];

    public function __construct(
        private readonly TechnicianAvailabilityService $availability
    ) {}

    /**
     * Every project whose team could be imported, screened against the dates
     * the destination holds.
     *
     * `$ranges` is the destination's schedule in the shape the availability
     * service reads. With none - a project being created before its dates are
     * chosen - nobody can clash, and every technician comes back available.
     *
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode?: string}>  $ranges
     * @param  int|null  $excludeProjectId  the destination itself, which is
     *                                      not a source for its own team
     * @return array<int, array<string, mixed>>
     */
    public function forRanges(array $ranges, ?int $excludeProjectId = null): array
    {
        $projects = Project::query()
            ->with(['clients', 'schedules', 'projectTechnicians.technician.account'])
            ->where('is_archived', false)
            // Archiving releases a project's team, so an archived project has
            // nothing to offer even before the question of whether it should.
            ->whereHas('projectTechnicians')
            ->when($excludeProjectId !== null, function ($query) use ($excludeProjectId): void {
                $query->where('project_id', '!=', $excludeProjectId);
            })
            ->orderBy('name')
            ->get();

        if ($projects->isEmpty()) {
            return [];
        }

        $busyDates = $this->busyDates($projects, $ranges, $excludeProjectId);

        return $projects
            ->map(fn (Project $project): array => $this->payload($project, $busyDates))
            ->all();
    }

    /**
     * Conflicting dates per technician, across every candidate at once.
     *
     * One question covers every technician on every project on the list, so
     * the cost is a fixed handful of queries however long that list gets.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<int, array<string, mixed>>  $ranges
     * @return array<int, array<int, string>>
     */
    private function busyDates(Collection $projects, array $ranges, ?int $excludeProjectId): array
    {
        if ($ranges === []) {
            return [];
        }

        $technicianIds = $projects
            ->flatMap(fn (Project $project) => $project->projectTechnicians->pluck('technician_id'))
            ->filter()
            ->unique()
            ->values();

        if ($technicianIds->isEmpty()) {
            return [];
        }

        return $this->availability
            ->findConflicts($technicianIds, $ranges, $excludeProjectId)
            ->mapWithKeys(fn (array $conflict): array => [
                $conflict['technician_id'] => $conflict['dates'],
            ])
            ->all();
    }

    /**
     * One source project as the modal shows it.
     *
     * @param  array<int, array<int, string>>  $busyDates
     * @return array<string, mixed>
     */
    private function payload(Project $project, array $busyDates): array
    {
        $members = $project->projectTechnicians
            ->filter(fn (ProjectTechnician $assignment): bool => $assignment->technician !== null)
            ->map(fn (ProjectTechnician $assignment): array => $this->member($assignment, $busyDates))
            ->values();

        $lead = $members->firstWhere('is_lead', true);
        $unavailable = $members->where('available', false)->values();

        return [
            'project_id' => (int) $project->project_id,
            'reference_no' => $project->reference_no,
            'name' => $project->name,
            'client' => $project->clients->first()?->fullname
                ?? $project->clients->first()?->company_name,
            'status' => $project->status,
            'status_label' => $project->statusLabel(),
            // Grouped rather than filtered: a finished project is often
            // exactly the crew somebody is looking for.
            'group' => in_array($project->status, self::CLOSED_STATUSES, true) ? 'closed' : 'active',
            'schedule_label' => $project->schedules
                ->map(fn (Schedule $schedule): string => $schedule->describe())
                ->join('; ') ?: 'No schedule set',
            'lead' => $lead,
            // Everybody on the team, the lead included: the modal lists the
            // crew as it stands, and names the lead separately.
            'technicians' => $members->all(),
            'available' => $unavailable->isEmpty(),
            'unavailable' => $unavailable->all(),
        ];
    }

    /**
     * @param  array<int, array<int, string>>  $busyDates
     * @return array<string, mixed>
     */
    private function member(ProjectTechnician $assignment, array $busyDates): array
    {
        $technicianId = (int) $assignment->technician_id;
        $dates = $busyDates[$technicianId] ?? [];

        return [
            'id' => $technicianId,
            'name' => $assignment->technician->name,
            'role' => $assignment->technician->account?->role,
            'role_label' => $assignment->technician->account?->roleLabel(),
            'avatar_url' => $assignment->technician->account?->avatarUrl(),
            // A project's lead is the member whose account role says so;
            // there is no per-project lead column.
            'is_lead' => $assignment->technician->account?->role === 'lead_technician',
            'available' => $dates === [],
            'reason' => $dates === []
                ? ''
                : 'Booked on '.$this->availability->describeDates($dates),
        ];
    }
}
