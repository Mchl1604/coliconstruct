<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
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
