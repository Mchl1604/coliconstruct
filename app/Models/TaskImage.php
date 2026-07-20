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

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id', 'task_id');
    }
}
