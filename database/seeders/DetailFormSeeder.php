<?php

namespace Database\Seeders;

use App\Models\DetailForm;
use App\Models\ProjectType;
use App\Services\DetailFormCatalog;
use App\Support\DetailFieldScope;
use App\Support\DetailFieldType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The detail forms the company starts with, taken from its paper service
 * report: Installation, Repair and Cleaning for aircon work, Ducting,
 * Ventilation, and General for any other type.
 *
 *     php artisan db:seed --class=DetailFormSeeder --force
 *
 * Every project type needs a form, so a type still without one afterwards is
 * given General. Add-only: a form already on the list - same name, whatever
 * the casing - is left exactly as the Super Admin edited it, and a type that
 * already has a form keeps it.
 */
class DetailFormSeeder extends Seeder
{
    public const GENERAL = 'General';

    public const AIRCON_TYPES = [
        'Window',
        'Wall mounted',
        'Floor standing',
        'Ceiling suspended',
        'Cassette',
        'Ducted',
        'VRF',
        'CHW',
        'Others',
    ];

    public const BRANDS = [
        'LG',
        'Carrier',
        'Koppel',
        'Gree',
        'Mitsubishi',
        'Panasonic',
        'Samsung',
        'Aux',
        'Daikin',
        'Kolin',
        'York',
        'Others',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $catalog = app(DetailFormCatalog::class);
            $added = 0;
            $forms = [];

            foreach (self::forms() as $name => $definition) {
                $form = DetailForm::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

                if (! $form) {
                    $form = $catalog->save(null, ['name' => $name, 'fields' => $definition['fields']]);
                    $added++;
                }

                $forms[$name] = $form;

                foreach ($definition['types'] as $typeName) {
                    ProjectType::query()
                        ->whereRaw('LOWER(type_name) = ?', [mb_strtolower($typeName)])
                        ->whereNull('form_id')
                        ->update(['form_id' => $form->form_id]);
                }
            }

            ProjectType::query()->whereNull('form_id')->update(['form_id' => $forms[self::GENERAL]->form_id]);

            $this->command?->info(sprintf('Detail forms ready: %d forms added.', $added));
        });
    }

    /**
     * @return array<string, array{types: array<int, string>, fields: array<int, array<string, mixed>>}>
     */
    public static function forms(): array
    {
        $unit = [
            self::field('Aircon type', DetailFieldType::Dropdown, DetailFieldScope::Unit, true, self::AIRCON_TYPES),
            self::field('Brand', DetailFieldType::Dropdown, DetailFieldScope::Unit, true, self::BRANDS),
            self::field('Capacity (HP)', DetailFieldType::Number, DetailFieldScope::Unit),
            self::field('Area served', DetailFieldType::Text, DetailFieldScope::Unit),
        ];

        return [
            'Installation' => [
                'types' => ['Aircon Installation'],
                'fields' => [
                    self::field('Work', DetailFieldType::Dropdown, DetailFieldScope::Project, true, ['Installation', 'Relocation']),
                    ...$unit,
                    self::field('Quantity', DetailFieldType::Number, DetailFieldScope::Unit),
                    self::field('Model', DetailFieldType::Text, DetailFieldScope::Unit),
                    self::field('Equipment tag', DetailFieldType::Text, DetailFieldScope::Unit),
                    self::field('Indoor serial no.', DetailFieldType::Text, DetailFieldScope::Unit),
                    self::field('Outdoor serial no.', DetailFieldType::Text, DetailFieldScope::Unit),
                ],
            ],
            'Repair' => [
                'types' => ['Aircon Repair'],
                'fields' => [
                    self::field('Trouble description', DetailFieldType::LongText, DetailFieldScope::Project, true),
                    self::field('Warranty', DetailFieldType::Dropdown, DetailFieldScope::Project, false, ['None', 'Under warranty', 'Free check-up']),
                    self::field('PO no.', DetailFieldType::Text, DetailFieldScope::Project),
                    ...$unit,
                    self::field('Model', DetailFieldType::Text, DetailFieldScope::Unit),
                    self::field('Serial no.', DetailFieldType::Text, DetailFieldScope::Unit),
                ],
            ],
            'Cleaning' => [
                'types' => ['Aircon Cleaning'],
                'fields' => [
                    ...$unit,
                    self::field('Quantity', DetailFieldType::Number, DetailFieldScope::Unit),
                    self::field('Equipment tag', DetailFieldType::Text, DetailFieldScope::Unit),
                ],
            ],
            'Ducting' => [
                'types' => ['Ducting Fabrication', 'Ducting Installation'],
                'fields' => [
                    self::field('Scope of work', DetailFieldType::LongText, DetailFieldScope::Project, true),
                    self::field('Area served', DetailFieldType::Text, DetailFieldScope::Project),
                    self::field('Duct material', DetailFieldType::Dropdown, DetailFieldScope::Project, false, ['GI sheet', 'Aluminum', 'Pre-insulated', 'Flexible', 'Others']),
                    self::field('Duct size', DetailFieldType::Text, DetailFieldScope::Project),
                    self::field('Length (m)', DetailFieldType::Number, DetailFieldScope::Project),
                    self::field('Insulated', DetailFieldType::YesNo, DetailFieldScope::Project),
                ],
            ],
            'Ventilation' => [
                'types' => ['Heating Ventillation', 'Heating Ventilation'],
                'fields' => [
                    self::field('Scope of work', DetailFieldType::LongText, DetailFieldScope::Project, true),
                    self::field('Area served', DetailFieldType::Text, DetailFieldScope::Project),
                    self::field('Equipment', DetailFieldType::Checkboxes, DetailFieldScope::Project, false, ['Exhaust fan', 'Fresh air unit', 'Heater', 'Others']),
                ],
            ],
            self::GENERAL => [
                'types' => [],
                'fields' => [
                    self::field('Scope of work', DetailFieldType::LongText, DetailFieldScope::Project, true),
                    self::field('Area served', DetailFieldType::Text, DetailFieldScope::Project),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $options
     * @return array{label: string, input_type: string, scope: string, options: array<int, string>, is_required: bool}
     */
    private static function field(string $label, DetailFieldType $type, DetailFieldScope $scope, bool $required = false, array $options = []): array
    {
        return [
            'label' => $label,
            'input_type' => $type->value,
            'scope' => $scope->value,
            'options' => $options,
            'is_required' => $required,
        ];
    }
}
