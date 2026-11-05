<?php

namespace App\Services;

use App\Models\DetailForm;
use App\Models\DetailFormField;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The fixed detail forms project types ask for, and the only place that writes
 * them. Written from code - see DetailFormSeeder - and read-only on screen.
 *
 * A field dropped from a definition is archived rather than deleted, so a
 * project saved with it still reads as it was.
 */
class DetailFormCatalog
{
    /**
     * Every form, with the types that use it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        return DetailForm::query()
            ->withCount(['activeFields as field_count', 'projectTypes as type_count'])
            ->with('projectTypes:type_id,type_name,form_id')
            ->orderBy('name')
            ->get()
            ->map(fn (DetailForm $form): array => [
                'form_id' => $form->form_id,
                'name' => $form->name,
                'field_count' => $form->field_count,
                'type_count' => $form->type_count,
                'type_names' => $form->projectTypes->pluck('type_name')->sort()->values()->all(),
            ])
            ->values();
    }

    /**
     * One form and its active fields, in order.
     *
     * @return array<string, mixed>
     */
    public function forDisplay(DetailForm $form): array
    {
        return [
            'form_id' => $form->form_id,
            'name' => $form->name,
            'fields' => $form->activeFields()->get()
                ->map(fn (DetailFormField $field): array => $field->toBuilderArray())
                ->values()
                ->all(),
        ];
    }

    /**
     * Make a form read exactly as defined: matched on name, its fields on key.
     *
     * @param  array<int, array{field_key: string, label: string, input_type: string, scope: string, options: array<int, string>, is_required: bool}>  $fields
     */
    public function define(string $name, array $fields): DetailForm
    {
        return DB::transaction(function () use ($name, $fields): DetailForm {
            $form = DetailForm::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? DetailForm::create(['name' => $name]);

            foreach (array_values($fields) as $position => $field) {
                DetailFormField::query()->updateOrCreate(
                    ['form_id' => $form->form_id, 'field_key' => $field['field_key']],
                    [
                        'label' => $field['label'],
                        'input_type' => $field['input_type'],
                        'scope' => $field['scope'],
                        'options' => $field['options'] ?: null,
                        'is_required' => $field['is_required'],
                        'sort_order' => $position,
                        'archived_at' => null,
                    ]
                );
            }

            $form->fields()
                ->whereNull('archived_at')
                ->whereNotIn('field_key', array_column($fields, 'field_key'))
                ->update(['archived_at' => now()]);

            return $form->refresh();
        });
    }
}
