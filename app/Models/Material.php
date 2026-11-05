<?php

namespace App\Models;

use App\Support\MaterialUnit;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry of the material list, and the stock held of it.
 *
 * How many the company holds is never stored here: it is the sum of the
 * material's stock movements, read through withOnHand() - see StockLedger.
 * What is stored is the level at which it counts as Low Stock.
 */
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    protected $table = 'tbl_materials';

    protected $primaryKey = 'material_id';

    protected $fillable = [
        'material_type_id',
        'material_subtype_id',
        'brand_id',
        'name',
        'unit',
        'description',
        'reorder_level',
        'unit_price',
        'is_active',
        'created_by',
        'created_from_project_id',
    ];

    /**
     * Above the reorder level, or with no level set.
     */
    public const STOCK_IN = 'in_stock';

    /**
     * Some left, but at or below the reorder level.
     */
    public const STOCK_LOW = 'low_stock';

    /**
     * None left.
     */
    public const STOCK_OUT = 'out_of_stock';

    /**
     * Every stock status, with its label.
     *
     * @var array<string, string>
     */
    public const STOCK_STATUSES = [
        self::STOCK_IN => 'In Stock',
        self::STOCK_LOW => 'Low Stock',
        self::STOCK_OUT => 'Out of Stock',
    ];

    /**
     * The brand travels with the material everywhere it is printed - every
     * list, picker and history shows "Name (Brand)" - so it is always loaded
     * with it rather than asked for by each of them. The sub-type rides along
     * for the same reason: every list that names the type names it too.
     *
     * @var array<int, string>
     */
    protected $with = ['brand', 'subtype'];

    protected $casts = [
        'unit' => MaterialUnit::class,
        'reorder_level' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(MaterialType::class, 'material_type_id', 'material_type_id');
    }

    /**
     * The kind within its type - Split-Type within Aircon Units. Optional.
     */
    public function subtype(): BelongsTo
    {
        return $this->belongsTo(MaterialSubtype::class, 'material_subtype_id', 'material_subtype_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id', 'brand_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'material_id', 'material_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * The project it was added from, when it was not added in System
     * Settings.
     */
    public function sourceProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'created_from_project_id', 'project_id');
    }

    public function projectMaterials(): HasMany
    {
        return $this->hasMany(ProjectMaterial::class, 'material_id', 'material_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Each material with an on_hand column: the sum of its movements that
     * are not voided, zero for one that has never had any.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithOnHand(Builder $query): Builder
    {
        // A builder rather than raw SQL: addSelect() only keeps the row's
        // own columns, and the alias, for a subquery it can see is one.
        return $query->addSelect(['on_hand' => StockMovement::query()
            ->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('tbl_stock_movements.material_id', 'tbl_materials.material_id')
            ->whereNull('tbl_stock_movements.voided_at'),
        ]);
    }

    /**
     * Only the materials in one stock status - see STOCK_STATUSES.
     *
     * Asked of the same sum withOnHand() reads, written out again because a
     * select's alias cannot be used in its own WHERE.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInStockStatus(Builder $query, string $status): Builder
    {
        $onHand = self::onHandSql();

        return match ($status) {
            self::STOCK_OUT => $query->whereRaw($onHand.' <= 0'),
            self::STOCK_LOW => $query->whereRaw($onHand.' > 0')
                ->whereNotNull('reorder_level')
                ->whereRaw($onHand.' <= reorder_level'),
            self::STOCK_IN => $query->whereRaw($onHand.' > 0')
                ->where(fn (Builder $level) => $level
                    ->whereNull('reorder_level')
                    ->orWhereRaw($onHand.' > reorder_level')),
            default => $query,
        };
    }

    /**
     * How many are held: the on_hand column when the row was read with
     * withOnHand(), and the movements themselves otherwise.
     */
    public function onHand(): float
    {
        if (array_key_exists('on_hand', $this->attributes)) {
            return round((float) $this->attributes['on_hand'], 2);
        }

        return round((float) $this->stockMovements()->notVoided()->sum('quantity'), 2);
    }

    /**
     * A STOCK_STATUSES key for this material, at the given on-hand figure or
     * at its current one.
     */
    public function stockStatus(?float $onHand = null): string
    {
        $onHand ??= $this->onHand();

        if ($onHand <= 0) {
            return self::STOCK_OUT;
        }

        if ($this->reorder_level !== null && $onHand <= (float) $this->reorder_level) {
            return self::STOCK_LOW;
        }

        return self::STOCK_IN;
    }

    public function brandName(): ?string
    {
        return $this->brand?->brand_name;
    }

    public function subtypeName(): ?string
    {
        return $this->subtype?->subtype_name;
    }

    /**
     * The name as a list prints it: "Split-Type Inverter 1.5 HP (Daikin)".
     */
    public function displayName(): string
    {
        $brand = $this->brandName();

        return $brand
            ? sprintf('%s (%s)', $this->name, $brand)
            : $this->name;
    }

    /**
     * Everything a picker needs to show and choose this material.
     *
     * @return array{material_id: int, name: string, brand: string|null, brand_id: int|null, label: string, type: string|null, material_subtype_id: int|null, subtype: string|null, unit: string, unit_label: string, allows_fractions: bool, description: string|null, reorder_level: float|null, is_active: bool}
     */
    public function toOption(): array
    {
        return [
            'material_id' => (int) $this->material_id,
            'name' => $this->name,
            'brand' => $this->brandName(),
            'brand_id' => $this->brand_id === null ? null : (int) $this->brand_id,
            'label' => $this->displayName(),
            'type' => $this->type?->type_name,
            'material_subtype_id' => $this->material_subtype_id === null ? null : (int) $this->material_subtype_id,
            'subtype' => $this->subtypeName(),
            'unit' => $this->unit->value,
            'unit_label' => $this->unit->label(),
            'allows_fractions' => $this->unit->allowsFractions(),
            'description' => $this->description,
            'reorder_level' => $this->reorder_level === null ? null : (float) $this->reorder_level,
            'is_active' => (bool) $this->is_active,
        ];
    }

    /**
     * The on-hand sum as SQL, correlated to the material row being read.
     */
    private static function onHandSql(): string
    {
        return '(SELECT COALESCE(SUM(quantity), 0) FROM tbl_stock_movements'
            .' WHERE tbl_stock_movements.material_id = tbl_materials.material_id'
            .' AND tbl_stock_movements.voided_at IS NULL)';
    }
}
