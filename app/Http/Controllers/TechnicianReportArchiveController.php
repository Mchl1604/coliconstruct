<?php

namespace App\Http\Controllers;

use App\Models\TechnicianReport;
use App\Services\TechnicianReportArchive;
use Illuminate\Http\Request;
use Throwable;

/**
 * Archiving and restoring one technician report.
 *
 * Two endpoints, shared by every screen that offers the action: the Reports
 * page and Project Details, in the administrative portal and in the technician
 * portal alike. Archiving a report from any of them takes it off all of them,
 * because there is one place it happens rather than four.
 *
 * Authorization is TechnicianReportPolicy's and is asked here, on the way in -
 * not by the pages that draw the buttons. Hiding a button is a courtesy to the
 * person looking at it; this is the rule. A lead technician who edits the
 * page, replays the request with somebody else's report id, or calls the
 * endpoint directly is refused exactly as if they had pressed a button that
 * was never drawn.
 *
 * Both actions answer in whichever way they were asked: JSON for the tables
 * that act without a reload, and a redirect carrying a flashed toast for the
 * Blade forms on the two Project Details pages.
 */
class TechnicianReportArchiveController extends Controller
{
    public function __construct(private readonly TechnicianReportArchive $archive) {}

    public function archive(Request $request, TechnicianReport $report)
    {
        $this->authorize('archive', $report);

        try {
            $this->archive->archive($report->loadMissing('project'), $request->user());
        } catch (Throwable $exception) {
            return $this->respond(
                $request,
                $this->safeErrorMessage($exception, 'Unable to archive report. Nothing was changed.'),
                'error'
            );
        }

        return $this->respond($request, 'Report archived successfully.', 'success', $report);
    }

    public function restore(Request $request, TechnicianReport $report)
    {
        $this->authorize('restore', $report);

        try {
            $this->archive->restore($report->loadMissing('project'));
        } catch (Throwable $exception) {
            return $this->respond(
                $request,
                $this->safeErrorMessage($exception, 'Unable to restore report. Nothing was changed.'),
                'error'
            );
        }

        return $this->respond($request, 'Report restored successfully.', 'success', $report);
    }

    /**
     * One outcome, told in the caller's own terms.
     *
     * The message is deliberately the same either way: what a person is told
     * never depends on which screen they pressed the button on, and it never
     * mentions roles, columns or ids - see the messages above.
     */
    private function respond(
        Request $request,
        string $message,
        string $key,
        ?TechnicianReport $report = null
    ) {
        if ($request->expectsJson()) {
            return $key === 'error'
                ? response()->json(['error' => $message], 422)
                : response()->json([
                    'message' => $message,
                    'report' => [
                        'id' => $report?->id,
                        'is_archived' => (bool) $report?->is_archived,
                    ],
                ]);
        }

        return back()->with($key, $message);
    }
}
