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
 * Either end may lie in the future. A removal can be scheduled - "John comes off
 * this project from Aug 21" - and so can a start, which is how a replacement
 * lead takes over on the same day. So whether a span is on the team is always
 * a question about a DATE, never about whether removed_at is filled in:
 *
 *     current   covers today                    (a leaving member is current)
 *     upcoming  starts after today
 *     ended     its removal date has arrived
 *
 * The distinction runs through the relations that read this table:
 * Project::projectTechnicians() is the team today, Project::rosterTechnicians()
 * is everybody current or still to come, and Project::teamHistory() is every
 * span there has ever been.
 *
 * One row is one continuous span, not one person. Somebody taken off a project
 * and later put back holds two rows - the closed one keeps the days they
 * worked the first time, and the new one opens at the day they returned. The
 * spans of one technician on one project never overlap; ProjectTeam keeps it
 * that way, and every reader relies on it.
 *
 * team_role is the role the span was opened with - see heldLeadRole().
 */
class ProjectTechnician extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_project_technicians';

    protected $primaryKey = 'project_technician_id';

    protected $fillable = [
        'project_id',
        'technician_id',
        'team_role',
        'joined_at',
        'joined_by',
        'removed_at',
        'removed_by',
        'removal_recorded_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'removed_at' => 'datetime',
        'removal_recorded_at' => 'datetime',
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
     * The team on a date - today's, by default. A member whose removal is
     * scheduled for later is still on it; one who starts later is not yet.
     */
    public function scopeCurrent(Builder $query, ?string $date = null): Builder
    {
        return $query->coveringDate($date ?? self::today());
    }

    /**
     * Everybody on the team on a date or due to join it after - every span
     * whose removal has not arrived by then.
     *
     * This is the set a change to the team is checked against: somebody
     * starting next week is not on the team today, but they are not free to be
     * added a second time either, and the lead rule has to see them coming.
     */
    public function scopeNotEnded(Builder $query, ?string $date = null): Builder
    {
        $date ??= self::today();

        return $query->where(fn (Builder $removed): Builder => $removed
            ->whereNull('removed_at')
            ->orWhereDate('removed_at', '>', $date));
    }

    /**
     * Spans that have not started yet, and still will.
     */
    public function scopeUpcoming(Builder $query, ?string $date = null): Builder
    {
        $date ??= self::today();

        return $query->whereDate('joined_at', '>', $date)
            ->where(fn (Builder $removed): Builder => $removed
                ->whereNull('removed_at')
                ->orWhereColumn('removed_at', '>', 'joined_at'));
    }

    /**
     * Spans that hold the whole of a task's dates - the strict rule a task's
     * technician is held to. See coversPeriod().
     *
     * Either end may be a column expression, so a task query can ask this of
     * its own rows.
     */
    public function scopeCoveringPeriod(Builder $query, mixed $start, mixed $due): Builder
    {
        return $query
            ->where(fn (Builder $joined): Builder => $joined
                ->whereNull('joined_at')
                ->orWhereDate('joined_at', '<=', $start))
            ->where(fn (Builder $removed): Builder => $removed
                ->whereNull('removed_at')
                ->orWhereDate('removed_at', '>', $due));
    }

    /**
     * Memberships whose span covers the given date.
     *
     * The boundaries are deliberately lopsided. joined_at is inclusive: the
     * day you arrive is a day you were on the team. removed_at is exclusive:
     * it is the first day you are NOT on the team, so a removal recorded on
     * Aug 21 leaves Aug 20 as the last day of the span. Days are compared, not
     * moments - a removal recorded at noon takes the whole of that day.
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

    /**
     * Whether a removal has been recorded against this span at all - taken
     * effect or still to come. Most readers want hasEnded() or isLeaving().
     */
    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /**
     * The first day of the span as 'Y-m-d', or null for one that has always
     * been there.
     */
    public function startDate(): ?string
    {
        return $this->joined_at === null ? null : CarbonImmutable::parse($this->joined_at)->toDateString();
    }

    /**
     * The first day the span NO LONGER covers, as 'Y-m-d' - the effective
     * removal date - or null while nothing ends it.
     */
    public function endDate(): ?string
    {
        return $this->removed_at === null ? null : CarbonImmutable::parse($this->removed_at)->toDateString();
    }

    /**
     * The last day the span covers, inclusive - the day before the removal.
     */
    public function lastDay(): ?CarbonImmutable
    {
        return $this->removed_at === null ? null : CarbonImmutable::parse($this->endDate())->subDay();
    }

    /**
     * A span that closes on the day it opened covers no day at all: the
     * technician added by mistake and taken straight back off.
     */
    public function isEmptySpan(): bool
    {
        return $this->startDate() !== null && $this->endDate() !== null && $this->startDate() >= $this->endDate();
    }

    /**
     * On the team on the given date - today, by default.
     */
    public function isCurrent(?string $date = null): bool
    {
        return ! $this->isEmptySpan() && $this->coveredOn($date ?? self::today());
    }

    /**
     * Not on the team yet, and due to be.
     */
    public function isUpcoming(?string $date = null): bool
    {
        return ! $this->isEmptySpan() && $this->startDate() !== null && $this->startDate() > ($date ?? self::today());
    }

    /**
     * Its removal has taken effect: a record of a membership, not a
     * membership.
     */
    public function hasEnded(?string $date = null): bool
    {
        return $this->isEmptySpan() || ($this->endDate() !== null && $this->endDate() <= ($date ?? self::today()));
    }

    /**
     * On the team today with a removal already scheduled.
     */
    public function isLeaving(?string $date = null): bool
    {
        return $this->isCurrent($date) && $this->endDate() !== null;
    }

    /**
     * Whether this span holds every day from a task's start to its due date.
     *
     * One span, deliberately. A technician taken off a project on Aug 21 and
     * put back on Sep 5 covers both Aug 18 and Sep 8 - but not the fortnight
     * between, when the work was somebody else's to do. A task running across
     * that gap is not theirs, even though both of its ends are. (A gap in the
     * PROJECT's schedule is different: nobody is on site then at all, which is
     * why TaskScheduleRules lets a task span one.)
     */
    public function coversPeriod(string $start, string $due): bool
    {
        if ($this->isEmptySpan()) {
            return false;
        }

        return ($this->startDate() === null || $this->startDate() <= $start)
            && ($this->endDate() === null || $this->endDate() > $due);
    }

    /**
     * Whether this span shares at least one day with [$from, $until), where a
     * null $until runs on forever.
     */
    public function overlaps(string $from, ?string $until): bool
    {
        if ($this->isEmptySpan() || ($until !== null && $from >= $until)) {
            return false;
        }

        return ($this->endDate() === null || $this->endDate() > $from)
            && ($until === null || $this->startDate() === null || $this->startDate() < $until);
    }

    /**
     * The office's today, which every "is this span on the team?" question is
     * measured against - never the server's midnight.
     */
    public static function today(): string
    {
        return Schedule::businessToday()->toDateString();
    }

    /**
     * Whether this span was held as the project's Lead Technician.
     *
     * Read from team_role, which is written when the span opens, so the answer
     * is the role the person had on this project rather than the one their
     * account has now. A lead demoted after the job was finished still led it.
     *
     * A row with nothing recorded falls back to the account - the same answer
     * every membership gave before the column existed, and still the right one
     * for a row somebody wrote without it.
     *
     * This is the question a RECORD asks. The live team still asks the account
     * - see Project::isLeadMember(), which decides which of the two applies.
     */
    public function heldLeadRole(): bool
    {
        if ($this->team_role !== null) {
            return $this->team_role === User::ROLE_LEAD_TECHNICIAN;
        }

        return (bool) $this->technician?->isLead();
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
