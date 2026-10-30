<?php

namespace App\Services;

use App\Models\DetailForm;
use App\Models\DetailFormField;
use App\Models\ProjectDetail;
use App\Models\ProjectType;
use App\Support\DetailFieldScope;
use App\Support\DetailFieldType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The detail forms project types ask for, and the only place that writes them.
 *
 * Two rules keep old projects readable however a form is edited: a field's
 * key never changes once it exists, and a field a project was already saved
 * with is archived rather than deleted.
 */
class DetailFormCatalog
{
    /**
     * Every form, with what decides whether it may be removed.
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
     * One form as the builder edits it: its active fields, in order.
     *
     * @return array<string, mixed>
     */
    public function forBuilder(DetailForm $form): array
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
     * Create a form, or replace an existing one's name and fields.
     *
     * Fields are matched on field_id. One left out is archived if any project
     * was saved with this form, and deleted if none was.
     *
     * @param  array{name: string, fields: array<int, array{field_id?: int|null, label: string, input_type: string, scope?: string|null, options?: array<int, string>|null, is_required?: bool}>}  $data
     *
     * @throws RuntimeException
     */
    public function save(?DetailForm $form, array $data): DetailForm
    {
        $name = $this->clean($data['name']);

        $this->assertNameIsFree($name, $form);

        return DB::transaction(function () use ($form, $data, $name): DetailForm {
            $form ??= new DetailForm;
            $form->fill(['name' => $name])->save();

            $existing = $form->activeFields()->get()->keyBy('field_id');
            /** @var array<int, int> $kept */
            $kept = [];

            foreach (array_values($data['fields']) as $position => $input) {
                $type = DetailFieldType::from($input['input_type']);
                $attributes = [
                    'label' => $this->clean($input['label']),
                    'input_type' => $type,
                    'scope' => DetailFieldScope::tryFrom((string) ($input['scope'] ?? '')) ?? DetailFieldScope::Unit,
                    'options' => $type->hasOptions() ? $this->cleanOptions($input['options'] ?? []) : null,
                    'is_required' => (bool) ($input['is_required'] ?? false),
                    'sort_order' => $position,
                ];

                $field = isset($input['field_id']) ? $existing->get((int) $input['field_id']) : null;

                if ($field) {
                    $field->update($attributes);
                    $kept[] = $field->field_id;

                    continue;
                }

                $created = $form->fields()->create([
                    ...$attributes,
                    'field_key' => $this->uniqueKey($form, $attributes['label']),
                ]);

                $kept[] = $created->field_id;
            }

            $dropped = array_values(array_diff($existing->modelKeys(), $kept));

            if ($dropped !== []) {
                $this->isUsed($form)
                    ? DetailFormField::query()->whereKey($dropped)->update(['archived_at' => now()])
                    : DetailFormField::query()->whereKey($dropped)->delete();
            }

            return $form->refresh();
        });
    }

    /**
     * @throws RuntimeException
     */
    public function remove(DetailForm $form): void
    {
        $typeCount = $form->projectTypes()->count();

        if ($typeCount > 0) {
            throw new RuntimeException(sprintf(
                "'%s' is used by %d project %s. Change those first.",
                $form->name,
                $typeCount,
                $typeCount === 1 ? 'type' : 'types'
            ));
        }

        // Projects saved with it keep their rows: form_id is nulled and each
        // row carries its own labels.
        $form->delete();
    }

    /**
     * Point a project type at a form.
     */
    public function assign(ProjectType $type, DetailForm $form): ProjectType
    {
        $type->update(['form_id' => $form->form_id]);

        return $type;
    }

    /**
     * Whether any project has been saved with this form.
     */
    public function isUsed(DetailForm $form): bool
    {
        return ProjectDetail::query()->where('form_id', $form->form_id)->exists();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array<int, string>
     */
    private function cleanOptions(array $options): array
    {
        return collect($options)
            ->filter(fn ($option): bool => is_string($option))
            ->map(fn (string $option): string => $this->clean($option))
            ->filter()
            ->unique(fn (string $option): string => mb_strtolower($option))
            ->values()
            ->all();
    }

    /**
     * A key made from the label, unique within the form - archived fields
     * included, so a new field never picks up an old one's saved values.
     */
    private function uniqueKey(DetailForm $form, string $label): string
    {
        $base = Str::limit(Str::slug($label, '_'), 50, '') ?: 'field';
        $taken = $form->fields()->pluck('field_key')->all();
        $key = $base;
        $suffix = 2;

        while (in_array($key, $taken, true)) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }

    /**
     * @throws RuntimeException
     */
    private function assertNameIsFree(string $name, ?DetailForm $ignoring): void
    {
        $taken = DetailForm::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoring?->exists, fn ($query) => $query->whereKeyNot($ignoring->form_id))
            ->exists();

        if ($taken) {
            throw new RuntimeException(sprintf("A form named '%s' already exists.", $name));
        }
    }
}
