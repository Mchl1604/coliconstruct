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
        'submitted_by',
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

    /**
     * The account that filed the report, whatever their role.
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by', 'id');
    }

    /**
     * Who to credit the report to.
     *
     * The submitting account when there is one; otherwise the technician the
     * report is about, which is who filed every report written before that
     * column existed.
     */
    public function submitterName(): string
    {
        return $this->submitter?->fullName()
            ?? $this->technician?->name
            ?? 'Unknown';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->report_type] ?? ucfirst((string) $this->report_type);
    }

    public function typeBadgeClass(): string
    {
        return $this->report_type === 'incident' ? 'bg-danger' : 'bg-primary';
    }

    /**
     * The colour the report viewer is tinted with, so the type is readable at
     * a glance before a word is read.
     */
    public function typeAccentClass(): string
    {
        return $this->report_type === 'incident'
            ? 'report-accent-incident'
            : 'report-accent-progress';
    }
}
