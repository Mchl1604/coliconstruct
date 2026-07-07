<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProjectType extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_project_types';

    protected $primaryKey = 'type_id';

    protected $fillable = [
        'type_name',
    ];

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(
            Project::class,
            'tbl_project_type_map',
            'type_id',
            'project_id',
            'type_id',
            'project_id'
        );
    }
}
