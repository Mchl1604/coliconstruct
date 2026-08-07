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
 * Short on purpose: a strip of figures, one ring, the work that is coming, who
 * is carrying it, and what just happened. Everything here is either a number
 * somebody checks daily or a door into the module that owns it.
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
            'summaryCards' => $this->metrics->summary($viewer),
            // The ring's numbers are rendered as text and drawn as an arc from
            // the same array, so the legend is readable before any script runs.
            'statusBreakdown' => $this->metrics->statusBreakdown(),
            'totalProjects' => $counts['total'],
            'upcoming' => $this->metrics->upcomingSchedule(),
            'workload' => $this->metrics->technicianWorkload(),
            'recentActivity' => $this->recentActivity($viewer),
        ]);
    }

    /**
     * The summary figures on their own, for a refresh without a reload.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['cards' => $this->metrics->summary($request->user())]);
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
