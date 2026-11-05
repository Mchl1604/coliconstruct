<?php

namespace App\Support;

/**
 * The company's paper Service Report, as the fixed forms its air-conditioning
 * project types ask for. Nobody edits these on screen: the paper is the design.
 *
 * Field keys are stated rather than made from labels, because the printed
 * sheet places each value by its key - see service-report-sheet.
 */
final class ServiceReportForms
{
    public const CHECK_UP_TYPE = 'Equipment Check-up';

    public const REPAIR_TYPE = 'Equipment Repair';

    public const CLEANING_TYPE = 'Aircon Cleaning';

    public const INSTALLATION_TYPE = 'Aircon Installation';

    /**
     * Which report section each project type fills.
     *
     * @var array<string, string>
     */
    public const SECTION_BY_TYPE = [
        self::CHECK_UP_TYPE => 'check_up',
        self::REPAIR_TYPE => 'repair',
        self::CLEANING_TYPE => 'cleaning',
        self::INSTALLATION_TYPE => 'installation',
    ];

    /** @var array<int, string> */
    public const WORK = ['Installation', 'Relocation'];

    /** @var array<int, string> */
    public const EQUIPMENT_TYPES = ['Window type', 'Split type', 'VRF', 'Others'];

    /** @var array<int, string> */
    public const BRANDS = ['LG', 'Carrier', 'Koppel', 'Gree', 'Mitsubishi', 'Panasonic', 'Samsung', 'Aux', 'Daikin', 'Kolin', 'York', 'Others'];

    /** @var array<int, string> */
    public const SYSTEM_TYPES = ['VRF', 'Split', 'CHW'];

    /** @var array<int, string> */
    public const MOUNTING_TYPES = ['Wall mounted', 'Floor standing', 'Ducted type', 'Ceiling suspended', 'Cassette type', 'Window type'];

    /** @var array<int, string> */
    public const COPPER_PIPE_SIZES = ['1/4', '5/8', '1-1/8', '3/8', '3/4', '1-3/8', '1/2', '7/8'];

    /** @var array<int, string> */
    public const PIPE_INSULATION_SIZES = ['1/4', '5/8', '1/2', '3/8', '3/4', '1"', '7/8'];

    /** @var array<int, string> */
    public const COMM_LINE_WIRE_SIZES = ['#18', '#12', '#16', '#14'];

    /** @var array<int, string> */
    public const SUPPLY_LOADS = ['#8 (8.0)', '#14 (2.0)', '#10 (5.5)', '#6 (14.0)', '#12 (3.5)', '#4 (22.0)'];

    /** @var array<int, string> */
    public const GROUNDS = ['#16 (1.6)', '#14 (2.0)', '#12 (3.5)'];

    /** @var array<int, string> */
    public const BREAKERS = ['20 A', '30 A', '50 A'];

    /**
     * The paper's indoor unit checklist, in the order it prints across.
     *
     * @var array<string, string>
     */
    public const INDOOR_CHECKLIST = [
        'indoor_filters' => 'Filters',
        'indoor_drain_line' => 'Drain line',
        'indoor_cooling_coil' => 'Cooling coil',
        'indoor_swing' => 'Swing',
        'indoor_panel' => 'Panel',
        'indoor_pcb_board' => 'PCB board',
        'indoor_fan_blade' => 'Fan blade',
        'indoor_electrical_connection' => "Elec'l connection",
    ];

    /**
     * The paper's outdoor unit checklist, in the order it prints across.
     *
     * @var array<string, string>
     */
    public const OUTDOOR_CHECKLIST = [
        'outdoor_panels' => 'Panels',
        'outdoor_pipe_flaring' => 'Pipe flaring',
        'outdoor_condenser_fins' => 'Condenser fins',
        'outdoor_breaker' => 'Breaker',
        'outdoor_compressor' => 'Compressor',
        'outdoor_nema' => '(NEMA)',
        'outdoor_fan_blade' => 'Fan blade',
    ];

    /**
     * The types whose paper section carries the checklists.
     *
     * @var array<int, string>
     */
    public const CHECKLIST_TYPES = [self::CLEANING_TYPE, self::INSTALLATION_TYPE];

    /**
     * @return array<string, string>
     */
    public static function checklistItems(): array
    {
        return [...self::INDOOR_CHECKLIST, ...self::OUTDOOR_CHECKLIST];
    }

    /**
     * Text only known once the work has started: hidden when a project is
     * created, and filled in later from Project Details.
     *
     * @var array<int, string>
     */
    public const FILLED_DURING_WORK = ['findings', 'remarks', 'serial_no', 'indoor_serial_no', 'outdoor_serial_no', 'equipment_tagging'];

    public static function isFilledDuringWork(string $typeName, string $fieldKey): bool
    {
        return isset(self::SECTION_BY_TYPE[$typeName]) && in_array($fieldKey, self::FILLED_DURING_WORK, true);
    }

    /**
     * The form behind each type, by form name.
     *
     * @return array<string, array{types: array<int, string>, fields: array<int, array{field_key: string, label: string, input_type: string, scope: string, options: array<int, string>, is_required: bool}>}>
     */
    public static function definitions(): array
    {
        $equipment = fn (string $lastKey, string $lastLabel): array => [
            self::field('equipment_type', 'Type of equipment', DetailFieldType::Dropdown, true, self::EQUIPMENT_TYPES),
            self::field('brand', 'Brand', DetailFieldType::Dropdown, true, self::BRANDS),
            self::field('area_served', 'Area serve', DetailFieldType::Text),
            self::field('model', 'Model', DetailFieldType::Text),
            self::field('serial_no', 'Serial no.', DetailFieldType::Text),
            self::field('trouble_description', 'Trouble description', DetailFieldType::LongText, true),
            self::field($lastKey, $lastLabel, DetailFieldType::LongText),
        ];

        $aircon = [
            self::field('area_served', 'Area serve', DetailFieldType::Text),
            self::field('equipment_tagging', 'Equip. tagging', DetailFieldType::Text),
            self::field('brand', 'Brand', DetailFieldType::Dropdown, true, self::BRANDS),
            self::field('system_type', 'Type of aircon', DetailFieldType::Dropdown, true, self::SYSTEM_TYPES),
            self::field('mounting_type', 'Mounting', DetailFieldType::Dropdown, false, self::MOUNTING_TYPES),
        ];

        return [
            'Equipment Check-up' => [
                'types' => [self::CHECK_UP_TYPE],
                'fields' => $equipment('findings', 'Findings'),
            ],
            'Equipment Repair' => [
                'types' => [self::REPAIR_TYPE],
                'fields' => $equipment('remarks', 'Remarks'),
            ],
            'Cleaning' => [
                'types' => [self::CLEANING_TYPE],
                'fields' => [
                    ...$aircon,
                    self::field('remarks', 'Remarks', DetailFieldType::LongText),
                ],
            ],
            'Installation' => [
                'types' => [self::INSTALLATION_TYPE],
                'fields' => [
                    self::field('work', 'Work', DetailFieldType::Dropdown, true, self::WORK, DetailFieldScope::Project),
                    ...$aircon,
                    self::field('model', 'Model', DetailFieldType::Text),
                    self::field('indoor_serial_no', 'Indoor serial no.', DetailFieldType::Text),
                    self::field('outdoor_serial_no', 'Outdoor serial no.', DetailFieldType::Text),
                    self::field('copper_pipe_sizes', 'Copper pipe sizes', DetailFieldType::Checkboxes, false, self::COPPER_PIPE_SIZES),
                    self::field('pipe_insulation_size', 'Pipe insulation size', DetailFieldType::Checkboxes, false, self::PIPE_INSULATION_SIZES),
                    self::field('comm_line_wire_size', 'Comm. line wire size', DetailFieldType::Checkboxes, false, self::COMM_LINE_WIRE_SIZES),
                    self::field('supply_load', 'Supply load', DetailFieldType::Checkboxes, false, self::SUPPLY_LOADS),
                    self::field('ground', 'Ground', DetailFieldType::Checkboxes, false, self::GROUNDS),
                    self::field('breaker', 'Breaker', DetailFieldType::Checkboxes, false, self::BREAKERS),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $options
     * @return array{field_key: string, label: string, input_type: string, scope: string, options: array<int, string>, is_required: bool}
     */
    private static function field(string $key, string $label, DetailFieldType $type, bool $required = false, array $options = [], DetailFieldScope $scope = DetailFieldScope::Unit): array
    {
        return [
            'field_key' => $key,
            'label' => $label,
            'input_type' => $type->value,
            'scope' => $scope->value,
            'options' => $options,
            'is_required' => $required,
        ];
    }
}
