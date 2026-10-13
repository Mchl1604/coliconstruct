<?php

namespace App\Http\Controllers;

use App\Services\DashboardMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Admin and Super Admin dashboard.
 *
 * Short on purpose: a strip of figures, the work that is coming, what is
 * waiting on somebody, who is on site, and which tasks are due today or
 * running late. Everything here is either a number somebody checks daily or a
 * backlog with the page that clears it one click away.
 */
class DashboardController extends Controller
{
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
            // Today's tasks and the late ones. This replaced Recent Activity:
            // the log says what happened, and a dashboard is opened to see
            // what still needs doing. The full log stays in Configuration.
            'taskMonitoring' => $this->metrics->taskMonitoring(),
        ]);
    }

    /**
     * The summary figures on their own, for a refresh without a reload.
     */
    public function summary(): JsonResponse
    {
        return response()->json(['cards' => $this->metrics->summary()]);
    }
}
