<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\SpecialtyRequest;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Everything the Admin and Super Admin dashboard counts and lists.
 *
 * The dashboard is deliberately short: a strip of figures, the work that is
 * coming, the doors into the modules, who is on site today, and what just
 * happened. Anything that needed a paragraph to explain belongs on the
 * module's own page, which is one click away.
 *
 * Two rules run through all of it:
 *
 *   - Counting happens in SQL. A dashboard that loads every project to count
 *     five statuses gets slower every week the business is open, and this one
 *     is the page people land on.
 *   - Every figure here is about projects, which both administrative roles
 *     may read. Anything role-specific belongs on the module that owns it.
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
     * Cache one computed figure.
     *
     * IMPORTANT: `$compute` must return arrays and scalars only - never a
     * Collection, a model or any other object.
     *
     * Every cache store but `array` serialises what it is given, and an object
     * read back before its class is loaded comes out as a
     * __PHP_Incomplete_Class: the first method call on it then fails with
     * "tried to call a method on an incomplete object". Arrays have no such
     * problem, and a caller that wants a Collection can wrap the array itself.
     *
     * @template T of array|scalar|null
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
     * Every one of them is a project figure, which both administrative roles
     * may read - so unlike the rest of this class, nothing here is narrowed by
     * who is asking.
     *
     * @return array<int, array{key: string, label: string, value: int, url: ?string, tone: string}>
     */
    public function summary(): array
    {
        $counts = $this->projectCounts();
        $projects = route('super-admin.projects');

        $cards = [
            $this->card('total_projects', 'Total Projects', $counts['total'], $projects),
            // The one figure that answers "what is happening right now".
            $this->card('active_today', 'Active Today', $counts['active_today'], route('super-admin.schedules.index'), 'today'),
            $this->card('ongoing', 'Ongoing', $counts['ongoing'], $projects, 'ongoing'),
            $this->card('pending', 'Pending', $counts['pending'], $projects, 'pending'),
            $this->card('overdue', 'Overdue', $counts['overdue'], $projects, 'overdue'),
            $this->card('completed', 'Completed', $counts['completed'], $projects, 'completed'),
        ];

        // Shown only when something is actually sitting with a client, for the
        // same reason the specialty queue below is: a card reading zero is a
        // card in the way.
        // Shown only when something is actually paused, on the same terms as
        // the card below it: a figure reading zero is a card in the way.
        if (($counts['on_hold'] ?? 0) > 0) {
            $cards[] = $this->card('on_hold', 'On Hold', $counts['on_hold'], $projects.'?status=on_hold', 'muted');
        }

        if ($counts['awaiting_confirmation'] > 0) {
            $cards[] = $this->card(
                'awaiting_confirmation',
                'Awaiting Client Confirmation',
                $counts['awaiting_confirmation'],
                $projects,
                'pending'
            );
        }

        // Shown only when there is something waiting, so an empty queue does
        // not take up a card saying zero.
        $waiting = $this->pendingSpecialtyRequests();

        if ($waiting > 0) {
            $cards[] = $this->card(
                'specialty_requests',
                'Specialty Requests',
                $waiting,
                route('super-admin.technicians.index'),
                'pending'
            );
        }

        return $cards;
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

            // Paused work is counted once, under On Hold, and taken back out of
            // whichever figure its stored status would otherwise land it in.
            // Putting a project on hold sets that status to Unscheduled, so
            // without this every held project reads as Pending here while its
            // badge - and now its own tab - say On Hold.
            $onHold = Project::query()
                ->where('is_archived', false)
                ->where('on_hold', true)
                ->whereNotIn('status', Project::READ_ONLY_STATUSES)
                ->count();

            $heldPending = Project::query()
                ->where('is_archived', false)
                ->where('on_hold', true)
                ->whereIn('status', ['pending', 'unscheduled'])
                ->count();

            $heldOngoing = Project::query()
                ->where('is_archived', false)
                ->where('on_hold', true)
                ->where('status', 'ongoing')
                ->count();

            $pending = (int) ($byStatus['pending'] ?? 0) + (int) ($byStatus['unscheduled'] ?? 0) - $heldPending;
            $ongoing = (int) ($byStatus['ongoing'] ?? 0) - $heldOngoing;
            $awaiting = (int) ($byStatus[Project::STATUS_AWAITING_CLIENT_CONFIRMATION] ?? 0);

            return [
                'total' => (int) $byStatus->sum(),
                'pending' => max(0, $pending - ($overdue - $overdueOngoing)),
                'ongoing' => max(0, $ongoing - $overdueOngoing),
                'overdue' => $overdue,
                'on_hold' => $onHold,
                // Finished work, counted as finished. Excluding it would make
                // the Completed figure disagree with the Completed tab on the
                // projects table, which now lists both.
                'completed' => (int) ($byStatus['completed'] ?? 0) + $awaiting,
                // Kept separately as well, for the card that says how many are
                // sitting with a client rather than with the company.
                'awaiting_confirmation' => $awaiting,
                'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
                'active_today' => $this->activeTodayCount(),
            ];
        });
    }

    /**
     * Projects with a crew on them today: open work whose booked date range
     * covers the current date.
     *
     * A project can hold several ranges, so the count is of distinct projects
     * rather than of schedule rows - two ranges covering today is still one
     * job happening.
     */
    private function activeTodayCount(): int
    {
        // The rule itself lives on the model - see Project::scopeActiveToday()
        // and its row-level twin isActiveToday(), which is what puts the
        // ACTIVE TODAY flag on a projects table row in every portal. This
        // figure and those rows are therefore the same question asked once.
        //
        // It used to be a copy of that query stated here, measured against the
        // server's date rather than the office's, so for eight hours a day the
        // dashboard and the calendar disagreed about which day it was.
        return Project::query()->activeToday()->count();
    }

    /**
     * How many technicians are waiting on a specialty decision.
     */
    public function pendingSpecialtyRequests(): int
    {
        return $this->remember(
            'pendingSpecialtyRequests',
            fn (): int => SpecialtyRequest::query()->pending()->count()
        );
    }

    // ------------------------------------------------------------------
    // Urgent actions
    // ------------------------------------------------------------------

    /**
     * What is waiting on somebody, and where it is fixed.
     *
     * This replaced Quick Actions, which was a row of doors into modules the
     * sidebar already offers - the same six links, one row lower. A dashboard
     * is opened to find out what needs doing, so this answers that instead:
     * every entry is a real backlog with a real count, and an entry with
     * nothing in it is absent rather than drawn as a zero.
     *
     * Each url opens the page ALREADY filtered to the records behind the
     * figure, so the reader lands on the work rather than on a list to search.
     * The three project entries point at the projects table's attention tabs -
     * see Project::ATTENTION_TABS - which exist for exactly this.
     *
     * Not cached: these are read once per dashboard load, they are the figures
     * most likely to be stale a moment after they are computed, and the whole
     * point of the section is that it stops mentioning something the moment it
     * is dealt with.
     *
     * @return array<int, array{key: string, label: string, detail: ?string, count: int, icon: string, url: string}>
     */
    public function urgentActions(): array
    {
        $projects = route('super-admin.projects');
        $schedules = route('super-admin.schedules.index');
        $outsideHours = $this->partialDaysOutsideHours();
        // Every task, unfiltered: an administrator reads the whole board. A
        // lead runs the same figure over their own projects instead - see
        // TaskAssignmentGaps, which is handed the scope rather than choosing
        // one.
        $unassignedTasks = Task::query()->needsAssignment()->count();

        return collect([
            [
                'key' => 'unscheduled_projects',
                'count' => Project::query()->missingSchedule()->count(),
                'singular' => 'Unscheduled Project',
                'plural' => 'Unscheduled Projects',
                'icon' => 'bi-calendar-x',
                'url' => $projects.'?status=unscheduled',
            ],
            [
                'key' => 'overdue_projects',
                'count' => Project::query()->overdue()->count(),
                'singular' => 'Overdue Project',
                'plural' => 'Overdue Projects',
                'icon' => 'bi-clock-history',
                'url' => $projects.'?status=overdue',
            ],
            [
                // Counted as projects rather than as people: the assignment is
                // what needs attention, and the row it opens shows which
                // technician it is.
                'key' => 'inactive_technicians',
                'count' => Project::query()->withInactiveCrew()->count(),
                'singular' => 'Inactive Technician in a Project',
                'plural' => 'Inactive Technicians in Projects',
                'icon' => 'bi-person-exclamation',
                'url' => $projects.'?status=inactive_crew',
            ],
            [
                'key' => 'specialty_requests',
                'count' => $this->pendingSpecialtyRequests(),
                'singular' => 'Specialty Change Request',
                'plural' => 'Specialty Change Requests',
                'icon' => 'bi-patch-question',
                // Opens the Technicians table narrowed to the people waiting,
                // and the request itself when there is only one.
                'url' => route('super-admin.technicians.index').'?specialty=pending',
            ],
            [
                'key' => 'pending_inquiries',
                'count' => $this->pendingInquiries(),
                'singular' => 'Pending Inquiry',
                'plural' => 'Pending Inquiries',
                'icon' => 'bi-envelope-exclamation',
                // Configuration opens on its Inquiries tab, already filtered
                // to the messages still waiting on somebody.
                'url' => route('super-admin.configuration.index').'?inquiries='.Inquiry::FILTER_PENDING,
            ],
            [
                // Bookings the working day no longer covers. Only work still to
                // come, and only on live projects: a partial day already worked
                // outside today's window is the record of a day that happened.
                'key' => 'partial_days_outside_hours',
                'count' => $outsideHours->count(),
                'singular' => 'Partial Day Outside Working Hours',
                'plural' => 'Partial Days Outside Working Hours',
                'icon' => 'bi-clock-history',
                // Straight to the booking: the schedules page with that
                // project's editor already open, where the row flags itself.
                'url' => $outsideHours->isEmpty()
                    ? $schedules
                    : $schedules.'?openSchedule='.$outsideHours->first()->project_id,
            ],
            [
                'key' => 'projects_without_technicians',
                'count' => Project::query()->missingTechnicians()->count(),
                'singular' => 'Project Without Technicians',
                'plural' => 'Projects Without Technicians',
                'icon' => 'bi-people',
                'url' => $projects.'?status=no_technicians',
            ],
            [
                // Open tasks that cannot proceed: nobody holds them, they have
                // no dates, or neither. Counted by the one rule both portals
                // read - Task::scopeNeedsAssignment() - so this figure and the
                // lead's own alert can never describe different work.
                //
                // The label does not lead with the number the way its siblings
                // do, because the number needs a qualifier: "5 Unassigned
                // Tasks" would say four of them are missing a technician when
                // some are only missing a date. The detail line carries it.
                'key' => 'unassigned_tasks',
                'count' => $unassignedTasks,
                'label' => $unassignedTasks === 1 ? 'Unassigned Task' : 'Unassigned Tasks',
                'detail' => TaskAssignmentGaps::dashboardSummary($unassignedTasks),
                'icon' => 'bi-clipboard-x',
                // Opens the Tasks board with the attention filter already on,
                // so the reader lands on exactly these tasks with each one
                // saying what it is missing.
                'url' => route('super-admin.tasks.index').'?attention=all',
            ],
        ])
            ->filter(fn (array $action): bool => $action['count'] > 0)
            ->map(fn (array $action): array => [
                'key' => $action['key'],
                // "3 Unscheduled Projects" / "1 Overdue Project" - the whole
                // message, so the view prints one string and cannot get the
                // agreement wrong. An entry that cannot be worded that way
                // states its own label and explains the number underneath.
                'label' => $action['label']
                    ?? $action['count'].' '.($action['count'] === 1 ? $action['singular'] : $action['plural']),
                'detail' => $action['detail'] ?? null,
                'count' => $action['count'],
                'icon' => $action['icon'],
                'url' => $action['url'],
            ])
            ->values()
            ->all();
    }

    /**
     * Partial-day bookings still to come whose hours the configured window no
     * longer covers.
     *
     * Narrowing Partial Day Start/End Hour in Project Settings deliberately
     * leaves existing bookings where they are - work already promised is not
     * moved by a setting. That is right, and it is also exactly how a booking
     * quietly stops matching the working day nobody meant it to fall outside
     * of, so the ones that can still be put right are surfaced rather than
     * left to be noticed.
     *
     * Live projects only. A completed, cancelled or archived project's dates
     * are its record, and there is nothing to correct on one.
     *
     * @return Collection<int, Schedule>
     */
    public function partialDaysOutsideHours(): Collection
    {
        return Schedule::query()
            ->upcomingPartialDay()
            ->whereHas('project', fn ($project) => $project
                ->where('is_archived', false)
                ->whereIn('status', Project::DERIVED_LIVE_STATUSES))
            ->orderBy('start_datetime')
            // The hour test lives on the model, so this list and the flag the
            // row draws on itself are the same question asked once.
            ->get()
            ->filter(fn (Schedule $schedule): bool => $schedule->needsHourCorrection())
            ->values();
    }

    /**
     * How many enquiries nobody has dealt with yet.
     *
     * New and In Progress both count: an enquiry someone has picked up is
     * still open work. Responded and Closed are finished, and the archive is
     * out of the working list entirely. Counted through the same scope the
     * Inquiries table filters by, so this figure and the list it opens can
     * never describe different rows - see Inquiry::PENDING_STATUSES.
     */
    public function pendingInquiries(): int
    {
        return Inquiry::query()->active()->pending()->count();
    }

    // ------------------------------------------------------------------
    // Lists
    // ------------------------------------------------------------------

    /**
     * Who is on site today: the technicians whose crew is booked on a date
     * range covering the current date.
     *
     * "Active or scheduled to work today" is decided by exactly the scheduling
     * logic the rest of the system uses - a booked date range on a project
     * that is live, not paused and not finished - so this panel and the Active
     * Today figure above it can never describe two different days' work. A
     * technician booked on two projects today is listed once, with both jobs
     * named.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function activeTechniciansToday(int $limit = 5): Collection
    {
        // The cached value is a plain array, not the Collection this returns -
        // see remember()'s note on why nothing but arrays and scalars go in.
        $rows = $this->remember('activeTechniciansToday', function (): array {
            // Project::scopeActiveToday() decides what "on site today" means,
            // here as everywhere else, so this panel and the figure above it
            // cannot describe two different days' work.
            return Technician::query()
                ->with('account')
                ->whereHas('account', fn ($query) => $query->whereIn('role', User::TECHNICIAN_ROLES))
                ->whereHas('projectTechnicians.project', fn ($project) => $project->activeToday())
                ->with(['projectTechnicians.project' => fn ($project) => $project->activeToday()])
                ->get()
                ->map(fn (Technician $technician): array => [
                    'name' => $technician->name,
                    'role' => $technician->account?->roleLabel() ?? 'Technician',
                    'avatar_url' => $technician->account?->avatarUrl(),
                    // What they are on today, so the panel says where somebody
                    // is rather than only that they are busy.
                    'projects' => $technician->projectTechnicians
                        ->map(fn ($assignment): ?string => $assignment->project?->reference_no
                            ?: $assignment->project?->name)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ])
                ->filter(fn (array $row): bool => $row['projects'] !== [])
                ->sortBy(fn (array $row): string => mb_strtolower($row['name']))
                ->values()
                ->all();
        });

        return collect($rows)->take($limit)->values();
    }

    /**
     * How many technicians activeTechniciansToday() found, before the panel's
     * limit is applied - the figure the heading prints.
     */
    public function activeTechnicianCountToday(): int
    {
        return $this->activeTechniciansToday(PHP_INT_MAX)->count();
    }

    /**
     * Who is carrying how much: ongoing projects per technician, busiest
     * first. The dashboard shows the top few; the Technicians page has them
     * all.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function technicianWorkload(int $limit = 4): Collection
    {
        // The cached value is a plain array, not the Collection this returns -
        // see remember()'s note on why nothing but arrays and scalars go in.
        $rows = $this->remember('technicianWorkload', function (): array {
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
                ->values()
                ->all();
        });

        return collect($rows)->take($limit)->values();
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
                ->whereNotIn('status', Project::READ_ONLY_STATUSES))
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
                    // The card shows this instead of the two dates when the
                    // booking is only part of a day, where "Aug 6 – Aug 6"
                    // would say less than nothing.
                    'schedule_label' => $schedule->describe(),
                    'is_partial_day' => $schedule->isPartialDay(),
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
