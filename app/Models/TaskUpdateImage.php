<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photograph attached to one day's progress on a task.
 */
class TaskUpdateImage extends Model
{
    protected $table = 'tbl_task_update_images';

    protected $primaryKey = 'task_update_image_id';

    protected $fillable = [
        'task_update_id',
        'image_path',
    ];

    /**
     * Where a page links to this image - a route that checks the asking
     * account against the task's project, never the file's own location.
     */
    public function url(): string
    {
        return route('media.task-update-image', ['image' => $this->getKey()]);
    }

    /**
     * Not update(): that name is Eloquent's own.
     */
    public function taskUpdate(): BelongsTo
    {
        return $this->belongsTo(TaskUpdate::class, 'task_update_id', 'task_update_id');
    }
}
