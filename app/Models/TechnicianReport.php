<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicianReport extends Model
{
    protected $table = 'tbl_technician_reports';

    protected $primaryKey = 'id';
    
     protected $fillable = [
        'project_id',
        'technician_id',
        'report_title',
        'report_description',
        'report_date',
        'report_type',
    ];

    public function images()
    {
        return $this->hasMany(TechnicianReportImage::class);
    }
}
