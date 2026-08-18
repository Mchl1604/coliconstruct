<?php

namespace App\Http\Controllers;

use App\Mail\ContactInquiryMail;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Services\ActivityLogger;
use App\Services\ClientProjects;
use App\Services\EmailService;
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
        ]);
    }

    public function about()
    {
        return view('public.about', [
            'coreValues' => $this->content->lines('about.core_values'),
            'team' => $this->teamMembers(),
        ]);
    }

    public function contact()
    {
        return view('public.contact', [
            // The form is only offered when there is somewhere for it to go.
            // A form that silently drops what somebody typed is worse than no
            // form, which is why this page carried a disabled one for so long.
            'canSendInquiries' => $this->canSendInquiries(),
        ]);
    }

    /**
     * Send what somebody wrote on the Contact page.
     *
     * The one thing a stranger can make this application do, so it is the one
     * endpoint with no account behind it at all. What guards it:
     *
     *   - A throttle on the route, because a public POST that sends email is
     *     exactly what a spam script looks for.
     *   - A honeypot field no person ever sees or fills in. A bot that fills
     *     every input gives itself away, and is answered as though it had
     *     succeeded so it has nothing to learn from and nothing to retry.
     *   - Nothing is stored and nothing is trusted: the message is escaped
     *     into the email, and the enquirer's address travels as Reply-To
     *     rather than as the sender.
     */
    public function sendInquiry(Request $request)
    {
        if (! $this->canSendInquiries()) {
            return back()
                ->withInput()
                ->with('error', 'Messages cannot be sent from this page at the moment. Please email or call us instead.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            // The honeypot. Named for something a browser will not autofill
            // and a person will never see.
            'company_website' => ['nullable', 'size:0'],
        ], [
            'message.min' => 'Please tell us a little more - at least 10 characters.',
            'company_website.size' => 'That message could not be sent.',
        ]);

        // Answered as a success. A bot learns nothing, and a person who
        // somehow tripped it has already been told by validation.
        if (filled($request->input('company_website'))) {
            return back()->with('success', $this->inquiryThanks());
        }

        $sent = app(EmailService::class)->send(
            (string) config('mail.inquiries_to'),
            new ContactInquiryMail(
                trim($validated['name']),
                trim($validated['email']),
                trim($validated['subject']),
                trim($validated['message']),
            )
        );

        if (! $sent) {
            return back()
                ->withInput()
                ->with('error', 'Your message could not be sent just now. Please email or call us instead.');
        }

        // Recorded like every other action a person outside the system takes -
        // the same trail a failed sign-in leaves. It is the only record an
        // enquiry has, since there is no inquiries table to write to.
        app(ActivityLogger::class)->recordAnonymous(
            ActivityLog::CONTACT_INQUIRY_SENT,
            trim($validated['name']),
            null,
            sprintf(
                'A website enquiry was sent by %s (%s): %s',
                trim($validated['name']),
                trim($validated['email']),
                trim($validated['subject'])
            )
        );

        return back()->with('success', $this->inquiryThanks());
    }

    /**
     * Whether a message posted here would actually reach anybody.
     */
    private function canSendInquiries(): bool
    {
        return filled(config('mail.inquiries_to'))
            && app(EmailService::class)->isDeliverable();
    }

    private function inquiryThanks(): string
    {
        return 'Thank you - your message has been sent. We will come back to you shortly.';
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

        // The task list is not shown to a client: it is how the company
        // organises its own crew, and what the client follows is the
        // technicians' reports. The tasks relation is still loaded by
        // ClientProjects, because progressFor() counts them for the progress
        // bar - which is the one thing the list was really telling them.
        return view('public.project-details', [
            'project' => $record,
            'card' => $this->card($record),
            'client' => $record->clients->first(),
            // Contact Support. There is no inquiries table to post to, so the
            // client is pointed at the published channels rather than at a
            // form that would silently drop what they typed - the same reason
            // the Contact page's own form is disabled. Reaching support never
            // changes the project's status and never pauses the seven days.
            'supportEmail' => $this->content->get('contact.email'),
            'supportPhone' => $this->content->get('contact.phone'),
            // The client's tracker, so it leads the page. Newest first, with
            // the most recently filed breaking a same-day tie - re-stated here
            // rather than trusting the order the relation arrived in.
            'reports' => $record->reports
                ->sortByDesc(fn ($report): array => [$report->report_date, $report->id])
                ->values(),
            'ranges' => $record->schedules
                ->sortBy('start_datetime')
                ->map(fn ($schedule): array => [
                    'start' => $schedule->start_datetime,
                    'end' => $schedule->end_datetime ?? $schedule->start_datetime,
                    // A client should see the hours somebody is coming, not a
                    // date printed twice. Same formatter as every other screen.
                    'label' => $schedule->describe('M d, Y'),
                ])
                ->values(),
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The people shown on the About page.
     *
     * The names are one "Name | Role" list and the photographs are four image
     * fields, paired by position - the same arrangement the rest of the site
     * uses for repeatable content, so nobody has to add a table to name a
     * fifth person. A member listed beyond the fourth simply has no picture.
     *
     * @return Collection<int, array{name: string, role: string, photo: string|null}>
     */
    private function teamMembers(): Collection
    {
        return $this->content->lines('about.team')
            ->map(fn (array $member, int $index): array => [
                'name' => $member['title'],
                'role' => $member['description'],
                'photo' => $this->content->image('about.team_photo_'.($index + 1)),
            ]);
    }

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
            'short_status_label' => $project->shortStatusLabel(),
            'status_badge_class' => $project->statusBadgeClass(),
            'header_class' => $this->headerClass($project),
            // What the client has to do about this project, if anything. The
            // card shows a prompt and the details page shows the buttons, and
            // both read these rather than re-deriving the state.
            'awaiting_confirmation' => $project->isAwaitingClientConfirmation(),
            'confirmation_deadline' => $project->confirmationDeadline(),
            'confirmation_countdown' => $project->confirmationCountdown(),
            'completed_on' => $project->completed_at,
            'completion_summary' => $project->completion_summary,
            'completion_remarks' => $project->completion_remarks,
            'completion_method_label' => $project->completionMethodLabel(),
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
     * to the client who booked it. Work awaiting their confirmation is
     * finished work, so it files under Completed rather than adding a tab -
     * the card's own banner is what asks them to act on it.
     */
    private function filterStatus(Project $project): string
    {
        if ($project->isOverdue()) {
            return 'overdue';
        }

        return match ($project->status) {
            'unscheduled', 'pending' => 'pending',
            'ongoing' => 'ongoing',
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION, 'completed' => 'completed',
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
            'unscheduled' => 'project-card-header-pending',
            'pending' => 'project-card-header-scheduled',
            'ongoing' => 'project-card-header-progress',
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION => 'project-card-header-awaiting',
            'completed' => 'project-card-header-complete',
            'cancelled' => 'project-card-header-cancelled',
            default => 'project-card-header-pending',
        };
    }
}
