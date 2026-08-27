<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_clients';

    protected $primaryKey = 'client_id';

    protected $casts = [
        'user_unlinked_at' => 'datetime',
    ];

    protected $fillable = [
        'project_id',
        'user_id',
        'user_unlinked_at',
        'client_type',
        'company_name',
        'surname',
        'firstname',
        'middlename',
        'fullname',
        'email_address',
        'contact_number',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    /**
     * The Registered User account this contact belongs to, once there is one.
     *
     * Null while a project has been booked for somebody who has not registered
     * yet, which is the ordinary case at the start: the address is what
     * connects them until they do.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Whether this contact is a company rather than a household.
     *
     * Read off the stored client_type, the same value Project::isResidential()
     * reads, so the two can never disagree about what kind of project this is.
     */
    public function isCommercial(): bool
    {
        return mb_strtolower(trim((string) $this->client_type)) === 'commercial';
    }

    /**
     * Who this project is FOR, in the words that identify it.
     *
     * On commercial work that is the company: the job belongs to ABC
     * Construction Corporation, and the person named on it is whoever answers
     * the phone about it. On residential work it is the person, because there
     * is no one else it could be.
     *
     * A company with no name recorded falls back to the person rather than to
     * "N/A" - a heading is no place to report a blank field, and the person is
     * still a true answer to "whose project is this?".
     *
     * Display only. Nothing here writes anything, no field is renamed, and both
     * values stay exactly as they are stored - see secondaryName(), which shows
     * whichever of the two this one did not.
     */
    public function primaryName(): string
    {
        if ($this->isCommercial() && filled($this->company_name)) {
            return (string) $this->company_name;
        }

        return (string) ($this->fullname ?: $this->company_name ?: 'N/A');
    }

    /**
     * The other one of the pair, or null when there is no second thing to say.
     *
     * Commercial work always has one - the contact person - and residential
     * work only has one when a company was recorded against it anyway, which
     * happens and is worth keeping on screen rather than dropping.
     */
    public function secondaryName(): ?string
    {
        $primary = $this->primaryName();

        $other = $this->isCommercial() && filled($this->company_name)
            ? $this->fullname
            : $this->company_name;

        return filled($other) && $other !== $primary ? (string) $other : null;
    }

    /**
     * What the secondary line is, so it reads as information rather than as a
     * stray second name: "Project Contact" under a company, "Company" under a
     * person.
     */
    public function secondaryLabel(): ?string
    {
        if ($this->secondaryName() === null) {
            return null;
        }

        return $this->isCommercial() && filled($this->company_name)
            ? 'Project Contact'
            : 'Company';
    }

    /**
     * Whether an administrator has deliberately taken the account off.
     *
     * The difference between "nobody has registered yet" and "this is not
     * their project": both leave user_id null, and only the second one must
     * survive the address fallback and the next registration.
     */
    public function hasUnlinkedAccount(): bool
    {
        return $this->user_id === null && $this->user_unlinked_at !== null;
    }
}
