<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\DashboardMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The Admin and Super Admin dashboard.
 *
 * Short on purpose: a strip of figures, the work that is coming, what is
 * waiting on somebody, who is carrying it, and what just happened. Everything
 * here is either a number somebody checks daily or a backlog with the page
 * that clears it one click away.
 *
 * What an Admin may not read is not sent. The archive figures and another
 * administrator's activity are absent from their payload rather than hidden by
 * the view.
 */
class DashboardController extends Controller
{
    /** How many entries the Recent Activity list shows. */
    private const ACTIVITY_LIMIT = 6;

    public function __construct(private readonly DashboardMetrics $metrics) {}

    public function index(Request $request)
    {
        $viewer = $request->user();
        $counts = $this->metrics->projectCounts();

        return view('super-admin.dashboard', [
            // Handed down rather than re-resolved with auth() in the view:
            // one place decides who is reading this page.
            'viewer' => $viewer,
            'summaryCards' => $this->metrics->summary(),
            'totalProjects' => $counts['total'],
            'upcoming' => $this->metrics->upcomingSchedule(),
            // Who is on site today, in place of the workload ranking. "How
            // many projects is somebody carrying" is a Technicians page
            // question; "who is working today" is the one a dashboard is
            // opened to answer.
            'activeTechnicians' => $this->metrics->activeTechniciansToday(),
            'activeTechnicianCount' => $this->metrics->activeTechnicianCountToday(),
            // What is waiting on somebody, and where each backlog is cleared.
            // This replaced Quick Actions, which was a second copy of the
            // sidebar - see DashboardMetrics::urgentActions().
            'urgentActions' => $this->metrics->urgentActions(),
            'recentActivity' => $this->recentActivity($viewer),
        ]);
    }

    /**
     * The summary figures on their own, for a refresh without a reload.
     */
    public function summary(): JsonResponse
    {
        return response()->json(['cards' => $this->metrics->summary()]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The last few things that happened, as this reader is allowed to see
     * them - visibleTo() keeps an Admin out of another administrator's trail.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function recentActivity(User $viewer)
    {
        return ActivityLog::query()
            ->visibleTo($viewer)
            ->latest('created_at')
            ->orderByDesc('activity_log_id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'time' => $log->created_at,
                'actor_name' => $log->actor_name,
                'action' => $log->action,
                'module' => $log->module ?? ActivityLog::moduleFor($log->action),
                'url' => $this->metrics->activityUrl($log->record_type, $log->record_id ? (int) $log->record_id : null),
            ]);
    }
}
