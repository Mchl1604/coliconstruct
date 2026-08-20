<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\DashboardMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
            // The doors into the modules, narrowed to the ones this reader may
            // actually open.
            'quickActions' => $this->quickActions($viewer),
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
     * The modules this reader may open, as a strip of shortcuts.
     *
     * Every entry points at a route that already exists - nothing here is a
     * page of its own - and each carries the check that decides whether this
     * reader may reach it. An action the viewer cannot use is not rendered
     * disabled, it is absent: a dashboard offering a door somebody cannot walk
     * through is worse than one that does not mention it.
     *
     * Admin and Super Admin reach all six today, because the routes behind
     * them admit both roles. The predicates are stated anyway so narrowing one
     * later is a change here rather than a change in the view.
     *
     * @return array<int, array{key: string, label: string, icon: string, url: string}>
     */
    private function quickActions(User $viewer): array
    {
        $isAdministrator = in_array($viewer->role, User::ADMINISTRATOR_ROLES, true);

        return collect([
            [
                'key' => 'projects',
                'label' => 'Projects',
                'icon' => 'bi-folder2-open',
                'url' => route('super-admin.projects'),
                'allowed' => $isAdministrator,
            ],
            [
                'key' => 'schedules',
                'label' => 'Schedules',
                'icon' => 'bi-calendar-event',
                'url' => route('super-admin.schedules.index'),
                'allowed' => $isAdministrator,
            ],
            [
                'key' => 'technicians',
                'label' => 'Technicians',
                'icon' => 'bi-tools',
                'url' => route('super-admin.technicians.index'),
                'allowed' => $isAdministrator,
            ],
            [
                // Client accounts live in Configuration's User Management tab
                // rather than on a page of their own, so this opens that tab
                // at the Clients table instead of duplicating it.
                'key' => 'clients',
                'label' => 'Clients',
                'icon' => 'bi-building',
                'url' => route('super-admin.configuration.index').'#clients',
                'allowed' => $isAdministrator,
            ],
            [
                'key' => 'reports',
                'label' => 'Reports',
                'icon' => 'bi-graph-up',
                'url' => route('super-admin.reports.index'),
                'allowed' => $isAdministrator,
            ],
            [
                'key' => 'configuration',
                'label' => 'Configuration',
                'icon' => 'bi-sliders',
                'url' => route('super-admin.configuration.index'),
                'allowed' => $isAdministrator,
            ],
        ])
            ->filter(fn (array $action): bool => $action['allowed'])
            ->map(fn (array $action): array => Arr::except($action, 'allowed'))
            ->values()
            ->all();
    }

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
