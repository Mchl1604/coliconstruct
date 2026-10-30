<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody an incident involved - a member of the project's team, or anyone
 * else by name - and how: injured, involved or a witness.
 */
class IncidentPerson extends Model
{
    protected $table = 'tbl_incident_persons';

    protected $primaryKey = 'incident_person_id';

    protected $fillable = [
        'incident_report_id',
        'technician_id',
        'name',
        'person_type',
        'involvement',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'incident_report_id', 'incident_report_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id', 'technician_id');
    }

    public function typeLabel(): string
    {
        return IncidentReport::PERSON_TYPES[$this->person_type] ?? ucfirst((string) $this->person_type);
    }

    public function involvementLabel(): string
    {
        return IncidentReport::INVOLVEMENTS[$this->involvement] ?? ucfirst((string) $this->involvement);
    }
}
