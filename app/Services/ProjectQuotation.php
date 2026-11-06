<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Project;
use App\Models\QuotationItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * A project's quotation breakdown: how its lines are checked, totalled and
 * saved. The total is what the project's quotation column holds.
 *
 * Submitted as quotation_items[n][kind, material_id, description, unit,
 * quantity, unit_price], from the create wizard and the edit dialog alike. A
 * line may quote more than is in stock: only the Materials step is held to
 * that, by ProjectMaterialPlan.
 */
class ProjectQuotation
{
    /** More lines than any quotation lists, and a ceiling on a tampered form. */
    public const MAX_LINES = 200;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'quotation_items' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'quotation_items.*.kind' => ['required', 'string', Rule::in(array_keys(QuotationItem::KINDS))],
            'quotation_items.*.material_id' => ['nullable', 'integer'],
            'quotation_items.*.description' => ['nullable', 'string', 'max:255'],
            'quotation_items.*.unit' => ['nullable', 'string', 'max:20'],
            'quotation_items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'quotation_items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quotation_items.required' => 'Add at least one quotation line.',
            'quotation_items.min' => 'Add at least one quotation line.',
            'quotation_items.max' => 'At most '.self::MAX_LINES.' quotation lines.',
            'quotation_items.*.quantity.required' => 'Enter a quantity for every quotation line.',
            'quotation_items.*.quantity.numeric' => 'Enter a quantity for every quotation line.',
            'quotation_items.*.quantity.gt' => 'Quotation quantities must be more than zero.',
            'quotation_items.*.unit_price.required' => 'Enter a price for every quotation line.',
            'quotation_items.*.unit_price.numeric' => 'Enter a price for every quotation line.',
            'quotation_items.*.unit_price.min' => 'Quotation prices cannot be negative.',
        ];
    }

    /**
     * What is wrong with the submitted lines beyond their shape, keyed the way
     * the validator reports it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    public function errors(array $rows): array
    {
        $errors = [];
        $materials = $this->materialsOf($rows);
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            if (($row['kind'] ?? null) !== QuotationItem::KIND_MATERIAL) {
                if (trim((string) ($row['description'] ?? '')) === '') {
                    $errors['quotation_items.'.$index.'.description'] = 'Enter a description for every quotation line.';
                }

                continue;
            }

            $material = $materials->get((int) ($row['material_id'] ?? 0));

            if ($material === null) {
                $errors['quotation_items.'.$index.'.material_id'] = 'That material is no longer listed.';

                continue;
            }

            if (isset($seen[$material->material_id])) {
                $errors['quotation_items.'.$index.'.material_id'] = sprintf('%s is quoted twice.', $material->displayName());

                continue;
            }

            $seen[$material->material_id] = true;

            $problem = $material->unit->quantityProblem($row['quantity'] ?? null);

            if ($problem !== null) {
                $errors['quotation_items.'.$index.'.quantity'] = sprintf('%s: %s', $material->displayName(), $problem);
            }
        }

        if ($this->total($rows) > (float) Project::MAX_QUOTATION) {
            $errors['quotation_items'] = Project::MAX_QUOTATION_MESSAGE;
        }

        return $errors;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function total(array $rows): float
    {
        return round(collect($rows)->sum(fn (array $row): float => round((float) ($row['quantity'] ?? 0) * (float) ($row['unit_price'] ?? 0), 2)), 2);
    }

    /**
     * Replace the project's lines with what was submitted.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function save(Project $project, array $rows): void
    {
        $materials = $this->materialsOf($rows);

        $project->quotationItems()->delete();

        foreach (array_values($rows) as $position => $row) {
            $material = $row['kind'] === QuotationItem::KIND_MATERIAL
                ? $materials->get((int) $row['material_id'])
                : null;

            $project->quotationItems()->create([
                'kind' => $row['kind'],
                'material_id' => $material?->material_id,
                'description' => $material?->displayName() ?? trim((string) $row['description']),
                // Labor is a cost, not a count of anything.
                'unit' => $row['kind'] === QuotationItem::KIND_LABOR
                    ? null
                    : ($material?->unit->value ?? (trim((string) ($row['unit'] ?? '')) ?: null)),
                // Labor is one amount, kept as a quantity of one.
                'quantity' => $row['kind'] === QuotationItem::KIND_LABOR ? 1 : round((float) $row['quantity'], 2),
                'unit_price' => $row['kind'] === QuotationItem::KIND_LABOR
                    ? round((float) $row['quantity'] * (float) $row['unit_price'], 2)
                    : round((float) $row['unit_price'], 2),
                'sort_order' => $position,
            ]);
        }
    }

    /**
     * Lines as the editor draws them, from what was submitted after a refusal
     * or from what is saved. Material lines carry today's stock figures.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function editorRows(array $rows): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        $materials = $this->materialsOf($rows);
        $figures = app(StockAvailability::class)->forMaterials($materials->pluck('material_id')->all());

        return collect($rows)
            ->map(function (array $row) use ($materials, $figures): array {
                $material = $materials->get((int) ($row['material_id'] ?? 0));

                return [
                    'kind' => (string) ($row['kind'] ?? QuotationItem::KIND_OTHER),
                    'description' => (string) ($row['description'] ?? ''),
                    'unit' => (string) ($row['unit'] ?? ''),
                    'quantity' => (string) ($row['quantity'] ?? ''),
                    'unit_price' => (string) ($row['unit_price'] ?? ''),
                    'material' => $material
                        ? $material->toOption() + ($figures[$material->material_id] ?? [])
                        : null,
                ];
            })
            ->all();
    }

    /**
     * The saved lines in the shape editorRows() reads.
     *
     * @return array<int, array<string, mixed>>
     */
    public function savedRows(Project $project): array
    {
        return $this->editorRows(
            $project->quotationItems
                ->map(fn (QuotationItem $item): array => [
                    'kind' => $item->kind,
                    'material_id' => $item->material_id,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
                    'unit_price' => (string) $item->unit_price,
                ])
                ->all()
        );
    }

    /**
     * How much of each material the quotation lists, by material_id. Quantities
     * only, so it is safe to show to the crew.
     *
     * @return array<int, array{label: string, unit: string, quantity: float}>
     */
    public function quotedMaterials(Project $project): array
    {
        return $project->quotationItems()
            ->whereNotNull('material_id')
            ->get()
            ->mapWithKeys(fn (QuotationItem $item): array => [
                (int) $item->material_id => [
                    'label' => $item->description,
                    'unit' => (string) $item->unit,
                    'quantity' => (float) $item->quantity,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, Material>
     */
    private function materialsOf(array $rows): Collection
    {
        return Material::query()
            ->with('type')
            ->whereIn('material_id', collect($rows)->pluck('material_id')->filter()->map(fn ($id): int => (int) $id)->all())
            ->get()
            ->keyBy('material_id');
    }
}
