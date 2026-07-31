<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Skill;
use App\Models\Task;
use App\Models\Technician;
use App\Services\TechnicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/**
 * Super-admin technician management: specialties on the Details tab, and
 * per-technician scheduling (view, remove from a project, assign to more
 * projects) on the Schedules tab.
 *
 * All availability decisions go through TechnicianAvailabilityService so this
 * page enforces exactly the same continuous-availability rule as the project
 * wizard and the schedules calendar.
 */
class TechnicianController extends Controller
{
    /**
     * A project's lead is the assigned technician whose account role is
     * lead_technician. There is no per-project lead column, so this mirrors
     * the derivation already used on the project details page.
     */
    private const LEAD_ROLE = 'lead_technician';

    public function index()
    {
        $technicians = Technician::query()
            ->with(['account', 'skills'])
            ->whereHas('account', function ($query): void {
                $query->whereIn('role', ['technician', self::LEAD_ROLE]);
            })
            ->orderBy('technician_id')
            ->get();

        $skills = Skill::query()->orderBy('skill_name')->get();

        return view('super-admin.technicians', compact('technicians', 'skills'));
    }

    // ------------------------------------------------------------------
    // Details tab - specialties
    // ------------------------------------------------------------------

    public function show(Technician $technician)
    {
        return response()->json($this->technicianPayload($technician->load(['account', 'skills'])));
    }

    public function addSpecialties(Request $request, Technician $technician)
    {
        $validator = Validator::make($request->all(), [
            'skill_ids' => ['required', 'array', 'min:1'],
            'skill_ids.*' => ['required', 'integer', 'exists:tbl_skills,skill_id'],
        ], [
            'skill_ids.required' => 'Select at least one specialty to add.',
            'skill_ids.min' => 'Select at least one specialty to add.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        // syncWithoutDetaching is what keeps duplicates out: re-adding an
        // existing specialty is a no-op rather than a second pivot row.
        $technician->skills()->syncWithoutDetaching($validator->validated()['skill_ids']);

        return response()->json([
            'message' => 'Specialties updated.',
            'technician' => $this->technicianPayload($technician->fresh(['account', 'skills'])),
        ]);
    }

    public function removeSpecialty(Technician $technician, Skill $skill)
    {
        if (! $technician->skills()->where('tbl_skills.skill_id', $skill->skill_id)->exists()) {
            return response()->json(['error' => 'That specialty is not assigned to this technician.'], 422);
        }

        $technician->skills()->detach($skill->skill_id);

        return response()->json([
            'message' => $this->sentence($skill->skill_name . ' removed'),
            'technician' => $this->technicianPayload($technician->fresh(['account', 'skills'])),
        ]);
    }

    // ------------------------------------------------------------------
    // Schedules tab - calendar
    // ------------------------------------------------------------------

    /**
     * Calendar events for one technician: every schedule range of every
     * non-archived project they are assigned to.
     */
    public function calendar(Technician $technician)
    {
        $schedules = $this->technicianSchedules($technician);

        $statusColors = [
            'pending' => '#f0ad4e',
            'ongoing' => '#0d6efd',
            'completed' => '#198754',
            'cancelled' => '#dc3545',
        ];

        $events = $schedules->map(function (Schedule $schedule) use ($statusColors): array {
            $project = $schedule->project;
            $start = CarbonImmutable::parse($schedule->start_datetime);
            $end = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime);

            return [
                'id' => $schedule->schedule_id,
                'title' => $project->reference_no,
                'start' => $start->toDateString(),
                // FullCalendar treats all-day end dates as exclusive.
                'end' => $end->addDay()->toDateString(),
                'color' => $project->on_hold
                    ? '#6c757d'
                    : ($statusColors[$project->status] ?? '#0d6efd'),
                'extendedProps' => [
                    'projectId' => $project->project_id,
                    'referenceNo' => $project->reference_no,
                    'projectName' => $project->name,
                    'client' => $this->clientName($project),
                    'status' => $project->status,
                    'statusLabel' => $this->statusLabel($project),
                    'rangeLabel' => $start->format('M j, Y') . ' - ' . $end->format('M j, Y'),
                ],
            ];
        })->values();

        return response()->json([
            'technician' => $this->technicianPayload($technician->load(['account', 'skills'])),
            'events' => $events,
            'assignmentCount' => $schedules->pluck('project_id')->unique()->count(),
        ]);
    }

    /**
     * Everything the Project Assignment modal needs, including whether this
     * technician is the project's lead and who could replace them.
     */
    public function assignment(Technician $technician, Project $project)
    {
        $project->load(['clients', 'schedules', 'projectTechnicians.technician.account']);

        $assignment = $project->projectTechnicians
            ->firstWhere('technician_id', $technician->technician_id);

        if (! $assignment) {
            return response()->json(['error' => 'This technician is not assigned to that project.'], 422);
        }

        $lead = $this->leadAssignment($project);
        $isLead = $lead && (int) $lead->technician_id === (int) $technician->technician_id;

        $payload = [
            'project' => $this->projectPayload($project),
            'is_lead' => $isLead,
            'read_only' => $project->isReadOnly(),
            'remaining_after_removal' => $project->projectTechnicians->count() - 1,
            'replacement_leads' => [],
        ];

        // Replacements are lead-role technicians who are NOT already on this
        // project and who are free for its whole schedule.
        if ($isLead && ! $project->isReadOnly()) {
            $payload['replacement_leads'] = $this->availableReplacementLeads($project)
                ->map(fn (Technician $candidate): array => [
                    'technician_id' => $candidate->technician_id,
                    'name' => $candidate->name,
                    'skills' => $candidate->skill_names,
                ])
                ->values()
                ->all();
        }

        return response()->json($payload);
    }

    /**
     * Remove one technician from one project.
     *
     * When they are the project's lead, a replacement lead must be supplied;
     * the replacement is added first and only then is the outgoing lead
     * removed, so the project is never left without one.
     */
    public function removeFromProject(Request $request, Technician $technician, Project $project)
    {
        $validator = Validator::make($request->all(), [
            'replacement_lead_id' => ['nullable', 'integer', 'exists:tbl_technicians,technician_id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $replacementLeadId = $validator->validated()['replacement_lead_id'] ?? null;

        try {
            DB::transaction(function () use ($technician, $project, $replacementLeadId): void {
                $project->load(['schedules', 'projectTechnicians.technician.account']);

                if ($project->isReadOnly()) {
                    throw new RuntimeException(sprintf(
                        'This project is %s and its team can no longer be changed.',
                        $project->status
                    ));
                }

                $assignment = $project->projectTechnicians
                    ->firstWhere('technician_id', $technician->technician_id);

                if (! $assignment) {
                    throw new RuntimeException('This technician is not assigned to that project.');
                }

                $lead = $this->leadAssignment($project);
                $isLead = $lead && (int) $lead->technician_id === (int) $technician->technician_id;

                if (! $isLead && $project->projectTechnicians->count() <= 1) {
                    throw new RuntimeException($this->sentence(
                        'A project must keep at least one technician. Assign someone else before removing '
                            . $technician->name
                    ));
                }

                if ($isLead) {
                    $this->promoteReplacementLead($project, $technician, $replacementLeadId);
                }

                $this->detachTechnician($project, $assignment);
            });
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $this->sentence($technician->name . ' was removed from ' . $project->name),
        ]);
    }

    // ------------------------------------------------------------------
    // Schedules tab - assigning to more projects
    // ------------------------------------------------------------------

    /**
     * Projects this technician could join: pending/ongoing, not on hold, not
     * already assigned, scheduled, and free for every day of their schedule.
     *
     * Each project carries its date ranges so the browser can grey out
     * projects that overlap one the user has already ticked.
     */
    public function assignableProjects(Technician $technician)
    {
        $candidates = Project::query()
            ->with(['clients', 'schedules', 'projectTechnicians.technician.account'])
            ->whereIn('status', Project::ACTIVE_PROJECT_STATUSES)
            ->where('is_archived', false)
            ->where(function ($query): void {
                $query->where('on_hold', false)->orWhereNull('on_hold');
            })
            ->whereDoesntHave('projectTechnicians', function ($query) use ($technician): void {
                $query->where('technician_id', $technician->technician_id);
            })
            ->orderBy('name')
            ->get();

        $eligible = [];
        $blocked = [];

        // Every candidate range in one window, so availability costs a fixed
        // handful of queries no matter how many projects are in play. The
        // technician is not on any candidate (see whereDoesntHave above), so
        // there is nothing of theirs to exclude per project.
        $allRanges = $candidates
            ->flatMap(fn (Project $project): array => $this->projectRanges($project))
            ->values()
            ->all();

        $busyDays = $allRanges === []
            ? []
            : (app(TechnicianAvailabilityService::class)->unavailableDatesByTechnician(
                [$technician->technician_id],
                [[
                    'start' => collect($allRanges)->min(fn (array $range) => $range['start']),
                    'end' => collect($allRanges)->max(fn (array $range) => $range['end']),
                ]]
            )[(int) $technician->technician_id] ?? []);

        foreach ($candidates as $project) {
            $ranges = $this->projectRanges($project);

            if ($ranges === []) {
                $blocked[] = $this->projectPayload($project, 'No schedule set yet, so there is nothing to assign to.');

                continue;
            }

            $clashes = false;

            foreach ($ranges as $range) {
                foreach ($this->eachDate($range['start'], $range['end']) as $day) {
                    if (isset($busyDays[$day])) {
                        $clashes = true;

                        break 2;
                    }
                }
            }

            if ($clashes) {
                $blocked[] = $this->projectPayload(
                    $project,
                    'Technician is unavailable during this project\'s schedule.'
                );

                continue;
            }

            $eligible[] = $this->projectPayload($project);
        }

        return response()->json([
            'technician' => $this->technicianPayload($technician->load(['account', 'skills'])),
            'projects' => $eligible,
            'blocked' => $blocked,
        ]);
    }

    /**
     * Assign the technician to one or more projects.
     *
     * Re-runs every check the browser already made, so a stale page or a
     * simultaneous edit elsewhere cannot create an overlapping assignment.
     */
    public function assignToProjects(Request $request, Technician $technician)
    {
        $validator = Validator::make($request->all(), [
            'project_ids' => ['required', 'array', 'min:1'],
            'project_ids.*' => ['required', 'integer', 'exists:tbl_projects,project_id'],
        ], [
            'project_ids.required' => 'Select at least one project.',
            'project_ids.min' => 'Select at least one project.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $projects = Project::query()
            ->with(['schedules', 'projectTechnicians'])
            ->whereIn('project_id', $validator->validated()['project_ids'])
            ->get();

        $availability = app(TechnicianAvailabilityService::class);

        try {
            DB::transaction(function () use ($technician, $projects, $availability): void {
                $claimedRanges = [];

                foreach ($projects as $project) {
                    $this->assertProjectAcceptsTechnicians($project);

                    if ($project->projectTechnicians->contains('technician_id', $technician->technician_id)) {
                        throw new RuntimeException($this->sentence(
                            $technician->name . ' is already assigned to ' . $project->name
                        ));
                    }

                    $ranges = $this->projectRanges($project);

                    if ($ranges === []) {
                        throw new RuntimeException($this->sentence($project->name . ' has no schedule yet'));
                    }

                    // Against everything already stored.
                    $availability->assertContinuouslyAvailable(
                        [$technician->technician_id],
                        $ranges,
                        $project->project_id
                    );

                    // Against the other projects being saved in this request,
                    // which share no rows yet and so can't be caught above.
                    foreach ($ranges as $range) {
                        foreach ($claimedRanges as $claimed) {
                            $overlaps = $range['start']->lte($claimed['end'])
                                && $range['end']->gte($claimed['start']);

                            if ($overlaps) {
                                throw new RuntimeException(sprintf(
                                    '%s overlaps %s, so %s cannot take both.',
                                    $project->name,
                                    $claimed['project'],
                                    $technician->name
                                ));
                            }
                        }

                        $claimedRanges[] = [
                            'start' => $range['start'],
                            'end' => $range['end'],
                            'project' => $project->name,
                        ];
                    }

                    $this->attachTechnician($project, $technician);
                }
            });
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $projects->count() === 1
                ? $this->sentence($technician->name . ' was assigned to ' . $projects->first()->name)
                : $technician->name . ' was assigned to ' . $projects->count() . ' projects.',
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Add the technician to a project and to every one of its schedules.
     */
    private function attachTechnician(Project $project, Technician $technician): void
    {
        $projectTechnician = ProjectTechnician::firstOrCreate([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        $project->schedules()->get()->each(function (Schedule $schedule) use ($projectTechnician): void {
            ScheduleTechnician::firstOrCreate([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $projectTechnician->project_technician_id,
            ]);
        });
    }

    /**
     * Drop a technician's assignment plus its schedule rows, and release any
     * unfinished task they held - mirroring the assigned-team editor.
     */
    private function detachTechnician(Project $project, ProjectTechnician $assignment): void
    {
        ScheduleTechnician::query()
            ->where('project_technician_id', $assignment->project_technician_id)
            ->delete();

        Task::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $assignment->technician_id)
            ->where('status', '!=', 'completed')
            ->update([
                'technician_id' => null,
                'status' => 'unassigned',
            ]);

        $assignment->delete();
    }

    /**
     * Validate and install the incoming lead before the outgoing one leaves.
     */
    private function promoteReplacementLead(Project $project, Technician $outgoing, ?int $replacementLeadId): void
    {
        if (! $replacementLeadId) {
            throw new RuntimeException(
                $outgoing->name . ' is the lead technician. Choose a replacement lead before removing them.'
            );
        }

        if ((int) $replacementLeadId === (int) $outgoing->technician_id) {
            throw new RuntimeException('The replacement lead must be a different technician.');
        }

        $replacement = $this->availableReplacementLeads($project)
            ->firstWhere('technician_id', $replacementLeadId);

        if (! $replacement) {
            throw new RuntimeException(
                'That replacement is no longer a valid lead for this project. '
                    . 'They must be a lead technician who is free for the whole schedule and not already assigned.'
            );
        }

        $this->attachTechnician($project, $replacement);
    }

    /**
     * Lead-role technicians who are not on this project and who are free for
     * every day of its schedule.
     *
     * @return Collection<int, Technician>
     */
    private function availableReplacementLeads(Project $project): Collection
    {
        $ranges = $this->projectRanges($project);

        if ($ranges === []) {
            return collect();
        }

        $assignedIds = $project->projectTechnicians->pluck('technician_id')->all();

        $candidates = Technician::query()
            ->with(['account', 'skills'])
            ->whereHas('account', function ($query): void {
                $query->where('role', self::LEAD_ROLE);
            })
            ->whereNotIn('technician_id', $assignedIds)
            ->orderBy('technician_id')
            ->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        // One bulk availability pass for every candidate rather than a query
        // per technician.
        $unavailable = app(TechnicianAvailabilityService::class)->unavailableDatesByTechnician(
            $candidates->pluck('technician_id'),
            $ranges,
            $project->project_id
        );

        return $candidates
            ->filter(fn (Technician $candidate): bool => ($unavailable[(int) $candidate->technician_id] ?? []) === [])
            ->values();
    }

    /**
     * Every schedule range of a project, as availability-service ranges.
     *
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function projectRanges(Project $project): array
    {
        return $project->schedules
            ->map(fn (Schedule $schedule): array => [
                'start' => CarbonImmutable::parse($schedule->start_datetime)->startOfDay(),
                'end' => CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->startOfDay(),
            ])
            ->values()
            ->all();
    }

    /**
     * Inclusive list of 'Y-m-d' strings between two dates.
     *
     * @return array<int, string>
     */
    private function eachDate(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cursor = $from->startOfDay();
        $end = $to->startOfDay();
        $dates = [];

        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    private function assertProjectAcceptsTechnicians(Project $project): void
    {
        if ($project->isReadOnly()) {
            throw new RuntimeException(sprintf(
                '%s is %s and can no longer take technicians.',
                $project->name,
                $project->status
            ));
        }

        if ($project->on_hold) {
            throw new RuntimeException($this->sentence($project->name . ' is on hold'));
        }

        if (! in_array($project->status, Project::ACTIVE_PROJECT_STATUSES, true)) {
            throw new RuntimeException(sprintf(
                '%s cannot take technicians while it is %s.',
                $project->name,
                $this->statusLabel($project)
            ));
        }
    }

    /**
     * Schedules of every non-archived project this technician is on.
     *
     * @return Collection<int, Schedule>
     */
    private function technicianSchedules(Technician $technician): Collection
    {
        return Schedule::query()
            ->whereHas('project', function ($query): void {
                $query->where('is_archived', false);
            })
            ->whereHas('scheduleTechnicians.projectTechnician', function ($query) use ($technician): void {
                $query->where('technician_id', $technician->technician_id);
            })
            ->with(['project.clients'])
            ->orderBy('start_datetime')
            ->get();
    }

    private function leadAssignment(Project $project): ?ProjectTechnician
    {
        return $project->projectTechnicians
            ->first(fn (ProjectTechnician $assignment): bool => optional($assignment->technician?->account)->role === self::LEAD_ROLE);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayload(Project $project, ?string $reason = null): array
    {
        $schedules = $project->schedules ?? collect();
        $start = $schedules->min('start_datetime');
        $end = $schedules->max('end_datetime');
        $lead = $this->leadAssignment($project);

        return [
            'project_id' => $project->project_id,
            'reference_no' => $project->reference_no,
            'name' => $project->name,
            'client' => $this->clientName($project),
            'status' => $project->status,
            'status_label' => $this->statusLabel($project),
            'url' => route('super-admin.projects.show', $project->project_id),
            'start_date' => $start ? CarbonImmutable::parse($start)->toDateString() : null,
            'end_date' => $end ? CarbonImmutable::parse($end)->toDateString() : null,
            'range_label' => $start && $end
                ? CarbonImmutable::parse($start)->format('M j, Y') . ' - ' . CarbonImmutable::parse($end)->format('M j, Y')
                : 'No schedule set',
            'ranges' => $schedules->map(fn (Schedule $schedule): array => [
                'start' => CarbonImmutable::parse($schedule->start_datetime)->toDateString(),
                'end' => CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->toDateString(),
            ])->values()->all(),
            'lead_technician' => $lead?->technician?->name,
            'technicians' => $project->projectTechnicians
                ->map(fn (ProjectTechnician $assignment): ?array => $assignment->technician ? [
                    'technician_id' => $assignment->technician->technician_id,
                    'name' => $assignment->technician->name,
                    'is_lead' => optional($assignment->technician->account)->role === self::LEAD_ROLE,
                ] : null)
                ->filter()
                ->values()
                ->all(),
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technicianPayload(Technician $technician): array
    {
        return [
            'technician_id' => $technician->technician_id,
            'name' => $technician->name,
            'position' => $this->positionLabel($technician),
            'email' => $technician->account?->email,
            'specialties' => $technician->skills
                ->map(fn (Skill $skill): array => [
                    'skill_id' => $skill->skill_id,
                    'skill_name' => $skill->skill_name,
                ])
                ->sortBy('skill_name')
                ->values()
                ->all(),
        ];
    }

    /**
     * Finish a sentence without doubling punctuation - project names often
     * already end in "." (e.g. "Anesi Inc.").
     */
    private function sentence(string $text): string
    {
        return preg_match('/[.!?]$/', $text) === 1 ? $text : $text . '.';
    }

    private function positionLabel(Technician $technician): string
    {
        $role = optional($technician->account)->role ?? $technician->role;

        return $role === self::LEAD_ROLE ? 'Lead Technician' : 'Technician';
    }

    private function clientName(Project $project): ?string
    {
        $client = $project->clients->first();

        return $client?->fullname ?: $client?->company_name;
    }

    private function statusLabel(Project $project): string
    {
        if ($project->on_hold) {
            return 'On Hold';
        }

        return match ($project->status) {
            'not_yet_scheduled' => 'Not Yet Scheduled',
            'pending' => 'Pending',
            'ongoing' => 'Ongoing',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'archived' => 'Archived',
            default => ucfirst((string) $project->status),
        };
    }
}
