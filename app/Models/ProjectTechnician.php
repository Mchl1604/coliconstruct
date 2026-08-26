<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One technician's membership of one project, as a span rather than a fact.
 *
 * A row used to exist while somebody was on a team and be deleted when they
 * left, which meant the table could only ever answer "who is on this project
 * now?". Every other question - who was on it in July, who is on this date,
 * who was here when that report was filed - had no answer at all, and worse,
 * deleting the row took the schedule links hanging off it down by cascade.
 *
 * A row is now permanent. joined_at opens the span and removed_at closes it,
 * so the table answers "who was on this project on that day?" as easily as it
 * answers "who is on it now".
 *
 * The distinction runs through the relations that read this table:
 * Project::projectTechnicians() is the current team and is scoped to open
 * spans, which is what every screen in the application means by "the team".
 * Project::teamHistory() is every span there has ever been, and only the
 * handful of places that deliberately look backwards use it.
 */
class ProjectTechnician extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_project_technicians';

    protected $primaryKey = 'project_technician_id';

    protected $fillable = [
        'project_id',
        'technician_id',
        'joined_at',
        'joined_by',
        'removed_at',
        'removed_by',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id', 'technician_id');
    }

    public function joinedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'joined_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function scheduleTechnicians(): HasMany
    {
        return $this->hasMany(ScheduleTechnician::class, 'project_technician_id', 'project_technician_id');
    }

    /**
     * Memberships that have not been closed - the team as it stands.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /**
     * Memberships whose span covers the given date.
     *
     * The boundaries are deliberately lopsided. joined_at is inclusive: the
     * day you arrive is a day you were on the team. removed_at is exclusive:
     * the day you are taken off is a day you were still there for, so the span
     * has to reach past it - a removal recorded at noon does not unmake the
     * morning.
     *
     * A row with no joined_at - which the backfill should have left none of -
     * counts as having always been there, because the alternative is dropping
     * somebody out of a history they are in.
     */
    public function scopeCoveringDate(Builder $query, string $date): Builder
    {
        return $query
            ->where(function (Builder $joined) use ($date): void {
                $joined->whereNull('joined_at')
                    ->orWhereDate('joined_at', '<=', $date);
            })
            ->where(function (Builder $removed) use ($date): void {
                $removed->whereNull('removed_at')
                    ->orWhereDate('removed_at', '>', $date);
            });
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /**
     * Whether this membership covers the given date - the same rule
     * scopeCoveringDate() applies, for a row already in hand.
     *
     * Both live here so a collection filtered in PHP and a query filtered in
     * SQL can never disagree about who was on a team.
     */
    public function coveredOn(string $date): bool
    {
        if ($this->joined_at !== null && CarbonImmutable::parse($this->joined_at)->toDateString() > $date) {
            return false;
        }

        return $this->removed_at === null
            || CarbonImmutable::parse($this->removed_at)->toDateString() > $date;
    }
}
