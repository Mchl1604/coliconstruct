<?php

namespace App\Models;

use App\Support\DisplayCode;
use Illuminate\Database\Eloquent\Builder;
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
        'is_archived',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'report_date' => 'date',
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
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

    /**
     * How the report's key is printed, e.g. RPT-0007.
     */
    public function displayCode(): string
    {
        return DisplayCode::format(DisplayCode::REPORT, $this->id);
    }

    /**
     * Reports that belong on an active list: never the archived ones.
     *
     * Every query that answers "what has been reported" reads through this, so
     * an archived report cannot leak back into a count, a table or a project's
     * own report list by somebody forgetting the condition.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * The other half: what the Archived Reports view lists.
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    public function isArchived(): bool
    {
        return (bool) $this->is_archived;
    }

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

    /**
     * The picture to show beside submitterName(), from the same account.
     *
     * Null only when nobody can be identified; a report is always filed by
     * somebody internal, so in practice this is their picture or the default
     * avatar.
     */
    public function submitterAvatarUrl(): ?string
    {
        return ($this->submitter ?? $this->technician?->account)?->avatarUrl();
    }

    /**
     * The account that filed this report away. Null while it is active, and
     * for anything archived before the column existed.
     */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by', 'id');
    }

    /**
     * Whether this account is the one that filed the report.
     *
     * `submitted_by` is the answer wherever there is one. Reports written
     * before that column existed carry no account at all, and for those the
     * technician the report is filed under is who wrote it - the same fallback
     * submitterName() prints and the same one the technician portal's own
     * report log narrows by. It is never "the technician assigned to the
     * project now": a report whose submitter is recorded is judged on that
     * alone.
     */
    public function wasSubmittedBy(User $user): bool
    {
        if ($this->submitted_by !== null) {
            return (int) $this->submitted_by === (int) $user->id;
        }

        $technicianId = $user->technicianId();

        return $technicianId !== null && (int) $this->technician_id === $technicianId;
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
