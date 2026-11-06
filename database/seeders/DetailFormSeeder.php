<?php

namespace Database\Seeders;

use App\Models\ProjectType;
use App\Services\DetailFormCatalog;
use App\Support\DetailFieldType;
use App\Support\ServiceReportForms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The company's fixed detail forms: the paper Service Report's for aircon work,
 * Ducting, and General for any other type.
 *
 *     php artisan db:seed --class=DetailFormSeeder --force
 *
 * These forms are not edited on screen, so running this puts them back exactly
 * as defined. Every project type needs a form, so a type still without one
 * afterwards is given General.
 */
class DetailFormSeeder extends Seeder
{
    public const GENERAL = 'General';

    public function run(): void
    {
        DB::transaction(function (): void {
            $catalog = app(DetailFormCatalog::class);
            $forms = [];

            foreach (self::forms() as $name => $definition) {
                $forms[$name] = $catalog->define($name, $definition['fields']);

                foreach ($definition['types'] as $typeName) {
                    ProjectType::query()
                        ->whereRaw('LOWER(type_name) = ?', [mb_strtolower($typeName)])
                        ->update(['form_id' => $forms[$name]->form_id]);
                }
            }

            ProjectType::query()->whereNull('form_id')->update(['form_id' => $forms[self::GENERAL]->form_id]);

            $this->command?->info(sprintf('Detail forms ready: %d forms.', count($forms)));
        });
    }

    /**
     * @return array<string, array{types: array<int, string>, fields: array<int, array<string, mixed>>}>
     */
    public static function forms(): array
    {
        return [
            ...ServiceReportForms::definitions(),
            'Ducting' => [
                'types' => ['Ducting Fabrication', 'Ducting Installation'],
                'fields' => [
                    self::field('scope_of_work', 'Scope of work', DetailFieldType::LongText, true),
                    self::field('area_served', 'Area served', DetailFieldType::Text),
                    self::field('duct_material', 'Duct material', DetailFieldType::Dropdown, false, ServiceReportForms::DUCT_MATERIALS),
                    self::field('duct_size', 'Duct size', DetailFieldType::Text),
                    self::field('length_m', 'Length (m)', DetailFieldType::Number),
                    self::field('insulated', 'Insulated', DetailFieldType::YesNo),
                ],
            ],
            self::GENERAL => [
                'types' => [],
                'fields' => [
                    self::field('scope_of_work', 'Scope of work', DetailFieldType::LongText, true),
                    self::field('area_served', 'Area served', DetailFieldType::Text),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $options
     * @return array{field_key: string, label: string, input_type: string, scope: string, options: array<int, string>, is_required: bool}
     */
    private static function field(string $key, string $label, DetailFieldType $type, bool $required = false, array $options = []): array
    {
        return [
            'field_key' => $key,
            'label' => $label,
            'input_type' => $type->value,
            'scope' => 'project',
            'options' => $options,
            'is_required' => $required,
        ];
    }
}
