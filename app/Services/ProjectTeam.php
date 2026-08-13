<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * Putting a technician on a project's team, and taking them off it.
 *
 * A team member is recorded twice. Once against the project
 * (tbl_project_technicians), which is what every screen lists. And once
 * against each of the project's schedules (tbl_schedule_technicians), which is
 * the only thing TechnicianAvailabilityService reads - see its
 * busySchedulesQuery(), which joins through scheduleTechnicians and nothing
 * else.
 *
 * A member holding the first row but not the second is on the team and yet
 * reads as free for the very dates they are booked for, so they can be booked
 * onto a second project over the same days. The two rows therefore have to be
 * written together, always, and that is what this class is for: the wizard,
 * the assigned-team editor and the technician's own schedule page all change
 * teams, and none of them may have its own idea of what changing one means.
 *
 * Extracted for the same reason TechnicianAvailabilityService settles "is this
 * technician free?" and ScheduleModeRules settles "what may a schedule say?".
 *
 * Nothing here opens a transaction: every caller already runs inside one, and
 * a team change is only ever part of a larger action.
 */
class ProjectTeam
{
    /**
     * Put a technician on the team, and on every date the project already
     * holds.
     *
     * Safe to call for somebody who is already on the team: it adds whatever
     * is missing and leaves the rest alone.
     */
    public function attach(Project $project, int $technicianId): ProjectTechnician
    {
        $assignment = ProjectTechnician::firstOrCreate([
            'project_id' => $project->project_id,
            'technician_id' => $technicianId,
        ]);

        foreach ($this->scheduleIds($project) as $scheduleId) {
            $this->link($scheduleId, (int) $assignment->project_technician_id);
        }

        return $assignment;
    }

    /**
     * Take a technician off the team, off its dates, and off any unfinished
     * task they were holding.
     *
     * Completed work keeps its technician: it is a record of who did it, not a
     * statement about who is available now.
     */
    public function detach(Project $project, ProjectTechnician $assignment): void
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
     * Book the whole of the project's current team onto a schedule that has
     * just been created.
     *
     * The mirror image of attach(): one arrives after the dates, the other
     * arrives after the team.
     */
    public function linkScheduleToTeam(Schedule $schedule, Project $project): void
    {
        foreach ($this->assignmentIds($project) as $projectTechnicianId) {
            $this->link((int) $schedule->schedule_id, $projectTechnicianId);
        }
    }

    /**
     * The schedule links this project should hold but does not - every
     * (schedule, team member) pair with no row between them.
     *
     * Reported rather than repaired, so the audit command and the repair that
     * follows it agree on what is missing without either one deciding it for
     * itself.
     *
     * @return Collection<int, array{schedule_id: int, project_technician_id: int, technician_id: int}>
     */
    public function missingScheduleLinks(Project $project): Collection
    {
        $project->loadMissing(['schedules.scheduleTechnicians', 'projectTechnicians']);

        $missing = collect();

        foreach ($project->schedules as $schedule) {
            $linked = $schedule->scheduleTechnicians
                ->map(fn (ScheduleTechnician $link): int => (int) $link->project_technician_id)
                ->all();

            foreach ($project->projectTechnicians as $assignment) {
                if (in_array((int) $assignment->project_technician_id, $linked, true)) {
                    continue;
                }

                $missing->push([
                    'schedule_id' => (int) $schedule->schedule_id,
                    'project_technician_id' => (int) $assignment->project_technician_id,
                    'technician_id' => (int) $assignment->technician_id,
                ]);
            }
        }

        return $missing;
    }

    private function link(int $scheduleId, int $projectTechnicianId): void
    {
        ScheduleTechnician::firstOrCreate([
            'schedule_id' => $scheduleId,
            'project_technician_id' => $projectTechnicianId,
        ]);
    }

    /**
     * Read fresh rather than from a loaded relation: a caller that has just
     * created a schedule, or that is part-way through rebuilding a team, would
     * otherwise be working from a picture that is already out of date.
     *
     * @return Collection<int, int>
     */
    private function scheduleIds(Project $project): Collection
    {
        return Schedule::query()
            ->where('project_id', $project->project_id)
            ->pluck('schedule_id')
            ->map(fn ($scheduleId): int => (int) $scheduleId);
    }

    /**
     * @return Collection<int, int>
     */
    private function assignmentIds(Project $project): Collection
    {
        return ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->pluck('project_technician_id')
            ->map(fn ($projectTechnicianId): int => (int) $projectTechnicianId);
    }
}
