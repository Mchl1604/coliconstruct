<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One alert addressed to one person.
 *
 * Unlike an ActivityLog entry, which records what happened, a notification
 * records what somebody still needs to look at - so it is per-recipient, it
 * carries the page it points to, and it is dismissible.
 *
 * Modules mirror ActivityLog::MODULES so the two features filter the same way
 * and a reader does not have to learn two vocabularies.
 */
class Notification extends Model
{
    use SoftDeletes;

    protected $table = 'tbl_notifications';

    protected $primaryKey = 'notification_id';

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'module',
        'reference_type',
        'reference_id',
        'url',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    // ------------------------------------------------------------------
    // Modules
    // ------------------------------------------------------------------

    public const MODULE_PROJECTS = 'Projects';

    public const MODULE_TASKS = 'Tasks';

    public const MODULE_SCHEDULE = 'Schedule';

    public const MODULE_USER_MANAGEMENT = 'User Management';

    public const MODULE_REPORTS = 'Reports';

    public const MODULE_SECURITY = 'Security';

    /**
     * Every module the filter offers, in the order it lists them.
     *
     * @var array<int, string>
     */
    public const MODULES = [
        self::MODULE_PROJECTS,
        self::MODULE_TASKS,
        self::MODULE_SCHEDULE,
        self::MODULE_USER_MANAGEMENT,
        self::MODULE_REPORTS,
        self::MODULE_SECURITY,
    ];

    /**
     * The icon each module wears in the dropdown and the centre.
     *
     * @var array<string, string>
     */
    public const MODULE_ICONS = [
        self::MODULE_PROJECTS => 'bi-folder',
        self::MODULE_TASKS => 'bi-check2-square',
        self::MODULE_SCHEDULE => 'bi-calendar-event',
        self::MODULE_USER_MANAGEMENT => 'bi-person-badge',
        self::MODULE_REPORTS => 'bi-file-earmark-text',
        self::MODULE_SECURITY => 'bi-shield-exclamation',
    ];

    /**
     * How many the bell shows before "View All Notifications" takes over.
     */
    public const DROPDOWN_LIMIT = 10;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function icon(): string
    {
        return self::MODULE_ICONS[$this->module] ?? 'bi-bell';
    }

    /**
     * "2 minutes ago". Carbon's own wording, so it matches the rest of the app.
     */
    public function relativeTime(): string
    {
        return $this->created_at?->diffForHumans() ?? '';
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('notification_id');
    }

    /**
     * Narrow by read state. Anything other than 'read'/'unread' means "all".
     */
    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'read' => $query->where('is_read', true),
            'unread' => $query->where('is_read', false),
            default => $query,
        };
    }

    /**
     * Free-text search over the parts a reader actually sees.
     */
    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term): void {
            $like = '%'.$term.'%';

            $inner->where('title', 'like', $like)
                ->orWhere('message', 'like', $like)
                ->orWhere('module', 'like', $like);
        });
    }

    /**
     * Mark as read, and say whether that changed anything - so a caller can
     * avoid a pointless write when the entry was already read.
     */
    public function markAsRead(): bool
    {
        if ($this->is_read) {
            return false;
        }

        $this->forceFill([
            'is_read' => true,
            'read_at' => now(),
        ])->save();

        return true;
    }
}
