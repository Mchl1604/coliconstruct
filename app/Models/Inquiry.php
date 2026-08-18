<?php

namespace App\Models;

use App\Support\DisplayCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message somebody wrote in through the public Contact page.
 *
 * Nobody behind it has an account: the name and address are what a stranger
 * typed, and nothing here links to a client or a project. What the record
 * carries is the handling - which state it is in, who answered it and when.
 *
 * The status is not a sequence. New to In Progress to Responded to Closed is
 * how most enquiries go, but an administrator may close one outright, and
 * replying moves it to Responded from wherever it was.
 */
class Inquiry extends Model
{
    protected $table = 'tbl_inquiries';

    protected $primaryKey = 'inquiry_id';

    // ------------------------------------------------------------------
    // Statuses
    // ------------------------------------------------------------------

    public const STATUS_NEW = 'new';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_CLOSED = 'closed';

    /**
     * Every status, in the order the workflow suggests - which is also the
     * order the filter lists them.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        self::STATUS_NEW => 'New',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_RESPONDED => 'Responded',
        self::STATUS_CLOSED => 'Closed',
    ];

    /**
     * The badge each status wears, using the same colours the rest of the
     * system gives to "just arrived", "being worked on" and "finished".
     *
     * @var array<string, string>
     */
    public const STATUS_BADGE_CLASSES = [
        self::STATUS_NEW => 'bg-primary',
        self::STATUS_IN_PROGRESS => 'bg-warning text-dark',
        self::STATUS_RESPONDED => 'bg-success',
        self::STATUS_CLOSED => 'bg-secondary',
    ];

    /**
     * The longest each field may be, stated once so the public form, the
     * validator and the column widths cannot drift apart.
     */
    public const MAX_NAME = 120;

    public const MAX_EMAIL = 255;

    public const MAX_SUBJECT = 150;

    public const MAX_MESSAGE = 2000;

    /** A reply is written by staff, so it may run longer than the message. */
    public const MAX_REPLY = 5000;

    protected $fillable = [
        'name',
        'email',
        'subject',
        'message',
        'status',
        'reply_message',
        'replied_at',
        'replied_by',
        'is_archived',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'replied_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by', 'id');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by', 'id');
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    /**
     * The code the enquiry is listed and quoted by - INQ-0007 rather than 7.
     */
    public function code(): string
    {
        return DisplayCode::format(DisplayCode::INQUIRY, $this->inquiry_id);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusBadgeClass(): string
    {
        return self::STATUS_BADGE_CLASSES[$this->status] ?? 'bg-secondary';
    }

    public function hasReply(): bool
    {
        return filled($this->reply_message);
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * The working list: everything not archived.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    /**
     * Narrow to one status. Anything other than a known one means "all", which
     * is what the filter's own default sends.
     */
    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        if (! $status || ! array_key_exists($status, self::STATUSES)) {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * Free-text search over the parts a reader actually sees, plus the code -
     * so "INQ-0007", "inq 7" and a plain "7" all find the same row.
     */
    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';
        $id = DisplayCode::toId(DisplayCode::INQUIRY, $term);

        return $query->where(function (Builder $inner) use ($like, $id): void {
            $inner->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('subject', 'like', $like)
                ->orWhere('message', 'like', $like);

            if ($id !== null) {
                $inner->orWhere('inquiry_id', $id);
            }
        });
    }
}
