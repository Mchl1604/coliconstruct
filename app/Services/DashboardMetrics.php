<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\SpecialtyRequest;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Everything the Admin and Super Admin dashboard counts and lists.
 *
 * The dashboard is deliberately short: a strip of figures, one ring, the work
 * that is coming, who is carrying it, and what just happened. Anything that
 * needed a paragraph to explain belongs on the module's own page, which is one
 * click away.
 *
 * Two rules run through all of it:
 *
 *   - Counting happens in SQL. A dashboard that loads every project to count
 *     five statuses gets slower every week the business is open, and this one
 *     is the page people land on.
 *   - What an Admin may not read, they are not given. The archive figures are
 *     absent from their payload rather than hidden by the view.
 *
 * Results are cached briefly and the cache is versioned: any write to a
 * project, an account, a schedule or a specialty request bumps the version
 * (see AppServiceProvider), so a change is on the dashboard immediately rather
 * than when a timer runs out.
 */
class DashboardMetrics
{
    /**
     * How long a computed figure survives if nothing changes.
     *
     * Short on purpose: the version bump is what makes an edit appear at once,
     * and this only covers the case of many readers and no writers.
     */
    private const TTL_SECONDS = 60;

    private const VERSION_KEY = 'dashboard.version';

    /**
     * The statuses the ring and the figures use, with the colour each one
     * wears. Soft rather than saturated, to match the rest of the page.
     *
     * @var array<string, array{label: string, colour: string}>
     */
    public const STATUS_COLOURS = [
        'completed' => ['label' => 'Completed', 'colour' => '#7c6bd6'],
        'ongoing' => ['label' => 'Ongoing', 'colour' => '#5aa9e6'],
        'overdue' => ['label' => 'Overdue', 'colour' => '#f28b6b'],
        'pending' => ['label' => 'Pending', 'colour' => '#f3c969'],
        'cancelled' => ['label' => 'Cancelled', 'colour' => '#b8c1cc'],
    ];

    // ------------------------------------------------------------------
    // Cache
    // ------------------------------------------------------------------

    /**
     * Invalidate every cached figure.
     *
     * Bumping a version rather than forgetting keys means no caller has to
     * know which figures a given write affected.
     */
    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $compute
     * @return T
     */
    private function remember(string $key, callable $compute)
    {
        return Cache::remember(
            sprintf('dashboard.v%d.%s', self::version(), $key),
            self::TTL_SECONDS,
            $compute
        );
    }

    // ------------------------------------------------------------------
    // Summary
    // ------------------------------------------------------------------

    /**
     * The small figures across the top of the page.
     *
     * Each is a label and a number and nothing else, with the route it opens.
     *
     * @return array<int, array{key: string, label: string, value: int, url: ?string, tone: string}>
     */
    public function summary(User $viewer): array
    {
        $counts = $this->projectCounts();
        $people = $this->peopleCounts();
        $projects = route('super-admin.projects');

        $cards = [
            $this->card('total_projects', 'Total Projects', $counts['total'], $projects),
            $this->card('ongoing', 'Ongoing', $counts['ongoing'], $projects, 'ongoing'),
            $this->card('pending', 'Pending', $counts['pending'], $projects, 'pending'),
            $this->card('overdue', 'Overdue', $counts['overdue'], $projects, 'overdue'),
            $this->card('completed', 'Completed', $counts['completed'], $projects, 'completed'),
            $this->card('clients', 'Clients', $people['clients'], route('super-admin.configuration.index')),
            $this->card('technicians', 'Technicians', $people['technicians'], route('super-admin.technicians.index')),
        ];

        // Shown only when there is something waiting, so an empty queue does
        // not take up a card saying zero.
        if ($people['pending_specialty_requests'] > 0) {
            $cards[] = $this->card(
                'specialty_requests',
                'Specialty Requests',
                $people['pending_specialty_requests'],
                route('super-admin.technicians.index', ['tab' => 'specialty-requests']),
                'pending'
            );
        }

        if (! $viewer->isSuperAdmin()) {
            return $cards;
        }

        // The archive belongs to the owner.
        return array_merge($cards, [
            $this->card('archived_projects', 'Archived Projects', $counts['archived'], route('super-admin.projects.archived')),
            $this->card('archived_accounts', 'Archived Accounts', $people['archived_accounts'], route('super-admin.configuration.index')),
        ]);
    }

    /**
     * @return array{key: string, label: string, value: int, url: ?string, tone: string}
     */
    private function card(string $key, string $label, int $value, ?string $url = null, string $tone = 'muted'): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'url' => $url, 'tone' => $tone];
    }

    /**
     * Every project figure in one pass rather than one query per card.
     *
     * Overdue is derived - the last scheduled day has passed but the project
     * is still open - so it is counted by the model's own scope rather than
     * by a column, and then removed from Pending and Ongoing so the figures
     * describe the work instead of double-counting it.
     *
     * @return array<string, int>
     */
    public function projectCounts(): array
    {
        return $this->remember('projectCounts', function (): array {
            $byStatus = Project::query()
                ->where('is_archived', false)
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $overdue = Project::query()->overdue()->count();
            $overdueOngoing = Project::query()->overdue()->where('status', 'ongoing')->count();

            $pending = (int) ($byStatus['pending'] ?? 0) + (int) ($byStatus['not_yet_scheduled'] ?? 0);
            $ongoing = (int) ($byStatus['ongoing'] ?? 0);

            return [
                'total' => (int) $byStatus->sum(),
                'pending' => max(0, $pending - ($overdue - $overdueOngoing)),
                'ongoing' => max(0, $ongoing - $overdueOngoing),
                'overdue' => $overdue,
                'completed' => (int) ($byStatus['completed'] ?? 0),
                'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
                'archived' => Project::query()
                    ->where(fn ($query) => $query->where('is_archived', true)->orWhere('status', 'archived'))
                    ->count(),
            ];
        });
    }

    /**
     * The headcounts, again in one pass over the users table.
     *
     * @return array<string, int>
     */
    public function peopleCounts(): array
    {
        return $this->remember('peopleCounts', function (): array {
            $byRole = User::query()
                ->notArchived()
                ->selectRaw('role, count(*) as aggregate')
                ->groupBy('role')
                ->pluck('aggregate', 'role');

            return [
                'clients' => (int) ($byRole[User::ROLE_CLIENT] ?? 0),
                'technicians' => (int) ($byRole['technician'] ?? 0) + (int) ($byRole[User::ROLE_LEAD_TECHNICIAN] ?? 0),
                'lead_technicians' => (int) ($byRole[User::ROLE_LEAD_TECHNICIAN] ?? 0),
                'admins' => (int) ($byRole[User::ROLE_ADMIN] ?? 0),
                'super_admins' => (int) ($byRole[User::ROLE_SUPER_ADMIN] ?? 0),
                'archived_accounts' => User::query()->archived()->count(),
                'pending_specialty_requests' => SpecialtyRequest::query()->pending()->count(),
            ];
        });
    }

    // ------------------------------------------------------------------
    // The ring
    // ------------------------------------------------------------------

    /**
     * Where the work stands, as a share of the whole.
     *
     * The percentages are computed here rather than in the browser so the
     * legend is readable the instant the page paints - the ring beside it is
     * decoration over the same numbers.
     *
     * @return array<int, array{key: string, label: string, colour: string, value: int, percent: int}>
     */
    public function statusBreakdown(): array
    {
        $counts = $this->projectCounts();
        $total = max(1, (int) $counts['total']);

        return collect(self::STATUS_COLOURS)
            ->map(fn (array $status, string $key): array => [
                'key' => $key,
                'label' => $status['label'],
                'colour' => $status['colour'],
                'value' => (int) ($counts[$key] ?? 0),
                'percent' => (int) round(((int) ($counts[$key] ?? 0)) / $total * 100),
            ])
            // A status nobody is in adds a legend row that says nothing.
            ->filter(fn (array $slice): bool => $slice['value'] > 0)
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Lists
    // ------------------------------------------------------------------

    /**
     * Who is carrying how much: ongoing projects per technician, busiest
     * first. The dashboard shows the top few; the Technicians page has them
     * all.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function technicianWorkload(int $limit = 4): Collection
    {
        return $this->remember('technicianWorkload', function (): Collection {
            return Technician::query()
                ->with('account')
                ->whereHas('account', fn ($query) => $query->whereIn('role', User::TECHNICIAN_ROLES))
                ->withCount(['projectTechnicians as ongoing_count' => function ($query): void {
                    $query->whereHas('project', fn ($project) => $project
                        ->where('is_archived', false)
                        ->whereIn('status', Project::ACTIVE_PROJECT_STATUSES));
                }])
                ->get()
                ->map(fn (Technician $technician): array => [
                    'name' => $technician->name,
                    'role' => $technician->account?->roleLabel() ?? 'Technician',
                    'avatar_url' => $technician->account?->avatarUrl(),
                    'value' => (int) $technician->ongoing_count,
                ])
                ->sortByDesc('value')
                ->values();
        })->take($limit)->values();
    }

    /**
     * Today's work and what is coming, nearest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function upcomingSchedule(int $limit = 3): Collection
    {
        $today = CarbonImmutable::today();

        return Schedule::query()
            ->with(['project.clients', 'project.projectTypes', 'project.tasks', 'project.projectTechnicians.technician.account'])
            ->whereHas('project', fn ($query) => $query
                ->where('is_archived', false)
                ->whereNotIn('status', ['completed', 'cancelled', 'archived']))
            ->whereDate('end_datetime', '>=', $today->toDateString())
            ->orderBy('start_datetime')
            ->limit($limit)
            ->get()
            ->map(function (Schedule $schedule): ?array {
                $project = $schedule->project;

                if (! $project) {
                    return null;
                }

                $client = $project->clients->first();
                $tasks = $project->tasks;
                $done = $tasks->where('status', 'completed')->count();

                return [
                    'project_id' => $project->project_id,
                    'reference_no' => $project->reference_no,
                    'title' => $client?->company_name ?: ($client?->fullname ?: $project->name),
                    'type' => $project->projectTypes->pluck('type_name')->first(),
                    'status_label' => $project->statusLabel(),
                    'is_overdue' => $project->isOverdue(),
                    'start' => CarbonImmutable::parse($schedule->start_datetime),
                    'end' => CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime),
                    // Tasks done out of tasks set: the nearest thing this
                    // system has to "how far along is it".
                    'done' => $done,
                    'total' => $tasks->count(),
                    'percent' => $tasks->count() > 0 ? (int) round($done / $tasks->count() * 100) : 0,
                    'team' => $project->projectTechnicians
                        ->map(fn ($assignment): ?string => $assignment->technician?->account?->avatarUrl())
                        ->filter()
                        ->take(3)
                        ->values()
                        ->all(),
                    'url' => route('super-admin.projects.show', $project->project_id),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Where an activity log entry points, when it points anywhere.
     *
     * Only a project is reachable: a task or a report is read inside the
     * project it belongs to, and the entry does not record which.
     */
    public function activityUrl(?string $recordType, ?int $recordId): ?string
    {
        if ($recordType !== 'Project' || ! $recordId) {
            return null;
        }

        return route('super-admin.projects.show', $recordId);
    }
}
