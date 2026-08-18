<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends Model
{
    public $timestamps = false;

    protected $table = 'tbl_clients';

    protected $primaryKey = 'client_id';

    protected $fillable = [
        'project_id',
        'user_id',
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
     * The client account this contact belongs to, once there is one.
     *
     * Null while a project has been booked for somebody who has not registered
     * yet, which is the ordinary case at the start: the address is what
     * connects them until they do.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
