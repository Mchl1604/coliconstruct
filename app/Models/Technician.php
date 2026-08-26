<?php

namespace App\Models;

use App\Support\DisplayCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Technician extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_technicians';

    protected $primaryKey = 'technician_id';

    protected $fillable = [
        'account_id',
        'role',
    ];

    protected $appends = [
        'name',
        'skill_names',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id', 'id');
    }

    /**
     * The technicians who may be given work.
     *
     * A technician record outlives the account's job title on purpose - see
     * UserAccountService::syncTechnicianRecord() - so "is this a technician?"
     * and "may this person be sent to a site?" are two different questions.
     * This one is the second: the account still holds a technician role, and
     * it can still sign in. An account that has been switched off or archived
     * cannot open the project, cannot close a task, and cannot be told it has
     * been assigned anything, so it is not work anybody can hand out.
     *
     * Somebody already on a team is a separate matter and is not filtered by
     * this: taking work away is a decision for a person to make, not a side
     * effect of an account being disabled. See ProjectTeamRules.
     *
     * @param  Builder<Technician>  $query
     * @return Builder<Technician>
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->whereHas('account', fn (Builder $account): Builder => $account
            ->whereIn('role', User::TECHNICIAN_ROLES)
            ->loginable());
    }

    /**
     * The same question asked of one record that is already in hand.
     */
    public function isAssignable(): bool
    {
        return $this->account !== null
            && in_array($this->account->role, User::TECHNICIAN_ROLES, true)
            && $this->account->canLogin();
    }

    /**
     * Whether this technician is a lead. There is no per-project lead column:
     * a project's lead is the member whose account role says so, which is why
     * a project may only ever carry one of them.
     */
    public function isLead(): bool
    {
        return $this->account?->role === User::ROLE_LEAD_TECHNICIAN;
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(
            Skill::class,
            'tbl_skill_map',
            'technician_id',
            'skill_id',
            'technician_id',
            'skill_id'
        );
    }

    public function tasks()
    {
        return $this->hasMany(
            Task::class,
            'technician_id',
            'technician_id'
        );
    }

    /**
     * The projects this technician is on now.
     *
     * Scoped to open memberships for the same reason
     * Project::projectTechnicians() is: every workload count, dashboard tile
     * and "what is this technician on?" read means the current team, and a
     * membership row now outlives the membership. See ProjectTechnician.
     */
    public function projectTechnicians(): HasMany
    {
        return $this->hasMany(ProjectTechnician::class, 'technician_id', 'technician_id')
            ->whereNull('removed_at');
    }

    /**
     * Every project this technician has ever been on, the ones they have been
     * taken off included.
     *
     * What their own calendar reads, so the days they were booked for stay on
     * it after they leave the team.
     */
    public function projectHistory(): HasMany
    {
        return $this->hasMany(ProjectTechnician::class, 'technician_id', 'technician_id');
    }

    /**
     * How the technician's key is printed, e.g. TECH-0007. Their staff
     * account code (EMP-0001) is a separate thing and stays as it is.
     */
    public function displayCode(): string
    {
        return DisplayCode::format(DisplayCode::TECHNICIAN, $this->technician_id);
    }

    public function getNameAttribute(): string
    {
        return $this->account?->name ?? $this->fullName ?? '';
    }

    public function getSkillNamesAttribute(): array
    {
        return $this->skills->pluck('skill_name')->values()->all();
    }
}
