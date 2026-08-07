<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A technician's proposed specialties, waiting on an administrator.
 *
 * A technician never changes their own specialties directly: what they can do
 * is ask. Until somebody approves, the approved set on tbl_skill_map is what
 * the scheduler, the project wizard and every suggestion list keep reading.
 */
class SpecialtyRequest extends Model
{
    protected $table = 'tbl_specialty_requests';

    protected $primaryKey = 'specialty_request_id';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** @var array<int, string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $fillable = [
        'technician_id',
        'status',
        'requested_skill_ids',
        'current_skill_ids',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'requested_skill_ids' => 'array',
        'current_skill_ids' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id', 'technician_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by', 'id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * The skills being asked for, in the order they read.
     *
     * @return Collection<int, Skill>
     */
    public function requestedSkills(): Collection
    {
        return $this->skillsFor($this->requested_skill_ids ?? []);
    }

    /**
     * What was approved at the time of asking - not what is approved now, so a
     * reviewer sees the comparison the technician saw.
     *
     * @return Collection<int, Skill>
     */
    public function currentSkills(): Collection
    {
        return $this->skillsFor($this->current_skill_ids ?? []);
    }

    /**
     * Specialties this request would add, as names.
     *
     * @return Collection<int, string>
     */
    public function additions(): Collection
    {
        return $this->requestedSkills()
            ->reject(fn (Skill $skill): bool => in_array($skill->skill_id, $this->current_skill_ids ?? [], true))
            ->pluck('skill_name')
            ->values();
    }

    /**
     * Specialties this request would drop, as names.
     *
     * @return Collection<int, string>
     */
    public function removals(): Collection
    {
        return $this->currentSkills()
            ->reject(fn (Skill $skill): bool => in_array($skill->skill_id, $this->requested_skill_ids ?? [], true))
            ->pluck('skill_name')
            ->values();
    }

    /**
     * "added HVAC; removed Plumbing", or "no change" for a request that
     * somehow carries neither - which the submission guard already rules out.
     *
     * Lives on the model rather than in one caller because the audit trail,
     * the notification and the email all have to describe the same request the
     * same way. It is also what makes each notification distinct: two requests
     * from the same technician on the same day say different things, so the
     * duplicate guard cannot mistake the second for a repeat of the first.
     */
    public function changeSummary(): string
    {
        $parts = [];

        if ($this->additions()->isNotEmpty()) {
            $parts[] = 'added '.$this->additions()->implode(', ');
        }

        if ($this->removals()->isNotEmpty()) {
            $parts[] = 'removed '.$this->removals()->implode(', ');
        }

        return $parts === [] ? 'no change' : implode('; ', $parts);
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Skill>
     */
    private function skillsFor(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Skill::query()
            ->whereIn('skill_id', $ids)
            ->orderBy('skill_name')
            ->get();
    }
}
