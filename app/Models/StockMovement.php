<?php

namespace App\Models;

use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a material in or out of stock.
 *
 * Never edited: a wrong entry is voided and stays on the history, struck
 * through, so every on-hand figure can be traced. The quantity carries its
 * own sign - see the table's migration - and StockLedger is the only thing
 * that writes one.
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    /**
     * Delivered by a supplier.
     */
    public const TYPE_STOCK_IN = 'stock_in';

    /**
     * Issued to a project, used up, damaged or lost.
     */
    public const TYPE_STOCK_OUT = 'stock_out';

    /**
     * Brought back from a site.
     */
    public const TYPE_RETURNED = 'returned';

    /**
     * A count that did not match the record, set right. Adds or takes away.
     */
    public const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Every type, with its label.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        self::TYPE_STOCK_IN => 'Stock In',
        self::TYPE_STOCK_OUT => 'Stock Out',
        self::TYPE_RETURNED => 'Returned',
        self::TYPE_ADJUSTMENT => 'Adjustment',
    ];

    /**
     * The two a Stock In records.
     *
     * @var array<int, string>
     */
    public const INCOMING_TYPES = [
        self::TYPE_STOCK_IN,
        self::TYPE_RETURNED,
    ];

    protected $table = 'tbl_stock_movements';

    protected $primaryKey = 'stock_movement_id';

    protected $fillable = [
        'material_id',
        'movement_type',
        'quantity',
        'movement_date',
        'reference_no',
        'supplier',
        'project_id',
        'project_material_entry_id',
        'is_reservation',
        'is_assignment',
        'notes',
        'recorded_by',
        'recorded_by_name',
        'voided_at',
        'voided_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'movement_date' => 'date',
        'is_reservation' => 'boolean',
        'is_assignment' => 'boolean',
        'voided_at' => 'datetime',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    /**
     * The entry on a project's Materials tab that caused this movement, when
     * one did - see StockLedger::issueForEntry().
     */
    public function projectEntry(): BelongsTo
    {
        return $this->belongsTo(ProjectMaterialEntry::class, 'project_material_entry_id', 'material_entry_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by', 'id');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by', 'id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /**
     * The movements that change the count: not voided, and not one of the old
     * reservation rows, which recorded a claim while the stock stayed on the
     * shelf. A plan's assignment does count - it is a real stock out.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereNull('voided_at')->where('is_reservation', false);
    }

    /**
     * Stock a Used entry took straight off the shelf, from a plan nobody had
     * released yet. Only written before plans became stock outs.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsedOnSite(Builder $query): Builder
    {
        return $query->whereHas('projectEntry', fn (Builder $entry) => $entry->where('entry_type', ProjectMaterialEntry::TYPE_USED));
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->movement_type] ?? ucfirst((string) $this->movement_type);
    }

    /**
     * Who recorded it, as the history prints it.
     */
    public function recorderName(): string
    {
        return $this->recorder?->fullName() ?? $this->recorded_by_name ?? 'System';
    }
}
