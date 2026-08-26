<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Services\InquiryService;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Configuration - Inquiries: the messages written in from the public Contact
 * page, and what staff do about them.
 *
 * Its own controller rather than another wing of ConfigurationController, for
 * the same reason System Contents and Project Types have theirs: the tab is a
 * feature, not a fragment of User Management.
 *
 * Everything is filtered, sorted and paginated in SQL. Nothing here loads more
 * than one page of a table that grows with every visitor.
 *
 * Access is decided entirely by the route group: Admin and Super Admin reach
 * this prefix, and nobody else does. The archive is Super Admin only, stated
 * on the routes rather than re-derived here.
 */
class InquiryController extends Controller
{
    /** Rows per page, matching the other Configuration tables. */
    private const PER_PAGE = 10;

    public function __construct(private readonly InquiryService $inquiries) {}

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    /**
     * The working list: everything not archived, newest first by default.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(array_merge(['all', Inquiry::FILTER_PENDING], array_keys(Inquiry::STATUSES)))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $filters = $validator->validated();

        $query = Inquiry::query()
            ->active()
            ->withStatus($filters['status'] ?? null)
            ->matching($filters['search'] ?? null);

        // Date is the only sort the table offers: an enquiry is read in the
        // order it arrived, and the id breaks a same-second tie so paging
        // cannot show the same row twice.
        $direction = $filters['direction'] ?? 'desc';

        $page = $query
            ->orderBy('created_at', $direction)
            ->orderBy('inquiry_id', $direction)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'rows' => collect($page->items())
                ->map(fn (Inquiry $inquiry): array => $this->row($inquiry))
                ->all(),
            'meta' => $this->paginationMeta($page),
            // The tab's own badge: how much of the list still needs looking at.
            'unhandled' => Inquiry::query()->active()->where('status', Inquiry::STATUS_NEW)->count(),
        ]);
    }

    /**
     * The archive, on the same terms as the working list.
     *
     * Super Admin only, enforced on the route - the other end of the same
     * privilege that governs archived accounts and archived projects.
     */
    public function archivedIndex(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(array_merge(['all', Inquiry::FILTER_PENDING], array_keys(Inquiry::STATUSES)))],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $filters = $validator->validated();

        $page = Inquiry::query()
            ->archived()
            ->with('archiver')
            ->withStatus($filters['status'] ?? null)
            ->matching($filters['search'] ?? null)
            // Rows archived before the timestamp existed sort last rather than
            // first, which is what a null would otherwise do on some engines.
            ->orderByRaw('archived_at IS NULL')
            ->orderByDesc('archived_at')
            ->orderByDesc('inquiry_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return response()->json([
            'rows' => collect($page->items())
                ->map(fn (Inquiry $inquiry): array => $this->archivedRow($inquiry))
                ->all(),
            'meta' => $this->paginationMeta($page),
        ]);
    }

    /**
     * One enquiry in full, for the details dialog.
     */
    public function show(Inquiry $inquiry)
    {
        $inquiry->load(['replier', 'archiver']);

        return response()->json(['inquiry' => $this->detail($inquiry)]);
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * Move an enquiry to another status by hand.
     */
    public function updateStatus(Request $request, Inquiry $inquiry)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', Rule::in(array_keys(Inquiry::STATUSES))],
        ], [
            'status.in' => 'That is not a status an inquiry can be in.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $updated = $this->inquiries->changeStatus($inquiry, $validator->validated()['status']);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Unable to change status.');
        }

        return response()->json([
            'inquiry' => $this->detail($updated->load(['replier', 'archiver'])),
            'message' => 'Status changed to '.$updated->statusLabel().'.',
        ]);
    }

    /**
     * Answer the enquiry by email.
     *
     * The recipient is never accepted from the request: it is the address on
     * the enquiry, so nothing typed into this form can redirect a reply to
     * somebody else.
     */
    public function reply(Request $request, Inquiry $inquiry)
    {
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'min:10', 'max:'.Inquiry::MAX_REPLY],
        ], [
            'message.required' => 'Write a reply before sending.',
            'message.min' => 'Write at least 10 characters.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $replied = $this->inquiries->reply(
                $inquiry,
                $validator->validated()['message'],
                $request->user()
            );
        } catch (Throwable $exception) {
            // A refused reply leaves the enquiry exactly as it was, so the
            // interface keeps offering the form.
            return $this->failure($exception, 'Unable to send reply.');
        }

        return response()->json([
            'inquiry' => $this->detail($replied->load(['replier', 'archiver'])),
            'message' => 'Reply sent to '.$replied->email.'.',
        ]);
    }

    public function archive(Inquiry $inquiry)
    {
        try {
            $archived = $this->inquiries->archive($inquiry);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Unable to archive inquiry.');
        }

        return response()->json([
            'inquiry' => $this->detail($archived),
            'message' => 'Inquiry archived. Nothing was deleted.',
        ]);
    }

    public function restore(Inquiry $inquiry)
    {
        try {
            $restored = $this->inquiries->restore($inquiry);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'Unable to restore inquiry.');
        }

        return response()->json([
            'inquiry' => $this->detail($restored),
            'message' => 'Inquiry restored to the active list.',
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * A rejected action this application refused is the administrator's
     * problem to fix, so it comes back as a 422 carrying its own message.
     * Anything else is a fault, reported without leaking its internals.
     */
    private function failure(Throwable $exception, string $fallback)
    {
        // The exact class, not `instanceof`: several framework exceptions
        // extend RuntimeException and their messages are not written for
        // somebody to read. See Controller::safeErrorMessage().
        if ($exception::class === RuntimeException::class) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        report($exception);

        return response()->json(['error' => $fallback], 500);
    }

    /**
     * One row of the Inquiries table.
     *
     * Everything here is data, never markup: the browser escapes each value
     * as it draws it, so nothing a visitor typed can become an element.
     *
     * @return array<string, mixed>
     */
    private function row(Inquiry $inquiry): array
    {
        return [
            'id' => $inquiry->inquiry_id,
            'code' => $inquiry->code(),
            'name' => $inquiry->name,
            'email' => $inquiry->email,
            'subject' => $inquiry->subject,
            'status' => $inquiry->status,
            'status_label' => $inquiry->statusLabel(),
            'status_badge_class' => $inquiry->statusBadgeClass(),
            'submitted_at' => $inquiry->created_at?->format(BusinessTime::DATE_TIME),
            'is_new' => $inquiry->status === Inquiry::STATUS_NEW,
        ];
    }

    /**
     * One row of the archive, which additionally names who filed it away.
     *
     * @return array<string, mixed>
     */
    private function archivedRow(Inquiry $inquiry): array
    {
        return $this->row($inquiry) + [
            'archived_at' => $inquiry->archived_at?->format(BusinessTime::DATE_TIME) ?? '—',
            'archived_by' => $inquiry->archiver?->fullName() ?? '—',
        ];
    }

    /**
     * The whole record, for the details dialog: the message as it was written,
     * and the reply if one was sent.
     *
     * @return array<string, mixed>
     */
    private function detail(Inquiry $inquiry): array
    {
        return $this->row($inquiry) + [
            'message' => $inquiry->message,
            'updated_at' => $inquiry->updated_at?->format(BusinessTime::DATE_TIME),
            'is_archived' => $inquiry->is_archived,
            'has_reply' => $inquiry->hasReply(),
            'reply_message' => $inquiry->reply_message,
            'replied_at' => $inquiry->replied_at?->format(BusinessTime::DATE_TIME),
            'replied_by' => $inquiry->replier?->fullName(),
            'archived_at' => $inquiry->archived_at?->format(BusinessTime::DATE_TIME),
            'archived_by' => $inquiry->archiver?->fullName(),
        ];
    }
}
