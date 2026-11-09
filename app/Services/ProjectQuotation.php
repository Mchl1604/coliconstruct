<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Project;
use App\Models\QuotationItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * A project's quotation breakdown: how its lines are checked, totalled and
 * saved. The total is what the project's quotation column holds.
 *
 * Submitted as quotation_items[n][kind, material_id, description, unit,
 * quantity, unit_price], with quotation_discount_percent and
 * quotation_special_discount beside them, and the printed terms in
 * TERM_FIELDS, from the create wizard and the edit dialog alike. The total is
 * the lines less both discounts. A
 * line may quote more than is in stock: only the Materials step is held to
 * that, by ProjectMaterialPlan.
 */
class ProjectQuotation
{
    /** More lines than any quotation lists, and a ceiling on a tampered form. */
    public const MAX_LINES = 200;

    /**
     * The free text the sheet prints under the totals, by column, with the most
     * each may hold.
     *
     * @var array<string, int>
     */
    public const TERM_FIELDS = [
        'quotation_notes' => 2000,
        'quotation_inclusions' => 2000,
        'quotation_exclusions' => 2000,
        'quotation_payment_terms' => 1000,
        'quotation_prepared_by' => 100,
        'quotation_approved_by' => 100,
    ];

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
            'quotation_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'quotation_special_discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            ...collect(self::TERM_FIELDS)->map(fn (int $max): array => ['nullable', 'string', 'max:'.$max])->all(),
            'quoted_at' => ['nullable', 'date_format:Y-m-d'],
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
            'quotation_discount_percent.numeric' => 'Enter the discount as a number.',
            'quotation_discount_percent.min' => 'The discount must be 0 to 100%.',
            'quotation_discount_percent.max' => 'The discount must be 0 to 100%.',
            'quotation_special_discount.numeric' => 'Enter the special discount as an amount.',
            'quotation_special_discount.min' => 'The special discount cannot be negative.',
            'quotation_notes.max' => 'Notes are too long.',
            'quotation_inclusions.max' => 'Inclusions are too long.',
            'quotation_exclusions.max' => 'Exclusions are too long.',
            'quotation_payment_terms.max' => 'Terms of payment are too long.',
            'quotation_prepared_by.max' => 'The Prepared By name is too long.',
            'quotation_approved_by.max' => 'The Approved By name is too long.',
            'quoted_at.date_format' => 'Choose a valid quotation date.',
        ];
    }

    /**
     * What is wrong with the submitted lines beyond their shape, keyed the way
     * the validator reports it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    public function errors(array $rows, mixed $discountPercent = 0, mixed $specialDiscount = 0): array
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

        $breakdown = $this->breakdown($rows, $discountPercent, $specialDiscount);

        if ($breakdown['special_discount'] > $breakdown['subtotal'] - $breakdown['discount_amount']) {
            $errors['quotation_special_discount'] = 'The special discount is more than the amount.';
        }

        if ($breakdown['total'] > (float) Project::MAX_QUOTATION) {
            $errors['quotation_items'] = Project::MAX_QUOTATION_MESSAGE;
        }

        return $errors;
    }

    /**
     * The amount owed: the lines less both discounts.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function total(array $rows, mixed $discountPercent = 0, mixed $specialDiscount = 0): float
    {
        return $this->breakdown($rows, $discountPercent, $specialDiscount)['total'];
    }

    /**
     * The figures under the lines, as the sheet prints them.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{subtotal: float, discount_percent: float, discount_amount: float, special_discount: float, total: float}
     */
    public function breakdown(array $rows, mixed $discountPercent = 0, mixed $specialDiscount = 0): array
    {
        $subtotal = round(collect($rows)->sum(fn (array $row): float => round((float) ($row['quantity'] ?? 0) * (float) ($row['unit_price'] ?? 0), 2)), 2);
        $percent = round(min(max((float) $discountPercent, 0), 100), 2);
        $special = round(max((float) $specialDiscount, 0), 2);
        $discountAmount = round($subtotal * $percent / 100, 2);

        return [
            'subtotal' => $subtotal,
            'discount_percent' => $percent,
            'discount_amount' => $discountAmount,
            'special_discount' => $special,
            'total' => round(max($subtotal - $discountAmount - $special, 0), 2),
        ];
    }

    /**
     * The breakdown of what the project has saved.
     *
     * @return array{subtotal: float, discount_percent: float, discount_amount: float, special_discount: float, total: float}
     */
    public function savedBreakdown(Project $project): array
    {
        return $this->breakdown(
            $project->quotationItems
                ->map(fn (QuotationItem $item): array => ['quantity' => $item->quantity, 'unit_price' => $item->unit_price])
                ->all(),
            $project->quotation_discount_percent,
            $project->quotation_special_discount
        );
    }

    /**
     * The printed terms out of what was submitted: trimmed, blank as null.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    public function terms(array $input): array
    {
        return collect(self::TERM_FIELDS)
            ->map(fn (int $max, string $field): ?string => trim((string) ($input[$field] ?? '')) ?: null)
            ->all();
    }

    /**
     * Replace the project's lines, discounts and terms with what was
     * submitted, dated as chosen - today when no date was given.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $terms  the submitted TERM_FIELDS and quoted_at
     */
    public function save(Project $project, array $rows, mixed $discountPercent = 0, mixed $specialDiscount = 0, array $terms = []): void
    {
        $materials = $this->materialsOf($rows);
        $breakdown = $this->breakdown($rows, $discountPercent, $specialDiscount);

        $project->forceFill([
            'quotation_discount_percent' => $breakdown['discount_percent'],
            'quotation_special_discount' => $breakdown['special_discount'],
            ...$this->terms($terms),
            'quoted_at' => filled($terms['quoted_at'] ?? null) ? Carbon::createFromFormat('Y-m-d', $terms['quoted_at'])->startOfDay() : now(),
        ])->save();

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
