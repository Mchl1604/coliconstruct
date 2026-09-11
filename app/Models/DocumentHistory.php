<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to one of a project's files.
 *
 * Written by DocumentHistoryLog inside the same transaction as the change, so
 * a save that rolls back leaves no entry claiming it happened.
 */
class DocumentHistory extends Model
{
    /** A file was added to the project. */
    public const EVENT_UPLOADED = 'uploaded';

    /** A quotation file was replaced by a newer upload, and kept. */
    public const EVENT_REPLACED = 'replaced';

    /** A file was taken off the project and deleted. */
    public const EVENT_REMOVED = 'removed';

    /** @var array<string, string> */
    public const EVENT_LABELS = [
        self::EVENT_UPLOADED => 'Uploaded',
        self::EVENT_REPLACED => 'Replaced',
        self::EVENT_REMOVED => 'Removed',
    ];

    /**
     * created_at is set explicitly and there is nothing to update: an entry
     * is a fact about a moment, not a record that changes.
     */
    public $timestamps = false;

    protected $table = 'tbl_document_history';

    protected $primaryKey = 'document_history_id';

    protected $fillable = [
        'project_id',
        'document_id',
        'document_type',
        'document_name',
        'event',
        'actor_id',
        'actor_name',
        'actor_role',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    /**
     * The file this entry is about, while it still exists. A replaced
     * quotation still does; a removed file does not, and this is null.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id', 'document_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function eventLabel(): string
    {
        return self::EVENT_LABELS[$this->event] ?? ucfirst((string) $this->event);
    }
}
