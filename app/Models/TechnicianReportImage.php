<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * The report this picture belongs to.
     *
     * The key is named because Eloquent would otherwise guess it from this
     * method - `report_id`, a column that does not exist. The relation then
     * resolved to null for every row, and because UploadedFileController reads
     * the project through it to decide who may see the file, every report
     * image answered 404 rather than its bytes.
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(TechnicianReport::class, 'technician_report_id', 'id');
    }
}
