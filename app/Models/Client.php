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
}
