<?php

namespace App\Models;

use Database\Factories\MaterialRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lead asking for material for a project they run - or asking to send some
 * back.
 *
 * Nothing moves until it is approved: approving a supply request adds it to
 * the project and takes it off the shelf, and approving a return records it
 * and puts it back on the shelf, through ProjectMaterialLedger in the same
 * transaction. See MaterialRequests.
 */
class MaterialRequest extends Model
{
    /** @use HasFactory<MaterialRequestFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** More material for the project. */
    public const TYPE_SUPPLY = 'supply';

    /** Material going back from the site to the warehouse. */
    public const TYPE_RETURN = 'return';

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    protected $table = 'tbl_material_requests';

    protected $primaryKey = 'material_request_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'material_id',
        'request_type',
        'project_material_id',
        'quantity',
        'entry_date',
        'notes',
        'status',
        'requested_by',
        'decided_by',
        'decided_at',
        'rejection_reason',
        'project_material_entry_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'decided_at' => 'datetime',
            'entry_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function projectMaterial(): BelongsTo
    {
        return $this->belongsTo(ProjectMaterial::class, 'project_material_id', 'project_material_id');
    }

    public function isReturn(): bool
    {
        return $this->request_type === self::TYPE_RETURN;
    }

    public function typeLabel(): string
    {
        return $this->isReturn() ? 'Return' : 'Request';
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
