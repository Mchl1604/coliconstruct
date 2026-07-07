<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    protected $table = 'tbl_projects';

    protected $primaryKey = 'project_id';

    protected $fillable = [
        'reference_no',
        'name',
        'status',
        'quotation',
        'address',
        'description',
    ];

    protected $casts = [
        'quotation' => 'decimal:2',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'project_id', 'project_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'project_id', 'project_id');
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(Schedule::class, 'project_id', 'project_id');
    }

    public function projectTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            ProjectType::class,
            'tbl_project_type_map',
            'project_id',
            'type_id',
            'project_id',
            'type_id'
        );
    }

    public function projectTechnicians(): HasMany
    {
        return $this->hasMany(ProjectTechnician::class, 'project_id', 'project_id');
    }
}
