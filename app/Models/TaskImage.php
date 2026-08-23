<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskImage extends Model
{
    protected $table = 'tbl_task_images';

    protected $fillable = [
        'task_id',
        'image_path',
    ];

    /**
     * Where a page links to this image - a route that checks the asking
     * account against the task's project, never the file's own location.
     */
    public function url(): string
    {
        return route('media.task-image', ['image' => $this->getKey()]);
    }

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id', 'task_id');
    }
}
