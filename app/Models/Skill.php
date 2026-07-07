<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Skill extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_skills';

    protected $primaryKey = 'skill_id';

    protected $fillable = [
        'skill_name',
    ];

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(
            Technician::class,
            'tbl_skill_map',
            'skill_id',
            'technician_id',
            'skill_id',
            'technician_id'
        );
    }
}
