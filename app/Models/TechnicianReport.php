<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicianReport extends Model
{
    protected $table = 'tbl_technician_reports';

    protected $primaryKey = 'id';

    protected $fillable = [
        'project_id',
        'technician_id',
        'report_title',
        'report_description',
        'report_date',
        'report_type',
    ];

    protected $casts = [
        'report_date' => 'date',
    ];

    /**
     * Report types the table's enum accepts, with their display labels.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'progress' => 'Progress Report',
        'incident' => 'Incident Report',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(TechnicianReportImage::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id', 'technician_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->report_type] ?? ucfirst((string) $this->report_type);
    }

    public function typeBadgeClass(): string
    {
        return $this->report_type === 'incident' ? 'bg-danger' : 'bg-primary';
    }
}
