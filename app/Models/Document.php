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

    /**
     * What a project document may be: a PDF, or a picture of one.
     *
     * Stated once for the same reason the size limit is - the wizard, the edit
     * dialog and the file pickers all read it from here, so they cannot offer
     * something the server would then refuse.
     */
    public const ALLOWED_MIMES = 'jpg,jpeg,png,pdf';

    /** The same list written the way a person reads it. */
    public const ALLOWED_LABEL = 'PDF, JPG, JPEG or PNG';

    /** What the file picker's accept attribute offers. */
    public const ACCEPT_ATTRIBUTE = '.pdf,.jpg,.jpeg,.png';

    /**
     * How many files one document type may take in a single upload. A guard
     * against a stray select-all rather than a rule anybody should meet: PHP's
     * own max_file_uploads is the harder ceiling, at 20 by default.
     */
    public const MAX_FILES = 10;

    /**
     * The document types a project carries, in the order they are shown.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'assessment' => 'Assessment',
        'quotation' => 'Quotation',
        'contract' => 'Contract',
    ];

    /**
     * The rules one uploaded file is held to, shared by every form that takes
     * one so none of them can drift.
     *
     * @return array<int, string>
     */
    public static function fileRules(): array
    {
        return ['file', 'mimes:'.self::ALLOWED_MIMES, 'max:'.self::MAX_KILOBYTES];
    }

    /**
     * The message a rejected file gets, named for the field it came from.
     */
    public static function mimesMessage(string $label): string
    {
        return sprintf('Each %s file must be a %s.', $label, self::ALLOWED_LABEL);
    }

    public static function maxMessage(string $label): string
    {
        return sprintf('Each %s file must be %s or smaller.', $label, self::MAX_LABEL);
    }

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

    /**
     * Where a page links to this document.
     *
     * Never the file's own location. The bytes are on a private disk and the
     * route is what decides whether the person asking may have them - see
     * UploadedFileController::document(). document_path is an internal detail
     * of the disk and nothing outside the store should build a URL from it.
     */
    public function url(): string
    {
        return route('media.document', ['document' => $this->document_id]);
    }
}
