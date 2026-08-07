<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ClientProjects;
use App\Services\SystemContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The public website: Home, About, Contact and My Projects.
 *
 * Not a line of the visible content lives here - every page reads it from
 * SystemContentService, which is what makes the whole site editable from
 * Configuration without touching a view.
 *
 * My Projects is the one page with a rule attached: a guest sees an invitation
 * to sign in, and a signed-in client sees only the work carrying their own
 * email address.
 */
class PublicSiteController extends Controller
{
    public function __construct(
        private readonly SystemContentService $content,
        private readonly ClientProjects $clientProjects
    ) {}

    public function home()
    {
        return view('public.home', [
            'services' => $this->content->lines('home.services'),
            'gallery' => $this->galleryImages(),
        ]);
    }

    public function about()
    {
        return view('public.about', [
            'coreValues' => $this->content->lines('about.core_values'),
            'photos' => collect(['about.photo_1', 'about.photo_2', 'about.photo_3'])
                ->map(fn (string $key): ?string => $this->content->image($key))
                ->filter()
                ->values(),
        ]);
    }

    public function contact()
    {
        return view('public.contact');
    }

    /**
     * The client's own projects, or an invitation to sign in.
     */
    public function myProjects(Request $request)
    {
        $user = $request->user();

        // A guest, or an employee who happens to be signed in, has no projects
        // of their own to show here. The two are told different things: one
        // needs to sign in, the other is already signed in as somebody this
        // page is not for.
        if (! $user || ! $user->isClient()) {
            return view('public.my-projects', [
                'projects' => collect(),
                'isClient' => false,
                'isGuest' => $user === null,
            ]);
        }

        $projects = $this->clientProjects->forUser($user);

        return view('public.my-projects', [
            'projects' => $projects,
            'isClient' => true,
            'isGuest' => false,
            'cards' => $projects->map(fn ($project): array => $this->card($project)),
        ]);
    }

    /**
     * One project, read only, and only if it is theirs.
     */
    public function projectDetails(Request $request, int $project)
    {
        $user = $request->user();

        abort_unless($user && $user->isClient(), 403);

        $record = $this->clientProjects->findForUser($user, $project);

        // A project belonging to somebody else is indistinguishable from one
        // that does not exist.
        abort_unless($record, 404);

        $tasks = $record->tasks->sortBy('due_date');

        return view('public.project-details', [
            'project' => $record,
            'card' => $this->card($record),
            'client' => $record->clients->first(),
            // The client's tracker, so it leads the page.
            'reports' => $record->reports->sortByDesc('report_date')->values(),
            'completedTasks' => $tasks->where('status', 'completed')->values(),
            'remainingTasks' => $tasks->whereNotIn('status', ['completed'])->values(),
            'ranges' => $record->schedules
                ->sortBy('start_datetime')
                ->map(fn ($schedule): array => [
                    'start' => $schedule->start_datetime,
                    'end' => $schedule->end_datetime ?? $schedule->start_datetime,
                ])
                ->values(),
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The documents a client may open on their own project, in the order the
     * buttons appear. Anything not listed here is not shown to them.
     *
     * @var array<string, string>
     */
    public const DOCUMENT_TITLES = [
        'assessment' => 'Assessment',
        'quotation' => 'Quotation',
        'contract' => 'Contract',
    ];

    /**
     * The facts a project card shows, gathered once so the grid and the
     * details page cannot describe the same project differently.
     *
     * @return array<string, mixed>
     */
    private function card(Project $project): array
    {
        $start = $project->schedules->min('start_datetime');
        $end = $project->schedules->max('end_datetime');
        $client = $project->clients->first();
        $clientName = $client?->company_name ?: $client?->fullname;

        return [
            'id' => $project->project_id,
            'reference_no' => $project->reference_no,
            'name' => $project->name,
            'service' => $project->projectTypes->pluck('type_name')->implode(', '),
            'client_name' => $clientName,
            'location' => $project->address,
            'description' => $project->description,
            'status' => $project->status,
            'status_label' => $project->statusLabel(),
            'status_badge_class' => $project->statusBadgeClass(),
            'header_class' => $this->headerClass($project),
            // What the My Projects filter bar matches on. Overdue is derived
            // rather than stored, so it travels as its own flag and the filter
            // key is the status the tab bar offers rather than the column.
            'is_overdue' => $project->isOverdue(),
            'filter_status' => $this->filterStatus($project),
            'search_text' => $this->searchText($project, $clientName),
            'start_date' => $start,
            'end_date' => $end,
            'lead_technician' => $this->clientProjects->leadTechnicianName($project),
            'technicians' => $project->projectTechnicians
                ->map(fn ($projectTechnician) => $projectTechnician->technician?->name)
                ->filter()
                ->values(),
            'progress' => $this->clientProjects->progressFor($project),
            'updated_at' => $project->updated_at,
            'url' => route('public.projects.show', $project->project_id),
        ];
    }

    /**
     * Which tab of the My Projects filter bar a project belongs under.
     *
     * Overdue is a tab of its own, so an overdue project is not also counted
     * as Ongoing - the same rule the Super Admin projects table applies.
     * "Not yet scheduled" work is booked but undated, which reads as Pending
     * to the client who booked it.
     */
    private function filterStatus(Project $project): string
    {
        if ($project->isOverdue()) {
            return 'overdue';
        }

        return match ($project->status) {
            'not_yet_scheduled', 'pending' => 'pending',
            'ongoing' => 'ongoing',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => $project->status,
        };
    }

    /**
     * Everything the My Projects search box matches a project on, flattened
     * into one lower-cased haystack the browser can scan without a request.
     */
    private function searchText(Project $project, ?string $clientName): string
    {
        return mb_strtolower(collect([
            $project->project_id,
            $project->reference_no,
            $project->name,
            $project->projectTypes->pluck('type_name')->implode(' '),
            $clientName,
            $project->address,
        ])->filter()->implode(' '));
    }

    /**
     * The card's coloured header, matching the status colours used across the
     * rest of the system.
     */
    private function headerClass(Project $project): string
    {
        if ($project->on_hold) {
            return 'project-card-header-hold';
        }

        return match ($project->status) {
            'not_yet_scheduled' => 'project-card-header-pending',
            'pending' => 'project-card-header-scheduled',
            'ongoing' => 'project-card-header-progress',
            'completed' => 'project-card-header-complete',
            'cancelled' => 'project-card-header-cancelled',
            default => 'project-card-header-pending',
        };
    }

    /**
     * @return Collection<int, string>
     */
    private function galleryImages()
    {
        return collect(range(1, 6))
            ->map(fn (int $index): ?string => $this->content->image('home.gallery_'.$index))
            ->filter()
            ->values();
    }
}
