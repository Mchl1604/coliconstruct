<?php

namespace App\Http\Controllers;

use App\Mail\ContactInquiryMail;
use App\Models\Inquiry;
use App\Models\Project;
use App\Services\ClientProjects;
use App\Services\EmailService;
use App\Services\InquiryService;
use App\Services\InquirySpamGuard;
use App\Services\SystemContentService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

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
        private readonly ClientProjects $clientProjects,
        private readonly InquirySpamGuard $spamGuard
    ) {}

    public function home()
    {
        return view('public.home', [
            'services' => $this->content->services(),
        ]);
    }

    public function about()
    {
        return view('public.about', [
            'coreValues' => $this->content->lines('about.core_values'),
            'owners' => $this->content->owners(),
        ]);
    }

    /**
     * The Contact page, whose form is always open.
     *
     * It used to be disabled unless a mailer was configured, because an
     * enquiry that could not be emailed went nowhere at all. It is stored now,
     * so a message reaches Configuration > Inquiries whether or not a mail
     * server is reachable, and there is nothing left for a closed form to
     * protect somebody from.
     */
    public function contact()
    {
        return view('public.contact');
    }

    /**
     * Take what somebody wrote on the Contact page.
     *
     * The one thing a stranger can make this application do, so it is the one
     * endpoint with no account behind it at all. What guards it:
     *
     *   - A honeypot field no person ever sees or fills in. A bot that fills
     *     every input gives itself away, and is answered as though it had
     *     succeeded so it has nothing to learn from and nothing to retry.
     *   - A window per IP address and two per email address, so neither a
     *     script nor somebody leaning on the button can fill the Inquiries
     *     tab with noise. See InquirySpamGuard.
     *   - A throttle on the route above those, which is a ceiling on requests
     *     rather than on enquiries - the same layering the verification routes
     *     use over OtpService's own per-address limits.
     *   - Length limits on every field, stated on the model so the form, the
     *     validator and the columns cannot drift apart.
     *
     * None of it is announced. The form carries no notice, no counter and no
     * cooldown: it looks like an ordinary contact form, and a visitor only
     * ever hears about a limit in the moment one actually stops them.
     *
     * What it does not do is as important as what it does: no account is
     * opened, no project is created, and the enquiry is linked to neither.
     * The record is the message.
     */
    public function sendInquiry(Request $request)
    {
        // Before validation rather than after it, so a bot is answered exactly
        // the same way whatever else it got wrong. Nothing is stored, nothing
        // is emailed, and nothing is counted against anybody's window.
        if ($this->spamGuard->tripsHoneypot($request)) {
            return back()->with('success', $this->inquiryThanks());
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.Inquiry::MAX_NAME],
            'email' => ['required', 'string', 'email', 'max:'.Inquiry::MAX_EMAIL],
            'subject' => ['required', 'string', 'max:'.Inquiry::MAX_SUBJECT],
            'message' => ['required', 'string', 'min:10', 'max:'.Inquiry::MAX_MESSAGE],
        ], [
            'message.min' => 'Please write at least 10 characters.',
        ]);

        try {
            $this->spamGuard->guard($request, $validated['email']);
        } catch (RuntimeException $exception) {
            // A toast, and what they typed is handed back, so somebody caught
            // by a colleague's submission on the same office connection has
            // only to wait rather than write the whole thing again.
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        try {
            $inquiry = app(InquiryService::class)->record($validated);
        } catch (Throwable $exception) {
            // Whatever went wrong is the company's problem, not the visitor's,
            // so it goes to the log and they are told something they can act
            // on. A raw database error must never reach a public page.
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Your message could not be sent just now. Please email or call us instead.');
        }

        // Counted only now that a row exists. An enquiry this application
        // failed to store cost the sender nothing, so it must not spend their
        // window either.
        $this->spamGuard->recordSubmission($request, $inquiry->email);

        // A copy to the company inbox, on top of the record. Best effort: the
        // enquiry is already saved and visible in Configuration, so a mail
        // server that is down costs a convenience rather than the message.
        if ($this->canSendInquiries()) {
            app(EmailService::class)->send(
                (string) config('mail.inquiries_to'),
                new ContactInquiryMail(
                    $inquiry->name,
                    $inquiry->email,
                    $inquiry->subject,
                    $inquiry->message,
                )
            );
        }

        return back()->with('success', $this->inquiryThanks());
    }

    /**
     * Whether a copy of an enquiry would actually reach the company's inbox.
     */
    private function canSendInquiries(): bool
    {
        return filled(config('mail.inquiries_to'))
            && app(EmailService::class)->isDeliverable();
    }

    private function inquiryThanks(): string
    {
        return 'Thank you - your message has been sent.';
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
            // Contact Support sends the client to the Contact Us page, which
            // is where every published channel is listed; the number is
            // repeated in the sentence beneath the buttons so somebody holding
            // a phone does not have to load a page to find it. Reaching
            // support never changes the project's status and never pauses the
            // seven days.
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
                    'label' => $schedule->describe(),
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
