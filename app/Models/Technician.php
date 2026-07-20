<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Technician extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_technicians';

    protected $primaryKey = 'technician_id';

    protected $fillable = [
        'account_id',
        'role',
    ];

    protected $appends = [
        'name',
        'skill_names',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id', 'id');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(
            Skill::class,
            'tbl_skill_map',
            'technician_id',
            'skill_id',
            'technician_id',
            'skill_id'
        );
    }
    public function tasks()
{
    return $this->hasMany(
        Task::class,
        'technician_id',
        'technician_id'
    );
}

    public function projectTechnicians(): HasMany
    {
        return $this->hasMany(ProjectTechnician::class, 'technician_id', 'technician_id');
    }

    public function getNameAttribute(): string
    {
        return $this->account?->name ?? $this->fullName ?? '';
    }

    public function getSkillNamesAttribute(): array
    {
        return $this->skills->pluck('skill_name')->values()->all();
    }
}
