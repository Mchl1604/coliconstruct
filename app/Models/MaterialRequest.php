<?php

namespace App\Models;

use Database\Factories\MaterialRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lead asking for material for a project they run.
 *
 * Nothing moves until it is approved: approving adds it to the project and
 * takes it off the shelf through ProjectMaterialLedger, in the same transaction.
 * See MaterialRequests.
 */
class MaterialRequest extends Model
{
    /** @use HasFactory<MaterialRequestFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

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
        'quantity',
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

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
