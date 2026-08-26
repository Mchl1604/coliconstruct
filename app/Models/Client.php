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
