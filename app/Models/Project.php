<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    protected $table = 'tbl_projects';

    protected $primaryKey = 'project_id';

    /**
     * Statuses that count as "locked" / view-only records.
     *
     * @var array<int, string>
     */
    public const READ_ONLY_STATUSES = ['completed', 'cancelled', 'archived'];

    /**
     * Statuses that DO count as a scheduling conflict for a technician.
     * Everything else (not_yet_scheduled, on_hold, completed, cancelled,
     * archived) must be ignored by the technician availability checker.
     *
     * @var array<int, string>
     */
    public const ACTIVE_PROJECT_STATUSES = ['pending', 'ongoing'];

    protected $fillable = [
        'reference_no',
        'name',
        'status',
        'quotation',
        'address',
        'description',
        'on_hold',
        'is_archived',
        'completed_at',
        'completion_summary',
        'completion_remarks',
        'cancelled_at',
        'cancellation_reason',
        'cancellation_remarks',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'quotation' => 'decimal:2',
        'on_hold' => 'boolean',
        'is_archived' => 'boolean',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'archived_at' => 'datetime',
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
        return $this->hasOne(Schedule::class, 'project_id', 'project_id')
            ->orderBy('start_datetime');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'project_id', 'project_id')
            ->orderBy('start_datetime');
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

    public function completionPhotos(): HasMany
    {
        return $this->hasMany(ProjectCompletionPhoto::class, 'project_id', 'project_id');
    }

    public function archivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by', 'id');
    }

    /**
     * Whether this project is locked for editing (historical record).
     */
    public function isReadOnly(): bool
    {
        return in_array($this->status, self::READ_ONLY_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived' || (bool) $this->is_archived;
    }
}
