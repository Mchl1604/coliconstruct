<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Technician extends Model
{
    protected $table = 'tbl_technicians';

    protected $primaryKey = 'technician_id';

    protected $fillable = [
        'account_id',
        'status',
        'skill_id',
        'fullName',
    ];
}
