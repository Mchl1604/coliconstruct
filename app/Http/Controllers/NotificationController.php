<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The notification bell and the Notification Center.
 *
 * Every route here is scoped to the signed-in reader: notifications are
 * addressed to a person, so "mine" is the only visibility rule there is, and
 * it is applied in SQL rather than checked afterwards.
 */
class NotificationController extends Controller
{
    /**
     * How many rows the Notification Center shows per page.
     */
    private const PER_PAGE = 15;

    /**
     * What the bell polls for: the unread count and the newest few.
     */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = Notification::query()
            ->forUser($user)
            ->latestFirst()
            ->limit(Notification::DROPDOWN_LIMIT)
            ->get();

        return response()->json([
            'unread_count' => Notification::query()->forUser($user)->unread()->count(),
            'notifications' => $notifications
                ->map(fn (Notification $notification): array => $this->row($notification))
                ->all(),
            'view_all_url' => route('notifications.index'),
        ]);
    }

    /**
     * The Notification Center page.
     *
     * One page for every role, wrapped in whichever shell the reader already
     * lives in. The layout is picked here rather than in the view: @extends
     * takes a single expression, and a conditional one belongs in PHP.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Each role reads this inside the shell it already lives in. A client
        // has no portal of its own, so theirs is the public website.
        $layout = match (true) {
            in_array($user->role, User::ADMINISTRATOR_ROLES, true) => 'layouts.superadminNav',
            $user->isClient() => 'layouts.publicSite',
            default => 'layouts.portalNav',
        };

        return view('notifications.index', [
            'modules' => Notification::MODULES,
            'layout' => $layout,
            // Built here rather than inline in the view: Blade's @json
            // directive cannot parse an argument containing nested arrays.
            // __id__ is substituted by the browser per row.
            'centerRoutes' => [
                'list' => route('notifications.list'),
                'readAll' => route('notifications.read-all'),
                'read' => route('notifications.read', ['notification' => '__id__']),
                'destroy' => route('notifications.destroy', ['notification' => '__id__']),
            ],
        ]);
    }

    /**
     * The Notification Center table: searched, filtered, paged - all in SQL,
     * because this list only grows.
     */
    public function list(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:all,read,unread'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $module = $validated['module'] ?? 'all';

        $notifications = Notification::query()
            ->forUser($user)
            ->matching($validated['search'] ?? null)
            ->withStatus($validated['status'] ?? 'all')
            ->when(
                $module !== 'all' && $module !== '',
                fn ($query) => $query->where('module', $module)
            )
            ->latestFirst()
            ->paginate(self::PER_PAGE);

        return response()->json([
            'rows' => collect($notifications->items())
                ->map(fn (Notification $notification): array => $this->row($notification))
                ->all(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
                'from' => $notifications->firstItem(),
                'to' => $notifications->lastItem(),
            ],
            'unread_count' => Notification::query()->forUser($user)->unread()->count(),
        ]);
    }

    /**
     * Open a notification: mark it read, then send the reader where it points.
     *
     * A GET because it is what a link click is, and the read flag is a
     * side effect of reading rather than a separate action to confirm.
     */
    public function open(Request $request, Notification $notification)
    {
        $this->authorizeOwnership($request, $notification);

        $notification->markAsRead();

        return redirect()->to($notification->url ?: url()->previous());
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        $this->authorizeOwnership($request, $notification);

        $notification->markAsRead();

        return response()->json([
            'unread_count' => Notification::query()->forUser($request->user())->unread()->count(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        // One statement rather than a model per row: this can be thousands.
        Notification::query()
            ->forUser($request->user())
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['unread_count' => 0]);
    }

    /**
     * Soft delete: the row stays for the record, but stops being the reader's
     * business.
     */
    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        $this->authorizeOwnership($request, $notification);

        DB::transaction(fn () => $notification->delete());

        return response()->json([
            'unread_count' => Notification::query()->forUser($request->user())->unread()->count(),
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * A notification is addressed to exactly one person; anyone else asking
     * for it gets the same answer as if it did not exist.
     */
    private function authorizeOwnership(Request $request, Notification $notification): void
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Notification $notification): array
    {
        return [
            'id' => $notification->notification_id,
            'title' => $notification->title,
            'message' => $notification->message,
            'module' => $notification->module,
            'icon' => $notification->icon(),
            'is_read' => (bool) $notification->is_read,
            'relative_time' => $notification->relativeTime(),
            'created_at' => $notification->created_at?->format('M j, Y g:i A'),
            'open_url' => route('notifications.open', $notification->notification_id),
        ];
    }
}
