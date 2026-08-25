<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A completion report the project has moved on from.
 *
 * The current report is not here. It lives on the project row - completed_at,
 * completion_summary, completion_remarks and the photographs whose
 * completion_report_id is still null - which is where it has always lived and
 * where every page still reads it from. A row exists in this table only
 * because a reopen replaced the report it holds, which is why `status` is
 * always Superseded.
 *
 * Reading it is therefore always the same question: "what was this project
 * closed out as, the time before?" See ProjectCompletionHistory, which is what
 * writes these rows.
 */
class ProjectCompletionReport extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_project_completion_reports';

    protected $primaryKey = 'completion_report_id';

    /**
     * Replaced by a later completion cycle, kept for the history.
     */
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'project_id',
        'cycle',
        'status',
        'completed_at',
        'completion_summary',
        'completion_remarks',
        'completion_method',
        'completion_requested_at',
        'completion_requested_by',
        'client_confirmed_at',
        'client_confirmed_by',
        'completion_override_reason',
        'completion_override_blockers',
        'completion_overridden_by',
        'project_status',
        'superseded_at',
        'superseded_by',
        'supersede_reason',
    ];

    protected $casts = [
        'cycle' => 'integer',
        'completed_at' => 'datetime',
        'completion_requested_at' => 'datetime',
        'client_confirmed_at' => 'datetime',
        'completion_override_blockers' => 'array',
        'superseded_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    /**
     * The photographs filed during this cycle. They were never copied or
     * moved - the rows are the same ones the project showed at the time, now
     * pointing here instead of at the project's current report.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(
            ProjectCompletionPhoto::class,
            'completion_report_id',
            'completion_report_id'
        );
    }

    public function completionRequestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completion_requested_by', 'id');
    }

    public function clientConfirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_confirmed_by', 'id');
    }

    public function completionOverriddenByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completion_overridden_by', 'id');
    }

    /**
     * The account that reopened the project and so ended this cycle.
     */
    public function supersededByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by', 'id');
    }

    /**
     * Newest cycle first, which is the order a history is read in.
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('cycle')->orderByDesc('completion_report_id');
    }

    /**
     * Never true today - a report is written here only once it has been
     * replaced - but asked rather than assumed, so a page cannot start
     * presenting a historical report as the current one if the lifecycle ever
     * grows a second value.
     */
    public function isSuperseded(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }

    public function statusLabel(): string
    {
        return $this->isSuperseded() ? 'Superseded' : ucfirst((string) $this->status);
    }

    /**
     * "Completion Report #2", how a cycle is named wherever one is listed.
     */
    public function cycleLabel(): string
    {
        return 'Completion Report #'.$this->cycle;
    }

    /**
     * Who filed it, falling back to the client who signed it off when the
     * report predates the column - the same shape of fallback
     * TechnicianReport::submitterName() uses.
     */
    public function submitterName(): string
    {
        return $this->completionRequestedByUser?->fullName()
            ?? $this->clientConfirmedByUser?->fullName()
            ?? 'Unknown';
    }

    /**
     * The same wording Project::completionMethodLabel() gives the current
     * report, so a report does not change how it reads by becoming history.
     */
    public function completionMethodLabel(): ?string
    {
        return match ($this->completion_method) {
            Project::METHOD_CLIENT_CONFIRMED => 'Confirmed by the client',
            Project::METHOD_AUTO_COMPLETED => sprintf(
                'Completed automatically after %d days without a reply',
                Project::completionConfirmationDays()
            ),
            default => null,
        };
    }

    /**
     * Whether this cycle was closed out over its own completion rules.
     */
    public function completionWasOverridden(): bool
    {
        return filled($this->completion_override_reason);
    }
}
