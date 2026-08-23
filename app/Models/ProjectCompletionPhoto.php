<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectCompletionPhoto extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_project_completion_photos';

    protected $primaryKey = 'completion_photo_id';

    protected $fillable = [
        'project_id',
        'photo_path',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    /**
     * Where a page links to this photograph - a route that checks the asking
     * account against the project, never the file's own location.
     */
    public function url(): string
    {
        return route('media.completion-photo', ['photo' => $this->completion_photo_id]);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }
}
