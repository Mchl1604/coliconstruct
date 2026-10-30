{{--
    One project type's detail form: its project fields once, then its units
    when the form has per-unit fields. The create wizard draws one per type in
    place of a free-text description, and so does the Edit Details dialog on
    Project Details. projectDetailsForm.js adds and removes units from the
    template below.

    $project: the saved project values, keyed by field_key.
    $units: [['values' => [...], 'detail_id' => ?int], ...]. Empty draws one
    blank unit, because a form with unit fields always asks for at least one.
--}}
@props([
    'type',
    'project' => [],
    'units' => [],
    'hidden' => false,
])

@php
    $form = $type->detailForm;
    $projectFields = $form->projectFields();
    $unitFields = $form->unitFields();
    $units = array_values($units) ?: [['values' => [], 'detail_id' => null]];
    $base = 'details['.$type->type_id.']';
@endphp

<section class="project-details-type border rounded-3 p-3 mb-3" data-details-type="{{ $type->type_id }}"
    data-details-type-name="{{ $type->type_name }}" data-units-next="{{ count($units) }}"
    @if ($hidden) hidden @endif>
    <h6 class="fw-bold mb-3">
        {{ $type->type_name }}
        <span class="text-secondary small fw-normal">&middot; {{ $form->name }}</span>
    </h6>

    @if ($projectFields->isNotEmpty())
        <x-detail-form-fields :fields="$projectFields" :prefix="$base.'[project]'" :values="$project" />
    @endif

    @if ($unitFields->isNotEmpty())
        <div class="d-flex justify-content-between align-items-center {{ $projectFields->isNotEmpty() ? 'mt-3' : '' }} mb-2">
            <span class="fw-semibold small">Units</span>
            <button type="button" class="btn btn-sm btn-outline-primary" data-units-add>
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                Add Unit
            </button>
        </div>

        <div data-units-rows>
            @foreach ($units as $index => $unit)
                <x-detail-form-row :fields="$unitFields" :prefix="$base.'[units]['.$index.']'"
                    :values="$unit['values'] ?? []" :detail-id="$unit['detail_id'] ?? null"
                    :title="'Unit '.$loop->iteration" :removable="true" />
            @endforeach
        </div>

        <template data-units-template>
            <x-detail-form-row :fields="$unitFields" :prefix="$base.'[units][__ROW__]'" title="Unit" :removable="true" />
        </template>
    @endif
</section>
