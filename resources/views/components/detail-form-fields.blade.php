{{--
    The inputs of a set of detail form fields, named {prefix}[field_key].
    Used for a type's project fields and, inside detail-form-row, for each
    unit - see ProjectDetailForms for how they are submitted.
--}}
@props([
    'fields',
    'prefix',
    'values' => [],
])

<div class="row g-2" data-detail-fields>
    @foreach ($fields as $field)
        @php
            $name = $prefix.'['.$field->field_key.']';
            $value = $values[$field->field_key] ?? null;
            // Built from the name, so a row cloned from the template gets
            // fresh ids when its row placeholder is replaced.
            $inputId = 'df_'.preg_replace('/[^A-Za-z0-9_]+/', '_', $name);
            $wide = in_array($field->input_type, [\App\Support\DetailFieldType::LongText, \App\Support\DetailFieldType::Checkboxes], true);
        @endphp

        <div class="{{ $wide ? 'col-12' : 'col-md-6 col-lg-4' }}">
            <label class="form-label small mb-1" for="{{ $inputId }}">
                {{ $field->label }}
                @if ($field->is_required)
                    <span class="text-danger">*</span>
                @endif
            </label>

            @switch($field->input_type)
                @case(\App\Support\DetailFieldType::LongText)
                    <textarea name="{{ $name }}" id="{{ $inputId }}" rows="2" maxlength="2000"
                        class="form-control form-control-sm" data-detail-input
                        @required($field->is_required)>{{ $value }}</textarea>
                @break

                @case(\App\Support\DetailFieldType::Number)
                    <input type="number" name="{{ $name }}" id="{{ $inputId }}" min="0" max="999999" step="any"
                        class="form-control form-control-sm" value="{{ $value }}" data-detail-input
                        @required($field->is_required)>
                @break

                @case(\App\Support\DetailFieldType::Date)
                    <input type="date" name="{{ $name }}" id="{{ $inputId }}"
                        class="form-control form-control-sm" value="{{ $value }}" data-detail-input
                        @required($field->is_required)>
                @break

                @case(\App\Support\DetailFieldType::Dropdown)
                    <select name="{{ $name }}" id="{{ $inputId }}" class="form-select form-select-sm"
                        data-detail-input @required($field->is_required)>
                        <option value="">Choose</option>
                        @foreach ($field->optionList() as $option)
                            <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                @break

                @case(\App\Support\DetailFieldType::YesNo)
                    <select name="{{ $name }}" id="{{ $inputId }}" class="form-select form-select-sm"
                        data-detail-input @required($field->is_required)>
                        <option value="">Choose</option>
                        <option value="Yes" @selected($value === 'Yes')>Yes</option>
                        <option value="No" @selected($value === 'No')>No</option>
                    </select>
                @break

                @case(\App\Support\DetailFieldType::Checkboxes)
                    <div id="{{ $inputId }}">
                        @foreach ($field->optionList() as $option)
                            <div class="form-check form-check-inline">
                                <input type="checkbox" class="form-check-input" name="{{ $name }}[]"
                                    id="{{ $inputId }}_{{ $loop->index }}" value="{{ $option }}" data-detail-input
                                    @checked(in_array($option, (array) $value, true))>
                                <label class="form-check-label small" for="{{ $inputId }}_{{ $loop->index }}">
                                    {{ $option }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                @break

                @default
                    <input type="text" name="{{ $name }}" id="{{ $inputId }}" maxlength="255"
                        class="form-control form-control-sm" value="{{ $value }}" data-detail-input
                        @required($field->is_required)>
            @endswitch
        </div>
    @endforeach
</div>
