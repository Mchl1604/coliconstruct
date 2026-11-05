<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialSubtype;
use App\Models\MaterialType;
use App\Support\MaterialUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The material list an aircon and ventilation contractor starts with: units
 * by brand and capacity, refrigerant, copper, insulation, electrical, drain
 * line, ducting, grilles, fans, mounting hardware, spare parts, cleaning
 * supplies and consumables.
 *
 *     php artisan db:seed --class=MaterialSeeder --force
 *
 * Add-only. A type or a material already on the list - same name, brand and
 * unit, whatever the casing - is left as it is, except that a blank price is
 * filled from PRICES, so nothing edited on the site is changed.
 * DemoDataSeeder runs it before it sets its projects' materials up.
 */
class MaterialSeeder extends Seeder
{
    /**
     * What each material on the starting list sells for, in pesos, by
     * "name|brand". Fills a blank price only: one set in Inventory stays.
     *
     * @var array<string, float|int>
     */
    public const PRICES = [
        'Split-Type Inverter 1.0 HP|Daikin' => 32500,
        'Split-Type Inverter 1.5 HP|Daikin' => 41500,
        'Split-Type Inverter 2.0 HP|Daikin' => 52000,
        'Split-Type Inverter 1.0 HP|Carrier' => 29500,
        'Split-Type Inverter 1.5 HP|Carrier' => 37500,
        'Split-Type Inverter 2.5 HP|Carrier' => 58000,
        'Split-Type Inverter 1.0 HP|Panasonic' => 30500,
        'Split-Type Inverter 1.5 HP|Panasonic' => 39000,
        'Split-Type Inverter 1.5 HP|LG' => 36500,
        'Split-Type Inverter 2.0 HP|LG' => 46500,
        'Split-Type Inverter 1.5 HP|Samsung' => 38500,
        'Split-Type Inverter 1.0 HP|Koppel' => 25500,
        'Split-Type Inverter 1.5 HP|Koppel' => 32000,
        'Split-Type Inverter 1.0 HP|Midea' => 22500,
        'Split-Type Non-Inverter 1.5 HP|Kolin' => 24500,
        'Split-Type Inverter 1.0 HP|Aux' => 21500,
        'Window-Type Inverter 1.0 HP|Condura' => 19500,
        'Window-Type Inverter 1.5 HP|Carrier' => 26500,
        'Window-Type Non-Inverter 0.75 HP|Kolin' => 12500,
        'Ceiling Cassette Inverter 2.5 HP|Daikin' => 78000,
        'Ceiling Cassette Inverter 3.0 HP|Carrier' => 86000,
        'Ceiling Cassette Inverter 4.0 HP|Midea' => 92000,
        'Floor-Mounted 3.0 TR|Carrier' => 165000,
        'Floor-Mounted 5.0 TR|Koppel' => 215000,
        'Ceiling-Suspended 3.0 TR|Daikin' => 158000,
        'Ducted Split 5.0 TR|Daikin' => 245000,
        'Ducted Split 7.5 TR|Carrier' => 335000,
        'VRF Outdoor Unit 10 HP|Mitsubishi Electric' => 425000,
        'VRF Indoor Unit 1.5 HP|Mitsubishi Electric' => 38500,
        'Refrigerant R32|Chemours' => 1150,
        'Refrigerant R410A|Honeywell' => 950,
        'Refrigerant R22|Honeywell' => 1250,
        'Nitrogen, Dry|' => 1800,
        'Copper Tube 1/4"|Mueller' => 165,
        'Copper Tube 3/8"|Mueller' => 235,
        'Copper Tube 1/2"|Mueller' => 310,
        'Copper Tube 5/8"|Mueller' => 395,
        'Copper Tube 3/4"|Kembla' => 485,
        'Flare Nut 1/4"|' => 45,
        'Flare Nut 1/2"|' => 85,
        'Copper Elbow 5/8"|' => 95,
        'Brazing Rod 15% Silver|Harris' => 185,
        'Rubber Foam Insulation 3/8" x 3/4"|Armaflex' => 145,
        'Rubber Foam Insulation 5/8" x 3/4"|Armaflex' => 195,
        'Rubber Foam Insulation 1/2" x 3/4"|K-Flex' => 165,
        'Insulation Tape 2"|Armaflex' => 225,
        'Fiberglass Duct Wrap 1"|Owens Corning' => 4850,
        'THHN Wire 2.0 mm²|Phelps Dodge' => 28,
        'THHN Wire 3.5 mm²|Phelps Dodge' => 42,
        'THHN Wire 5.5 mm²|Phelps Dodge' => 65,
        'THHN Wire 3.5 mm²|Columbia' => 38,
        'Control Cable 4-Core|' => 75,
        'Circuit Breaker 2P 20A|Schneider Electric' => 485,
        'Circuit Breaker 2P 30A|Schneider Electric' => 545,
        'Safety Switch 30A|GE' => 1450,
        'Flexible Conduit 1/2"|' => 55,
        'PVC Electrical Conduit 1/2"|Neltex' => 95,
        'Electrical Tape|3M' => 85,
        'PVC Pipe 3/4"|Neltex' => 165,
        'PVC Pipe 1"|Atlanta' => 225,
        'PVC Elbow 3/4"|Neltex' => 25,
        'Drain Hose 5/8"|' => 45,
        'PVC Solvent Cement 100cc|Neltex' => 95,
        'G.I. Sheet Gauge 24|' => 1250,
        'G.I. Sheet Gauge 26|' => 950,
        'Flexible Duct 8"|' => 285,
        'Flexible Duct 10"|' => 345,
        'Canvas Connector|' => 420,
        'Volume Damper 10"|' => 1350,
        'Duct Sealant Mastic|Hardcast' => 1650,
        'Aluminum Foil Tape 2"|3M' => 385,
        'Threaded Rod 3/8"|' => 185,
        'Square Ceiling Diffuser 12" x 12"|' => 1250,
        'Linear Slot Diffuser 4 ft|' => 3450,
        'Return Air Grille 24" x 12"|' => 1650,
        'Exhaust Grille 10" x 10"|' => 750,
        'Ceiling Exhaust Fan 10"|Panasonic' => 2850,
        'Wall Exhaust Fan 12"|Hanabishi' => 1950,
        'Inline Duct Fan 8"|KDK' => 6500,
        'Centrifugal Fan 2 HP|' => 38500,
        'Fresh Air Louver 24" x 24"|' => 2450,
        'Outdoor Unit Wall Bracket 1.0-2.0 HP|' => 650,
        'Outdoor Unit Wall Bracket 2.5-3.0 HP|' => 950,
        'Anti-Vibration Rubber Pad|' => 350,
        'Expansion Anchor Bolt 3/8"|' => 35,
        'Plastic Anchor with Screw|' => 185,
        'Tek Screw 1"|' => 225,
        'Blind Rivet 1/8"|' => 650,
        'Cable Tie 8"|' => 95,
        'Run Capacitor 35+5 µF|' => 450,
        'Indoor Fan Motor, Split-Type|' => 2850,
        'Rotary Compressor 1.5 HP|GMCC' => 9500,
        'PCB Control Board, Split-Type|' => 3250,
        'Universal Aircon Remote|' => 450,
        'Filter Drier 1/4"|Danfoss' => 650,
        'Thermostat, Digital|Honeywell' => 3850,
        'Coil Cleaner, Alkaline|Nu-Calgon' => 1250,
        'Evaporator Coil Cleaner Spray|Nu-Calgon' => 485,
        'Aircon Cleaning Bag|' => 650,
        'Washable Air Filter|' => 185,
        'Duct Tape 2"|' => 125,
        'Teflon Tape|' => 25,
        'Silicone Sealant, Clear|Bostik' => 245,
        'Cotton Rags|' => 75,
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $existing = Material::query()
                ->get()
                ->keyBy(fn (Material $material): string => self::key($material->name, $material->brandName(), $material->unit->value));

            $added = 0;

            foreach (self::catalog() as $typeName => $materials) {
                $type = MaterialType::query()->whereRaw('LOWER(type_name) = ?', [mb_strtolower($typeName)])->first()
                    ?? MaterialType::create(['type_name' => $typeName]);

                foreach ($materials as [$name, $brand, $unit, $description]) {
                    $price = self::PRICES[$name.'|'.$brand] ?? null;
                    $listed = $existing->get(self::key($name, $brand, $unit->value));

                    if ($listed) {
                        // Only a blank price: one set in Inventory stays.
                        if ($listed->unit_price === null && $price !== null) {
                            $listed->update(['unit_price' => $price]);
                        }

                        continue;
                    }

                    Material::create([
                        'material_type_id' => $type->material_type_id,
                        'brand_id' => $brand === null ? null : self::brand($brand)->brand_id,
                        'name' => $name,
                        'unit' => $unit,
                        'description' => $description,
                        'unit_price' => $price,
                        'is_active' => true,
                    ]);

                    $added++;
                }
            }

            // Sub-types last, so the materials just added are filed too.
            $filed = MaterialSubtype::applyStartingList();

            $this->command?->info(sprintf('Material list ready: %d materials added, %d filed under a sub-type.', $added, $filed));
        });
    }

    /**
     * The brand by this name, whatever its casing, added if the list does not
     * have it yet.
     */
    private static function brand(string $name): Brand
    {
        return Brand::query()->whereRaw('LOWER(brand_name) = ?', [mb_strtolower($name)])->first()
            ?? Brand::create(['brand_name' => $name]);
    }

    /**
     * Every type with its materials: [name, brand, unit, description].
     *
     * @return array<string, array<int, array{0: string, 1: string|null, 2: MaterialUnit, 3: string|null}>>
     */
    public static function catalog(): array
    {
        $unit = MaterialUnit::Unit;
        $piece = MaterialUnit::Piece;

        return [
            'Aircon Units' => [
                ['Split-Type Inverter 1.0 HP', 'Daikin', $unit, 'Wall-mounted, R32'],
                ['Split-Type Inverter 1.5 HP', 'Daikin', $unit, 'Wall-mounted, R32'],
                ['Split-Type Inverter 2.0 HP', 'Daikin', $unit, 'Wall-mounted, R32'],
                ['Split-Type Inverter 1.0 HP', 'Carrier', $unit, 'Wall-mounted, R32'],
                ['Split-Type Inverter 1.5 HP', 'Carrier', $unit, 'Wall-mounted, R32'],
                ['Split-Type Inverter 2.5 HP', 'Carrier', $unit, 'Wall-mounted, R32'],
                ['Split-Type Inverter 1.0 HP', 'Panasonic', $unit, 'Wall-mounted, R32'],
                ['Split-Type Inverter 1.5 HP', 'Panasonic', $unit, 'Wall-mounted, R32'],
                ['Split-Type Inverter 1.5 HP', 'LG', $unit, 'Dual inverter, R32'],
                ['Split-Type Inverter 2.0 HP', 'LG', $unit, 'Dual inverter, R32'],
                ['Split-Type Inverter 1.5 HP', 'Samsung', $unit, 'WindFree, R32'],
                ['Split-Type Inverter 1.0 HP', 'Koppel', $unit, 'Wall-mounted, R32'],
                ['Split-Type Inverter 1.5 HP', 'Koppel', $unit, 'Wall-mounted, R32'],
                ['Split-Type Inverter 1.0 HP', 'Midea', $unit, 'Wall-mounted, R32'],
                ['Split-Type Non-Inverter 1.5 HP', 'Kolin', $unit, 'Wall-mounted, R410A'],
                ['Split-Type Inverter 1.0 HP', 'Aux', $unit, 'Wall-mounted, R32'],
                ['Window-Type Inverter 1.0 HP', 'Condura', $unit, 'R32'],
                ['Window-Type Inverter 1.5 HP', 'Carrier', $unit, 'R32'],
                ['Window-Type Non-Inverter 0.75 HP', 'Kolin', $unit, 'R410A'],
                ['Ceiling Cassette Inverter 2.5 HP', 'Daikin', $unit, '4-way blow, R32'],
                ['Ceiling Cassette Inverter 3.0 HP', 'Carrier', $unit, '4-way blow, R32'],
                ['Ceiling Cassette Inverter 4.0 HP', 'Midea', $unit, '4-way blow, R410A'],
                ['Floor-Mounted 3.0 TR', 'Carrier', $unit, 'Floor-standing, R410A'],
                ['Floor-Mounted 5.0 TR', 'Koppel', $unit, 'Floor-standing, R410A'],
                ['Ceiling-Suspended 3.0 TR', 'Daikin', $unit, 'R410A'],
                ['Ducted Split 5.0 TR', 'Daikin', $unit, 'Concealed ducted, R410A'],
                ['Ducted Split 7.5 TR', 'Carrier', $unit, 'Concealed ducted, R410A'],
                ['VRF Outdoor Unit 10 HP', 'Mitsubishi Electric', $unit, 'Heat pump, R410A'],
                ['VRF Indoor Unit 1.5 HP', 'Mitsubishi Electric', $unit, 'Wall-mounted'],
            ],
            'Refrigerants & Gases' => [
                ['Refrigerant R32', 'Chemours', MaterialUnit::Kilogram, 'Charged by weight'],
                ['Refrigerant R410A', 'Honeywell', MaterialUnit::Kilogram, 'Charged by weight'],
                ['Refrigerant R22', 'Honeywell', MaterialUnit::Kilogram, 'For older units'],
                ['Nitrogen, Dry', null, MaterialUnit::Tank, 'Flushing and pressure test'],
            ],
            'Copper & Fittings' => [
                ['Copper Tube 1/4"', 'Mueller', MaterialUnit::Meter, 'Soft-drawn, liquid line'],
                ['Copper Tube 3/8"', 'Mueller', MaterialUnit::Meter, 'Soft-drawn'],
                ['Copper Tube 1/2"', 'Mueller', MaterialUnit::Meter, 'Soft-drawn, suction line'],
                ['Copper Tube 5/8"', 'Mueller', MaterialUnit::Meter, 'Soft-drawn, suction line'],
                ['Copper Tube 3/4"', 'Kembla', MaterialUnit::Meter, 'Hard-drawn'],
                ['Flare Nut 1/4"', null, $piece, 'Brass'],
                ['Flare Nut 1/2"', null, $piece, 'Brass'],
                ['Copper Elbow 5/8"', null, $piece, '90 degrees'],
                ['Brazing Rod 15% Silver', 'Harris', $piece, null],
            ],
            'Insulation' => [
                ['Rubber Foam Insulation 3/8" x 3/4"', 'Armaflex', MaterialUnit::Length, '6 ft per length'],
                ['Rubber Foam Insulation 5/8" x 3/4"', 'Armaflex', MaterialUnit::Length, '6 ft per length'],
                ['Rubber Foam Insulation 1/2" x 3/4"', 'K-Flex', MaterialUnit::Length, '6 ft per length'],
                ['Insulation Tape 2"', 'Armaflex', MaterialUnit::Roll, 'Foam tape'],
                ['Fiberglass Duct Wrap 1"', 'Owens Corning', MaterialUnit::Roll, 'Foil-faced'],
            ],
            'Electrical' => [
                ['THHN Wire 2.0 mm²', 'Phelps Dodge', MaterialUnit::Meter, 'Stranded'],
                ['THHN Wire 3.5 mm²', 'Phelps Dodge', MaterialUnit::Meter, 'Stranded'],
                ['THHN Wire 5.5 mm²', 'Phelps Dodge', MaterialUnit::Meter, 'Stranded'],
                ['THHN Wire 3.5 mm²', 'Columbia', MaterialUnit::Meter, 'Stranded'],
                ['Control Cable 4-Core', null, MaterialUnit::Meter, 'Indoor to outdoor unit'],
                ['Circuit Breaker 2P 20A', 'Schneider Electric', $piece, 'Plug-in type'],
                ['Circuit Breaker 2P 30A', 'Schneider Electric', $piece, 'Plug-in type'],
                ['Safety Switch 30A', 'GE', $piece, 'Weatherproof'],
                ['Flexible Conduit 1/2"', null, MaterialUnit::Meter, null],
                ['PVC Electrical Conduit 1/2"', 'Neltex', MaterialUnit::Length, '3 m per length'],
                ['Electrical Tape', '3M', MaterialUnit::Roll, null],
            ],
            'Drainage & PVC' => [
                ['PVC Pipe 3/4"', 'Neltex', MaterialUnit::Length, '3 m per length'],
                ['PVC Pipe 1"', 'Atlanta', MaterialUnit::Length, '3 m per length'],
                ['PVC Elbow 3/4"', 'Neltex', $piece, '90 degrees'],
                ['Drain Hose 5/8"', null, MaterialUnit::Meter, 'Corrugated'],
                ['PVC Solvent Cement 100cc', 'Neltex', MaterialUnit::Can, null],
            ],
            'Ducting' => [
                ['G.I. Sheet Gauge 24', null, MaterialUnit::Sheet, '4 ft x 8 ft'],
                ['G.I. Sheet Gauge 26', null, MaterialUnit::Sheet, '4 ft x 8 ft'],
                ['Flexible Duct 8"', null, MaterialUnit::Meter, 'Insulated'],
                ['Flexible Duct 10"', null, MaterialUnit::Meter, 'Insulated'],
                ['Canvas Connector', null, MaterialUnit::Meter, 'Vibration isolation'],
                ['Volume Damper 10"', null, $piece, 'Round'],
                ['Duct Sealant Mastic', 'Hardcast', MaterialUnit::Gallon, null],
                ['Aluminum Foil Tape 2"', '3M', MaterialUnit::Roll, null],
                ['Threaded Rod 3/8"', null, MaterialUnit::Length, 'Duct hanger, 10 ft'],
            ],
            'Grilles & Diffusers' => [
                ['Square Ceiling Diffuser 12" x 12"', null, $piece, '4-way, aluminum'],
                ['Linear Slot Diffuser 4 ft', null, $piece, '2-slot'],
                ['Return Air Grille 24" x 12"', null, $piece, 'With filter frame'],
                ['Exhaust Grille 10" x 10"', null, $piece, null],
            ],
            'Fans & Ventilation' => [
                ['Ceiling Exhaust Fan 10"', 'Panasonic', $unit, null],
                ['Wall Exhaust Fan 12"', 'Hanabishi', $unit, null],
                ['Inline Duct Fan 8"', 'KDK', $unit, null],
                ['Centrifugal Fan 2 HP', null, $unit, 'Kitchen exhaust'],
                ['Fresh Air Louver 24" x 24"', null, $piece, 'With bird screen'],
            ],
            'Mounting & Hardware' => [
                ['Outdoor Unit Wall Bracket 1.0-2.0 HP', null, MaterialUnit::Set, null],
                ['Outdoor Unit Wall Bracket 2.5-3.0 HP', null, MaterialUnit::Set, null],
                ['Anti-Vibration Rubber Pad', null, MaterialUnit::Set, 'Set of 4'],
                ['Expansion Anchor Bolt 3/8"', null, $piece, null],
                ['Plastic Anchor with Screw', null, MaterialUnit::Box, '100 per box'],
                ['Tek Screw 1"', null, MaterialUnit::Box, '100 per box'],
                ['Blind Rivet 1/8"', null, MaterialUnit::Box, '1,000 per box'],
                ['Cable Tie 8"', null, MaterialUnit::Pack, '100 per pack'],
            ],
            'Spare Parts' => [
                ['Run Capacitor 35+5 µF', null, $piece, 'Dual, for outdoor unit'],
                ['Indoor Fan Motor, Split-Type', null, $piece, null],
                ['Rotary Compressor 1.5 HP', 'GMCC', $unit, 'R32'],
                ['PCB Control Board, Split-Type', null, $piece, 'Universal'],
                ['Universal Aircon Remote', null, $piece, null],
                ['Filter Drier 1/4"', 'Danfoss', $piece, null],
                ['Thermostat, Digital', 'Honeywell', $piece, 'Programmable'],
            ],
            'Cleaning Supplies' => [
                ['Coil Cleaner, Alkaline', 'Nu-Calgon', MaterialUnit::Gallon, 'For outdoor coils'],
                ['Evaporator Coil Cleaner Spray', 'Nu-Calgon', MaterialUnit::Can, 'No-rinse'],
                ['Aircon Cleaning Bag', null, $piece, 'Wall-mounted units'],
                ['Washable Air Filter', null, $piece, 'Cut to size'],
            ],
            'Consumables' => [
                ['Duct Tape 2"', null, MaterialUnit::Roll, null],
                ['Teflon Tape', null, MaterialUnit::Roll, null],
                ['Silicone Sealant, Clear', 'Bostik', MaterialUnit::Tube, null],
                ['Cotton Rags', null, MaterialUnit::Pack, null],
            ],
        ];
    }

    private static function key(string $name, ?string $brand, string $unit): string
    {
        return mb_strtolower($name).'|'.mb_strtolower((string) $brand).'|'.$unit;
    }
}
