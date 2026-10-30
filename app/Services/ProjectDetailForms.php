<?php

namespace App\Services;

use App\Models\DetailForm;
use App\Models\DetailFormField;
use App\Models\Project;
use App\Models\ProjectDetail;
use App\Models\ProjectType;
use App\Support\DetailFieldScope;
use App\Support\DetailFieldType;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * A project's description: the detail form each of its types asks for, how it
 * is checked and how it is saved.
 *
 * Submitted as details[type_id][project][field_key] for the fields filled once
 * and details[type_id][units][row][field_key] for each unit - from the create
 * wizard and from the Edit Details dialog on Project Details alike. A unit row
 * may carry _detail_id, the saved row it edits, so values of fields archived
 * since are kept rather than dropped.
 */
class ProjectDetailForms
{
    /** The most units one type may list on a project. */
    public const MAX_UNITS = 50;

    /** The hidden key an edited unit names its saved row by. */
    public const DETAIL_ID_KEY = '_detail_id';

    /**
     * The given types that ask for a form, with that form's active fields.
     *
     * @param  Collection<int, ProjectType>  $types
     * @return Collection<int, ProjectType>
     */
    public function withForms(Collection $types): Collection
    {
        $types->loadMissing('detailForm.activeFields');

        return $types->filter(fn (ProjectType $type): bool => $type->detailForm !== null)->values();
    }

    /**
     * @param  Collection<int, ProjectType>  $types
     * @return array<string, mixed>
     */
    public function rules(Collection $types): array
    {
        $rules = [];

        foreach ($this->withForms($types) as $type) {
            $base = 'details.'.$type->type_id;
            $form = $type->detailForm;

            // Each field says for itself whether it is required.
            $rules[$base] = ['nullable', 'array'];
            $rules[$base.'.project'] = ['nullable', 'array'];

            foreach ($form->projectFields() as $field) {
                $rules = [...$rules, ...$this->fieldRules($base.'.project.'.$field->field_key, $field)];
            }

            if ($form->hasUnits()) {
                $rules[$base.'.units'] = ['required', 'array', 'min:1', 'max:'.self::MAX_UNITS];
                $rules[$base.'.units.*'] = ['array'];
                $rules[$base.'.units.*.'.self::DETAIL_ID_KEY] = ['nullable', 'integer'];

                foreach ($form->unitFields() as $field) {
                    $rules = [...$rules, ...$this->fieldRules($base.'.units.*.'.$field->field_key, $field)];
                }
            }
        }

        return $rules;
    }

    /**
     * @param  Collection<int, ProjectType>  $types
     * @return array<string, string>
     */
    public function messages(Collection $types): array
    {
        $messages = [];

        foreach ($this->withForms($types) as $type) {
            $base = 'details.'.$type->type_id;

            $messages[$base.'.units.required'] = sprintf('Add at least one unit for %s.', $type->type_name);
            $messages[$base.'.units.min'] = sprintf('Add at least one unit for %s.', $type->type_name);
            $messages[$base.'.units.max'] = sprintf('%s takes %d units at most.', $type->type_name, self::MAX_UNITS);
        }

        return $messages;
    }

    /**
     * Field names as people read them: "Aircon Repair: Brand".
     *
     * @param  Collection<int, ProjectType>  $types
     * @return array<string, string>
     */
    public function attributes(Collection $types): array
    {
        $attributes = [];

        foreach ($this->withForms($types) as $type) {
            $base = 'details.'.$type->type_id;

            foreach ($type->detailForm->activeFields as $field) {
                $key = $field->scope === DetailFieldScope::Project
                    ? $base.'.project.'.$field->field_key
                    : $base.'.units.*.'.$field->field_key;
                $label = $type->type_name.': '.$field->label;

                $attributes[$key] = $label;
                $attributes[$key.'.*'] = $label;
            }
        }

        return $attributes;
    }

    /**
     * Replace the project's rows for each given type with the submitted ones.
     *
     * Only the types passed in are touched, so a type whose form was changed
     * keeps whatever was saved for it until it is edited.
     *
     * @param  Collection<int, ProjectType>  $types
     * @param  array<int|string, mixed>  $input  details, keyed by type_id
     */
    public function sync(Project $project, Collection $types, array $input): void
    {
        foreach ($this->withForms($types) as $type) {
            $form = $type->detailForm;
            $typeInput = (array) ($input[$type->type_id] ?? []);
            $saved = ProjectDetail::query()
                ->where('project_id', $project->project_id)
                ->where('type_id', $type->type_id)
                ->get();

            ProjectDetail::query()->whereKey($saved->modelKeys())->delete();

            $previousProject = $saved->first(fn (ProjectDetail $detail): bool => $detail->scope === DetailFieldScope::Project);
            [$values, $labels] = $this->rowFor($form->projectFields(), (array) ($typeInput['project'] ?? []), $previousProject);

            if (! $this->isBlank($values)) {
                $this->store($project, $type, $form, DetailFieldScope::Project, $values, $labels, 0);
            }

            if (! $form->hasUnits()) {
                continue;
            }

            $savedUnits = $saved->filter(fn (ProjectDetail $detail): bool => $detail->scope === DetailFieldScope::Unit)->keyBy('detail_id');
            $rows = array_values(array_filter((array) ($typeInput['units'] ?? []), 'is_array'));

            foreach ($rows as $position => $row) {
                $previous = $savedUnits->get((int) ($row[self::DETAIL_ID_KEY] ?? 0));
                [$values, $labels] = $this->rowFor($form->unitFields(), $row, $previous);

                if (! $this->isBlank($values)) {
                    $this->store($project, $type, $form, DetailFieldScope::Unit, $values, $labels, $position);
                }
            }
        }
    }

    /**
     * Drop the rows of types the project no longer has.
     */
    public function pruneRemovedTypes(Project $project): void
    {
        ProjectDetail::query()
            ->where('project_id', $project->project_id)
            ->whereNotIn('type_id', $project->projectTypes()->pluck('tbl_project_types.type_id'))
            ->delete();
    }

    /**
     * A project's saved details as each page shows them, grouped by type: the
     * once-per-project values, then the units.
     *
     * @return Collection<int, array{type: ProjectType, project: array<int, array{label: string, value: string}>, units: array<int, array<int, array{label: string, value: string}>>}>
     */
    public function summary(Project $project): Collection
    {
        $project->loadMissing(['details', 'projectTypes']);
        $byType = $project->details->groupBy('type_id');

        return $project->projectTypes
            ->filter(fn (ProjectType $type): bool => $byType->has($type->type_id))
            ->map(function (ProjectType $type) use ($byType): array {
                $rows = $byType->get($type->type_id);

                return [
                    'type' => $type,
                    'project' => $rows->first(fn (ProjectDetail $detail): bool => $detail->scope === DetailFieldScope::Project)?->readableValues() ?? [],
                    'units' => $rows->filter(fn (ProjectDetail $detail): bool => $detail->scope === DetailFieldScope::Unit)
                        ->map(fn (ProjectDetail $detail): array => $detail->readableValues())
                        ->values()
                        ->all(),
                ];
            })
            ->values();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array<string, array<int, mixed>>
     */
    private function fieldRules(string $key, DetailFormField $field): array
    {
        $presence = $field->is_required ? 'required' : 'nullable';

        if ($field->input_type === DetailFieldType::Checkboxes) {
            return [
                $key => [$presence, 'array'],
                $key.'.*' => ['string', Rule::in($field->optionList())],
            ];
        }

        return [$key => [$presence, ...$this->valueRules($field)]];
    }

    /**
     * @return array<int, mixed>
     */
    private function valueRules(DetailFormField $field): array
    {
        return match ($field->input_type) {
            DetailFieldType::Text => ['string', 'max:255'],
            DetailFieldType::LongText => ['string', 'max:2000'],
            DetailFieldType::Number => ['numeric', 'min:0', 'max:999999'],
            DetailFieldType::Dropdown => ['string', Rule::in($field->optionList())],
            DetailFieldType::YesNo => ['string', Rule::in(['Yes', 'No'])],
            DetailFieldType::Date => ['date_format:Y-m-d'],
            DetailFieldType::Checkboxes => ['array'],
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $labels
     */
    private function store(Project $project, ProjectType $type, DetailForm $form, DetailFieldScope $scope, array $values, array $labels, int $position): void
    {
        ProjectDetail::create([
            'project_id' => $project->project_id,
            'type_id' => $type->type_id,
            'form_id' => $form->form_id,
            'scope' => $scope,
            'values' => $values,
            'labels' => $labels,
            'sort_order' => $position,
        ]);
    }

    /**
     * The values and labels one row is saved with: the active fields in form
     * order, then any archived ones the row being edited already had.
     *
     * @param  Collection<int, DetailFormField>  $fields
     * @param  array<string, mixed>  $row
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function rowFor(Collection $fields, array $row, ?ProjectDetail $previous): array
    {
        $values = [];
        $labels = [];

        foreach ($fields as $field) {
            $labels[$field->field_key] = $field->label;
            $values[$field->field_key] = $this->normalize($field, $row[$field->field_key] ?? null);
        }

        if ($previous) {
            $previousValues = (array) $previous->values;

            foreach ((array) $previous->labels as $key => $label) {
                if (! array_key_exists($key, $labels)) {
                    $labels[$key] = $label;
                    $values[$key] = $previousValues[$key] ?? null;
                }
            }
        }

        return [$values, $labels];
    }

    private function normalize(DetailFormField $field, mixed $value): mixed
    {
        if ($field->input_type === DetailFieldType::Checkboxes) {
            return array_values(array_filter((array) $value, fn ($item): bool => is_string($item) && $item !== ''));
        }

        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                return false;
            }
        }

        return true;
    }
}
