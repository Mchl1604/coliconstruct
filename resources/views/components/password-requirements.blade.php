@props([
    // The id of the password input this checklist follows.
    'for',
])

{{--
    The password policy as a checklist that ticks itself off as the password
    is typed.

    Drawn from App\Support\PasswordPolicy, patterns and all, so the browser
    tests exactly what the server will: nothing here is a second copy of the
    rules. passwordField.js drives it, and also refuses to submit a form while
    a line is still unticked.
--}}
<div {{ $attributes->merge(['class' => 'text-start']) }}>
    <div class="form-text fw-semibold mb-1" id="{{ $for }}RequirementsHeading">Password requirements</div>

    <ul class="list-unstyled small mb-0" id="{{ $for }}Requirements"
        aria-labelledby="{{ $for }}RequirementsHeading" data-password-requirements="{{ $for }}">
        @foreach (\App\Support\PasswordPolicy::checklist() as $requirement)
            <li class="text-muted" data-requirement="{{ $requirement['key'] }}"
                @if ($requirement['pattern']) data-pattern="{{ $requirement['pattern'] }}" @endif
                @if ($requirement['min_length']) data-min-length="{{ $requirement['min_length'] }}" @endif
                @if ($requirement['missing']) data-missing="{{ $requirement['missing'] }}" @endif>
                <i class="bi bi-circle me-1" aria-hidden="true" data-requirement-icon></i>
                {{ $requirement['label'] }}
                <span class="visually-hidden" data-requirement-state>(not met)</span>
            </li>
        @endforeach
    </ul>
</div>
