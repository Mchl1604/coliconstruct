<?php

namespace App\Models;

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One correction made to a schedule that had already run.
 *
 * Written whenever a save adds days in the past to a project, or takes days in
 * the past away from it - see HistoricalScheduleCorrection, which decides which
 * of those a submission is doing. A routine reschedule of work still to come
 * writes nothing here.
 *
 * The row is the audit answer to "who said this happened?": the range as it
 * stood, the range as it stands, the days that changed hands, and the
 * technicians named for the days that were added.
 */
class ScheduleCorrection extends Model
{
    /**
     * created_at is set explicitly and there is nothing to update: a
     * correction is a fact about a moment, not a record that changes.
     */
    public $timestamps = false;

    protected $table = 'tbl_schedule_corrections';

    protected $primaryKey = 'schedule_correction_id';

    protected $fillable = [
        'project_id',
        'schedule_id',
        'actor_id',
        'actor_name',
        'actor_role',
        'original_range',
        'new_range',
        'added_dates',
        'removed_dates',
        'technicians',
        'conflicts',
        'created_at',
    ];

    protected $casts = [
        'added_dates' => 'array',
        'removed_dates' => 'array',
        'technicians' => 'array',
        'conflicts' => 'array',
        'created_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The names put against the days this correction added.
     *
     * @return array<int, string>
     */
    public function technicianNames(): array
    {
        return array_values(array_filter(array_map(
            fn (array $technician): ?string => $technician['name'] ?? null,
            $this->technicians ?? []
        )));
    }

    /**
     * The correction as one readable sentence, for a screen that lists these
     * rather than for the audit trail's own description.
     */
    public function describe(): string
    {
        $parts = [];

        if ($this->original_range) {
            $parts[] = $this->new_range
                ? sprintf('changed %s to %s', $this->original_range, $this->new_range)
                : sprintf('removed %s', $this->original_range);
        } elseif ($this->new_range) {
            $parts[] = sprintf('recorded %s', $this->new_range);
        }

        if ($this->added_dates) {
            $parts[] = sprintf(
                'added %s, worked by %s',
                $this->dateList($this->added_dates),
                implode(', ', $this->technicianNames()) ?: 'nobody named'
            );
        }

        if ($this->removed_dates) {
            $parts[] = sprintf('gave up %s', $this->dateList($this->removed_dates));
        }

        return implode('; ', $parts);
    }

    /**
     * @param  array<int, string>  $dates
     */
    private function dateList(array $dates): string
    {
        return implode(', ', array_map(
            fn (string $date): string => CarbonImmutable::parse($date)->format(BusinessTime::DATE),
            $dates
        ));
    }
}
