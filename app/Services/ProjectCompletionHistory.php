<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectCompletionPhoto;
use App\Models\ProjectCompletionReport;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Moving a completion report out of the way when a project goes back to work.
 *
 * Reopening a project used to leave the report where it was, on the project's
 * own columns. That is the one place every page reads the CURRENT completion
 * report from, so a project that was live again went on presenting a finished
 * project's report as though it still applied - and the next completion then
 * wrote straight over it.
 *
 * Deleting it was never an option either: a completion report describes a
 * visit that really happened, and the photographs are the evidence.
 *
 * So the report is moved rather than kept or destroyed. supersede() copies
 * every completion column onto a row of its own, re-points that cycle's
 * photographs at it, and only then clears the project's columns. Afterwards:
 *
 *   - Project::hasCompletionReport() is false, so the normal completion
 *     section on every page shows nothing without any of them being taught
 *     about reopening;
 *   - the report is readable through View Previous Completion Reports,
 *     labelled Superseded;
 *   - completing the project again writes a fresh report into the columns that
 *     are now empty, and that one is the current one.
 *
 * Run it any number of times and the cycles simply stack up, oldest first.
 */
class ProjectCompletionHistory
{
    /**
     * File the project's current completion report away as history.
     *
     * Returns null when there is nothing to file - a project reopened without
     * ever having been closed out, which the cancel/complete guards make
     * unlikely but which must not create an empty historical row.
     *
     * Always called inside the caller's transaction: the snapshot, the
     * photographs and the cleared columns are one change, and a project left
     * with its report copied but not cleared would show the same report twice.
     *
     * @param  string|null  $reason  Why the cycle ended - the reopen reason.
     */
    public function supersede(Project $project, ?User $actor = null, ?string $reason = null): ?ProjectCompletionReport
    {
        if (! $project->hasCompletionReport()) {
            return null;
        }

        $report = ProjectCompletionReport::create([
            'project_id' => $project->project_id,
            'cycle' => $this->nextCycle($project),
            'status' => ProjectCompletionReport::STATUS_SUPERSEDED,
            'completed_at' => $project->completed_at,
            'completion_summary' => $project->completion_summary,
            'completion_remarks' => $project->completion_remarks,
            'completion_method' => $project->completion_method,
            'completion_requested_at' => $project->completion_requested_at,
            'completion_requested_by' => $project->completion_requested_by,
            'client_confirmed_at' => $project->client_confirmed_at,
            'client_confirmed_by' => $project->client_confirmed_by,
            'completion_override_reason' => $project->completion_override_reason,
            'completion_override_blockers' => $project->completion_override_blockers,
            'completion_overridden_by' => $project->completion_overridden_by,
            // Completed, or still waiting on the client. Read before the
            // reopen writes 'ongoing' over it.
            'project_status' => $project->status,
            'superseded_at' => CarbonImmutable::now(),
            'superseded_by' => $actor?->id,
            'supersede_reason' => $reason,
        ]);

        // The photographs of this cycle - the ones that have not already been
        // claimed by an earlier one - move with the report they belong to. The
        // files themselves are untouched; only the row's owner changes.
        ProjectCompletionPhoto::query()
            ->where('project_id', $project->project_id)
            ->whereNull('completion_report_id')
            ->update(['completion_report_id' => $report->completion_report_id]);

        // Now, and only now, the project stops carrying a completion report.
        // Everything above is a copy, so nothing is lost by clearing these.
        $project->update([
            'completed_at' => null,
            'completion_summary' => null,
            'completion_remarks' => null,
            'completion_method' => null,
            'client_confirmed_at' => null,
            'client_confirmed_by' => null,
            'completion_override_reason' => null,
            'completion_override_blockers' => null,
            'completion_overridden_by' => null,
        ]);

        // Both are stale now: the columns have changed and the photographs
        // belong to the report rather than to the project.
        $project->unsetRelation('completionPhotos');
        $project->unsetRelation('completionReports');

        return $report;
    }

    /**
     * Which completion this was, counted from one.
     *
     * Read from the rows rather than from a counter on the project, so the
     * numbering cannot drift from the history it describes.
     */
    public function nextCycle(Project $project): int
    {
        $highest = (int) ProjectCompletionReport::query()
            ->where('project_id', $project->project_id)
            ->max('cycle');

        return $highest + 1;
    }
}
