@props([
    'name',
    'label',
    'id' => null,
    'hint' => null,
    'autocomplete' => 'current-password',
    'autofocus' => false,
    'minlength' => null,
    // 'new' and 'confirm' pair up for the live match indication.
    'role' => null,
    // The block the field sits in. A page laying these out in a grid column
    // owns the spacing, so it can hand in its own rather than fight `mb-3`.
    'wrapper' => 'mb-3 text-start',
])

@php
    $id = $id ?? $name;
@endphp

{{--
    A password field with a show/hide eye.

    The eye sits inside the input group rather than beside it, so the control
    reads as one field. passwordField.js drives it.
--}}
<div class="{{ $wrapper }}">
    <label class="form-label" for="{{ $id }}">{{ $label }}</label>

    <div class="input-group" data-password-field>
        <input type="password" id="{{ $id }}" name="{{ $name }}"
            class="form-control border-end-0 @error($name) is-invalid @enderror"
            autocomplete="{{ $autocomplete }}"
            @if ($minlength) minlength="{{ $minlength }}" maxlength="72" @endif
            @if ($role === 'new') data-password-new @elseif ($role === 'confirm') data-password-confirm @endif
            @if ($autofocus) autofocus @endif
            {{ $attributes->merge(['required' => true]) }}>

        <button class="btn btn-outline-secondary border-start-0" type="button" data-password-toggle
            aria-label="Show password" aria-pressed="false" tabindex="-1">
            <i class="bi bi-eye" aria-hidden="true"></i>
        </button>
    </div>

    @if ($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif
</div>
