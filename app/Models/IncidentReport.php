<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The structured details of one incident report - see the table's migration,
 * and IncidentReporting, which writes them.
 *
 * Every choice is a fixed list below, so incidents can be counted and
 * compared. The labels are what every form and page prints.
 */
class IncidentReport extends Model
{
    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'injury' => 'Injury',
        'near_miss' => 'Near Miss',
        'property_damage' => 'Client Property Damage',
        'equipment_damage' => 'Equipment / Tool Damage',
        'refrigerant_leak' => 'Refrigerant Leak',
        'electrical' => 'Electrical (shock / short)',
        'fire' => 'Fire / Smoke',
        'fall' => 'Fall from Height',
        'vehicle' => 'Vehicle Accident',
        'theft' => 'Theft / Loss',
        'complaint' => 'Client Complaint',
        'other' => 'Other',
    ];

    /**
     * @var array<string, string>
     */
    public const SEVERITIES = [
        'minor' => 'Minor',
        'moderate' => 'Moderate',
        'serious' => 'Serious',
        'critical' => 'Critical',
    ];

    /**
     * The lists whose Other asks what, each with the incident type it belongs
     * to where it only applies to one.
     *
     * @var array<string, string|null>
     */
    public const OTHER_FIELDS = [
        'incident_type' => null,
        'location' => null,
        'injury_body_part' => 'injury',
        'injury_type' => 'injury',
        'refrigerant_type' => 'refrigerant_leak',
        'cause' => null,
    ];

    /**
     * @var array<string, string>
     */
    public const LOCATIONS = [
        'indoor_unit' => 'Indoor unit area',
        'outdoor_unit' => 'Outdoor unit / wall / roof',
        'electrical_panel' => 'Electrical panel',
        'ceiling' => 'Ceiling / attic',
        'premises' => 'Client premises',
        'transit' => 'Vehicle / in transit',
        'warehouse' => 'Warehouse',
        'other' => 'Other',
    ];

    /**
     * @var array<string, string>
     */
    public const BODY_PARTS = [
        'head' => 'Head',
        'eyes' => 'Eyes',
        'hand' => 'Hand / fingers',
        'arm' => 'Arm / shoulder',
        'back' => 'Back',
        'leg' => 'Leg / knee',
        'foot' => 'Foot',
        'multiple' => 'Multiple',
        'other' => 'Other',
    ];

    /**
     * @var array<string, string>
     */
    public const INJURY_TYPES = [
        'cut' => 'Cut / laceration',
        'burn' => 'Burn',
        'electric_shock' => 'Electric shock',
        'fracture' => 'Fracture',
        'sprain' => 'Sprain / strain',
        'eye_injury' => 'Eye injury',
        'inhalation' => 'Inhalation',
        'other' => 'Other',
    ];

    /**
     * @var array<string, string>
     */
    public const TREATMENTS = [
        'none' => 'None',
        'first_aid' => 'First aid',
        'clinic' => 'Clinic / hospital',
        'lost_time' => 'Lost time',
    ];

    /**
     * @var array<string, string>
     */
    public const DAMAGE_OWNERS = [
        'client' => 'Client',
        'company' => 'Company',
        'third_party' => 'Third party',
    ];

    /**
     * @var array<string, string>
     */
    public const REFRIGERANTS = [
        'R32' => 'R32',
        'R410A' => 'R410A',
        'R22' => 'R22',
        'R134a' => 'R134a',
        'other' => 'Other',
    ];

    /**
     * @var array<string, string>
     */
    public const CAUSES = [
        'unsafe_act' => 'Unsafe act',
        'unsafe_condition' => 'Unsafe condition',
        'equipment_failure' => 'Equipment failure',
        'no_ppe' => 'No / improper PPE',
        'procedure' => 'Procedure not followed',
        'weather' => 'Weather',
        'other' => 'Other',
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        self::STATUS_OPEN => 'Unresolved',
        self::STATUS_CLOSED => 'Resolved',
    ];

    /**
     * @var array<string, string>
     */
    public const PERSON_TYPES = [
        'team' => 'Team member',
        'client' => 'Client',
        'client_staff' => 'Client staff',
        'subcontractor' => 'Subcontractor',
        'public' => 'Public',
    ];

    /**
     * @var array<string, string>
     */
    public const INVOLVEMENTS = [
        'injured' => 'Injured',
        'involved' => 'Involved',
        'witness' => 'Witness',
    ];

    protected $table = 'tbl_incident_reports';

    protected $primaryKey = 'incident_report_id';

    protected $fillable = [
        'technician_report_id',
        'incident_type',
        'severity',
        'occurred_at',
        'phase_id',
        'task_id',
        'location',
        'location_details',
        'injury_body_part',
        'injury_type',
        'injury_treatment',
        'damage_description',
        'damage_owner',
        'damage_cost',
        'refrigerant_type',
        'refrigerant_amount',
        'immediate_action',
        'cause',
        'preventive_action',
        'work_stopped',
        'client_notified',
        'other_details',
        'status',
        'resolution_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'damage_cost' => 'decimal:2',
        'refrigerant_amount' => 'decimal:2',
        'work_stopped' => 'boolean',
        'client_notified' => 'boolean',
        'other_details' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(TechnicianReport::class, 'technician_report_id', 'id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'phase_id', 'phase_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id', 'task_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'id');
    }

    public function persons(): HasMany
    {
        return $this->hasMany(IncidentPerson::class, 'incident_report_id', 'incident_report_id');
    }

    /**
     * The label for one of this report's choices, by the name of its list:
     * label('TYPES', 'incident_type').
     */
    public function label(string $list, string $attribute): ?string
    {
        $value = $this->getAttribute($attribute);

        if ($value === 'other' && filled($this->other_details[$attribute] ?? null)) {
            return $this->other_details[$attribute];
        }

        return $value === null ? null : (constant(self::class.'::'.$list)[$value] ?? ucfirst((string) $value));
    }

    /**
     * Whether this may be a case the company has to report to DOLE: an
     * injury that cost working time. A reminder for the office, not a
     * decision - see Rule 1050 of the OSH Standards.
     */
    public function mayNeedDoleReport(): bool
    {
        return $this->incident_type === 'injury' && $this->injury_treatment === 'lost_time';
    }

    public function statusBadgeClass(): string
    {
        return $this->status === self::STATUS_CLOSED ? 'bg-success' : 'bg-danger';
    }
}
