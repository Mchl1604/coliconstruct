<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    /**
     * The largest project document that may be uploaded, in kilobytes.
     *
     * Stated once and used by every form that accepts one, so the create
     * wizard and the edit dialog cannot disagree. It must stay comfortably
     * below PHP's own `upload_max_filesize`: a file over that limit is
     * discarded by PHP before Laravel can validate it, and the person gets a
     * blunt "content too large" instead of a message naming the field.
     */
    public const MAX_KILOBYTES = 20480;

    /** The same limit written the way a person reads it. */
    public const MAX_LABEL = '20 MB';

    public $timestamps = false;

    protected $table = 'tbl_documents';

    protected $primaryKey = 'document_id';

    protected $fillable = [
        'project_id',
        'document_type',
        'document_name',
        'document_path',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }
}
