<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a project's material after setup.
 *
 * Never edited: a wrong entry is voided and stays on the list, struck
 * through, so every total can be traced. See the table's migration.
 */
class ProjectMaterialEntry extends Model
{
    /**
     * More of a material brought in than the plan said, or a material the
     * plan did not have at all.
     */
    public const TYPE_ADDED = 'added';

    public const TYPE_USED = 'used';

    /**
     * Brought to site, not needed, and taken back.
     */
    public const TYPE_RETURNED = 'returned';

    /**
     * Still on site when the project closed. Written by the system, never by
     * a person - see ProjectMaterialLedger::closeOut().
     */
    public const TYPE_UNUSED = 'unused';

    /**
     * Every type, with its label.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        self::TYPE_ADDED => 'Added',
        self::TYPE_USED => 'Used',
        self::TYPE_RETURNED => 'Returned',
        self::TYPE_UNUSED => 'Unused',
    ];

    /**
     * The two a person records against what is already on site.
     *
     * @var array<int, string>
     */
    public const DRAWING_TYPES = [
        self::TYPE_USED,
        self::TYPE_RETURNED,
    ];

    protected $table = 'tbl_project_material_entries';

    protected $primaryKey = 'material_entry_id';

    protected $fillable = [
        'project_id',
        'project_material_id',
        'entry_type',
        'quantity',
        'entry_date',
        'notes',
        'recorded_by',
        'recorded_by_name',
        'voided_at',
        'voided_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'entry_date' => 'date',
        'voided_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function projectMaterial(): BelongsTo
    {
        return $this->belongsTo(ProjectMaterial::class, 'project_material_id', 'project_material_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by', 'id');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by', 'id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->entry_type] ?? ucfirst((string) $this->entry_type);
    }

    /**
     * Who recorded it, as the history prints it.
     */
    public function recorderName(): string
    {
        return $this->recorder?->fullName() ?? $this->recorded_by_name ?? 'System';
    }
}
