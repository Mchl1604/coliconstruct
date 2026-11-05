<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a project's quotation breakdown.
 *
 * Reads on its own: the description, unit and price are kept as quoted, so a
 * material renamed or repriced in Inventory later does not change a quotation
 * already given. Never limited by stock - see ProjectMaterialPlan for what is.
 */
class QuotationItem extends Model
{
    public const KIND_MATERIAL = 'material';

    public const KIND_LABOR = 'labor';

    public const KIND_OTHER = 'other';

    /**
     * Each kind and its heading on the printed quotation, in print order.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        self::KIND_MATERIAL => 'Materials',
        self::KIND_LABOR => 'Labor',
        self::KIND_OTHER => 'Other',
    ];

    protected $table = 'tbl_quotation_items';

    protected $primaryKey = 'quotation_item_id';

    protected $fillable = [
        'project_id',
        'kind',
        'material_id',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    public function amount(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }
}
