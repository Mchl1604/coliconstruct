<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $table = 'tbl_tasks';

    protected $primaryKey = 'task_id';

    protected $fillable = [
        'project_id',
        'technician_id',
        'task_title',
        'task_description',
        'start_date',
        'due_date',
        'status',
    ];
    public function images()
    {
        return $this->hasMany(TaskImage::class, 'task_id', 'task_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function technician()
    {
        return $this->belongsTo(Technician::class, 'technician_id', 'technician_id');
    }
}
