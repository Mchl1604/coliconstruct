<?php

namespace App\Models;

use Database\Factories\MaterialSubtypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One kind within a material type - Split-Type under Aircon Units, Copper Tube
 * under Copper & Fittings. Kept by Purchasing beside the types themselves.
 */
class MaterialSubtype extends Model
{
    /** @use HasFactory<MaterialSubtypeFactory> */
    use HasFactory;

    /**
     * The sub-types each starting material type begins with, and the words in
     * a material's name that file it under one - checked in order, first
     * match wins, ignoring case.
     *
     * Only a starting point. Purchasing adds, renames and removes them on the
     * Inventory page; applyStartingList() never undoes that. Types with no
     * natural kinds - Consumables - have none.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    public const STARTING_LIST = [
        'Aircon Units' => [
            'Split-Type' => ['split-type', 'split type'],
            'Window-Type' => ['window-type', 'window type'],
            'Ceiling Cassette' => ['cassette'],
            'Ceiling-Suspended' => ['ceiling-suspended', 'ceiling suspended'],
            'Floor-Mounted' => ['floor-mounted', 'floor mounted', 'floor-standing'],
            'Ducted' => ['ducted'],
            'VRF' => ['vrf', 'vrv'],
            'Portable' => ['portable'],
        ],
        'Refrigerants & Gases' => [
            'Refrigerant' => ['refrigerant'],
            'Nitrogen' => ['nitrogen'],
        ],
        'Copper & Fittings' => [
            'Copper Tube' => ['copper tube', 'copper pipe'],
            'Fittings' => ['flare nut', 'elbow', 'coupling', 'reducer'],
            'Brazing' => ['brazing', 'solder'],
        ],
        'Insulation' => [
            'Pipe Insulation' => ['rubber foam', 'pipe insulation'],
            'Insulation Tape' => ['insulation tape'],
            'Duct Wrap' => ['duct wrap', 'fiberglass'],
        ],
        'Electrical' => [
            'Wire & Cable' => ['wire', 'cable'],
            'Breakers & Switches' => ['breaker', 'switch'],
            'Conduit' => ['conduit'],
            'Electrical Tape' => ['tape'],
        ],
        'Drainage & PVC' => [
            'Pipe' => ['pvc pipe'],
            'Fittings' => ['elbow', 'coupling'],
            'Drain Hose' => ['hose'],
            'Adhesives' => ['cement', 'solvent'],
        ],
        'Ducting' => [
            'G.I. Sheet' => ['g.i. sheet', 'gi sheet'],
            'Flexible Duct' => ['flexible duct'],
            'Duct Accessories' => ['canvas', 'damper', 'threaded rod', 'hanger'],
            'Sealants & Tape' => ['sealant', 'mastic', 'tape'],
        ],
        'Grilles & Diffusers' => [
            'Diffuser' => ['diffuser'],
            'Grille' => ['grille'],
        ],
        'Fans & Ventilation' => [
            'Exhaust Fan' => ['exhaust fan'],
            'Inline Fan' => ['inline'],
            'Centrifugal Fan' => ['centrifugal'],
            'Louver' => ['louver'],
        ],
        'Mounting & Hardware' => [
            'Brackets' => ['bracket'],
            'Anchors & Screws' => ['anchor', 'screw', 'bolt', 'rivet'],
            'Vibration Pads' => ['pad'],
            'Cable Ties' => ['cable tie'],
        ],
        'Spare Parts' => [
            'Electrical Parts' => ['capacitor', 'pcb', 'board', 'thermostat', 'remote'],
            'Motors & Compressors' => ['motor', 'compressor'],
            'Refrigeration Parts' => ['drier', 'valve'],
        ],
        'Cleaning Supplies' => [
            'Chemicals' => ['cleaner'],
            'Accessories' => ['bag', 'filter'],
        ],
    ];

    protected $table = 'tbl_material_subtypes';

    protected $primaryKey = 'material_subtype_id';

    protected $fillable = [
        'material_type_id',
        'subtype_name',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(MaterialType::class, 'material_type_id', 'material_type_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class, 'material_subtype_id', 'material_subtype_id');
    }

    /**
     * Give each starting type that exists its missing starting sub-types, and
     * file every material that has none under the first whose words its name
     * contains.
     *
     * Safe to run again: a sub-type already there - whatever its casing - is
     * not added twice, and a material that already has a sub-type, chosen by
     * anybody, is left alone. Written against the tables rather than the
     * models so a migration can call it.
     *
     * @return int How many materials were filed.
     */
    public static function applyStartingList(): int
    {
        $filed = 0;

        foreach (self::STARTING_LIST as $typeName => $subtypes) {
            $typeId = DB::table('tbl_material_types')
                ->whereRaw('LOWER(type_name) = ?', [mb_strtolower($typeName)])
                ->value('material_type_id');

            if ($typeId === null) {
                continue;
            }

            $ids = [];

            foreach (array_keys($subtypes) as $subtypeName) {
                $ids[$subtypeName] = DB::table('tbl_material_subtypes')
                    ->where('material_type_id', $typeId)
                    ->whereRaw('LOWER(subtype_name) = ?', [mb_strtolower($subtypeName)])
                    ->value('material_subtype_id')
                    ?? DB::table('tbl_material_subtypes')->insertGetId([
                        'material_type_id' => $typeId,
                        'subtype_name' => $subtypeName,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $unfiled = DB::table('tbl_materials')
                ->where('material_type_id', $typeId)
                ->whereNull('material_subtype_id')
                ->get(['material_id', 'name']);

            foreach ($unfiled as $material) {
                $subtypeName = self::matchFor((string) $material->name, $subtypes);

                if ($subtypeName === null) {
                    continue;
                }

                DB::table('tbl_materials')
                    ->where('material_id', $material->material_id)
                    ->update(['material_subtype_id' => $ids[$subtypeName]]);

                $filed++;
            }
        }

        return $filed;
    }

    /**
     * The first sub-type whose words the name contains.
     *
     * @param  array<string, array<int, string>>  $subtypes
     */
    private static function matchFor(string $name, array $subtypes): ?string
    {
        $name = mb_strtolower($name);

        foreach ($subtypes as $subtypeName => $words) {
            foreach ($words as $word) {
                if (str_contains($name, $word)) {
                    return $subtypeName;
                }
            }
        }

        return null;
    }
}
