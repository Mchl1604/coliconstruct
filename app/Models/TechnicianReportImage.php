<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicianReportImage extends Model
{
    protected $table = 'tbl_technician_report_images';

    protected $primaryKey = 'id';
    
    protected $fillable = [
        'technician_report_id',
        'image_path',
    ];

    public function report()
    {
        return $this->belongsTo(TechnicianReport::class);
    }
}
