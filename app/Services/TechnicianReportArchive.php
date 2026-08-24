<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\TechnicianReport;
use App\Models\User;
use RuntimeException;

/**
 * Archiving a technician report, and putting it back.
 *
 * The one place either happens. Both portals offer the action from two screens
 * each - the Reports page and Project Details - and all four go through here,
 * so a report archived from one disappears from the others and there is a
 * single description of what archiving actually does.
 *
 * What it does is move three columns and nothing else. The report keeps its
 * project, its technician, the account that filed it, its images, its files
 * and the date it was submitted; the project it belongs to, the schedule and
 * the technician assignments are not touched at all. Archiving is a decision
 * about a list, not about the work.
 *
 * Nothing on disk is removed either: an archived report's pictures are served
 * by the same route they always were - see UploadedFileController - so the
 * archive keeps a whole record rather than a stub of one.
 *
 * Who may do it is TechnicianReportPolicy's question, asked by the controller
 * before either method here is reached.
 */
class TechnicianReportArchive
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /**
     * Take a report off the active lists.
     *
     * @throws RuntimeException when it is already archived, which is a
     *                          stale page rather than a fault.
     */
    public function archive(TechnicianReport $report, User $actor): TechnicianReport
    {
        if ($report->isArchived()) {
            throw new RuntimeException('That report is already archived.');
        }

        $report->forceFill([
            'is_archived' => true,
            'archived_at' => now(),
            // On the row as well as in the trail: the archive view names who
            // filed it away, and a table is joined rather than searched.
            'archived_by' => $actor->id,
        ])->save();

        $this->activityLogger->record(
            ActivityLog::REPORT_ARCHIVED,
            null,
            sprintf(
                'Archived %s (%s) on %s.',
                $report->displayCode(),
                $report->report_title,
                $report->project?->reference_no ?? 'a removed project'
            ),
            $report
        );

        return $report;
    }

    /**
     * Put an archived report back on the active lists, exactly as it was.
     *
     * Nothing is created and nothing is copied: this is the same row, so it
     * comes back with the same submitter, the same project, the same
     * technician and the same pictures it went in with.
     *
     * The actor is not stored anywhere: the archive's own columns are cleared,
     * and who lifted it is the activity trail's to remember.
     *
     * @throws RuntimeException when it is not archived in the first place.
     */
    public function restore(TechnicianReport $report): TechnicianReport
    {
        if (! $report->isArchived()) {
            throw new RuntimeException('That report is not archived.');
        }

        $report->forceFill([
            'is_archived' => false,
            'archived_at' => null,
            'archived_by' => null,
        ])->save();

        $this->activityLogger->record(
            ActivityLog::REPORT_RESTORED,
            null,
            sprintf(
                'Restored %s (%s) on %s.',
                $report->displayCode(),
                $report->report_title,
                $report->project?->reference_no ?? 'a removed project'
            ),
            $report
        );

        return $report;
    }
}
