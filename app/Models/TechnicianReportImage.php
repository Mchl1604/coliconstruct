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

    /**
     * Where a page links to this image - a route that checks the asking
     * account against the report's project, never the file's own location.
     */
    public function url(): string
    {
        return route('media.report-image', ['image' => $this->getKey()]);
    }

    public function report()
    {
        return $this->belongsTo(TechnicianReport::class);
    }
}
