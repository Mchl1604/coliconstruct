<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The technician and lead technician portal: the same pages for both, with
 * My Reports added for a lead.
 *
 * Every query here is anchored to the signed-in account's own technician
 * record, so a technician can only ever see their own work. Nothing takes an
 * id from the request - there is nothing to tamper with.
 */
class TechnicianPortalController extends Controller
{
    /**
     * Statuses that keep a project off the portal entirely: finished and
     * abandoned work is not an assignment anyone still owes anything on.
     *
     * @var array<int, string>
     */
    private const HIDDEN_STATUSES = ['cancelled', 'archived'];

    public function schedule()
    {
        $technician = $this->technician();
        $projects = $this->assignedProjects($technician);

        // Flatten every project's schedule ranges into one dated list, so the
        // page reads as a diary rather than a project index.
        $entries = $projects
            ->filter(fn (Project $project): bool => $project->showsOnCalendar())
            ->flatMap(fn (Project $project) => $project->schedules->map(fn ($schedule): array => [
                'project' => $project,
                'start' => CarbonImmutable::parse($schedule->start_datetime),
                'end' => CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime),
            ]))
            ->sortBy(fn (array $entry) => $entry['start'])
            ->values();

        $today = CarbonImmutable::today();

        return view('technician.schedule', [
            'entries' => $entries,
            'today' => $today,
            'activeCount' => $entries->filter(
                fn (array $entry): bool => $entry['start']->lte($today) && $entry['end']->gte($today)
            )->count(),
            'upcomingCount' => $entries->filter(
                fn (array $entry): bool => $entry['start']->gt($today)
            )->count(),
        ]);
    }

    public function projects()
    {
        $technician = $this->technician();
        $projects = $this->assignedProjects($technician);

        // One grouped query rather than a task count per project.
        $taskCounts = Task::query()
            ->where('technician_id', $technician->technician_id)
            ->whereIn('project_id', $projects->pluck('project_id'))
            ->selectRaw('project_id, count(*) as total')
            ->groupBy('project_id')
            ->pluck('total', 'project_id');

        return view('technician.projects', [
            'projects' => $projects,
            'taskCounts' => $taskCounts,
        ]);
    }

    public function tasks()
    {
        $technician = $this->technician();

        $tasks = Task::query()
            ->with('project')
            ->where('technician_id', $technician->technician_id)
            ->orderByRaw("case when status = 'ongoing' then 0 when status = 'pending' then 1 else 2 end")
            ->orderBy('due_date')
            ->get();

        return view('technician.tasks', [
            'tasks' => $tasks,
            'today' => CarbonImmutable::today(),
        ]);
    }

    /**
     * Lead technicians only - the route group enforces that.
     */
    public function reports()
    {
        $technician = $this->technician();

        $reports = TechnicianReport::query()
            ->with(['project', 'images'])
            ->where('technician_id', $technician->technician_id)
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->get();

        return view('technician.reports', ['reports' => $reports]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The signed-in account's technician record.
     *
     * Configuration creates one alongside every technician account, but an
     * account whose role was granted directly in the database might not have
     * one yet, so it is created on demand rather than failing the page.
     */
    private function technician(): Technician
    {
        $user = request()->user();

        return Technician::firstOrCreate(
            ['account_id' => $user->id],
            ['role' => $user->role]
        );
    }

    /**
     * Every live project this technician is assigned to, with the relations
     * the portal pages read, loaded once.
     *
     * @return Collection<int, Project>
     */
    private function assignedProjects(Technician $technician): Collection
    {
        return Project::query()
            ->with(['clients', 'schedules'])
            ->where('is_archived', false)
            ->whereNotIn('status', self::HIDDEN_STATUSES)
            ->whereHas(
                'projectTechnicians',
                fn ($query) => $query->where('technician_id', $technician->technician_id)
            )
            ->orderByDesc('project_id')
            ->get();
    }
}
