@props([
    // Which portal's forms it opens: 'super-admin' or 'technician'.
    'portal',
    // The project, when opened from inside one: the form then asks for no
    // project and comes back here. Null on the Reports pages.
    'project' => null,
    'size' => '',
])

{{-- One button, then the choice of report. --}}
@php
    $query = $project ? ['project' => $project, 'from' => 'project'] : [];
@endphp

<div class="dropdown d-inline-block">
    <button type="button" class="btn btn-primary dropdown-toggle {{ $size }}" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
        Create Report
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
        <li>
            <a class="dropdown-item" href="{{ route($portal.'.progress-reports.create', $query) }}">
                <i class="bi bi-clipboard-data me-2 text-primary" aria-hidden="true"></i>
                Progress Report
            </a>
        </li>
        <li>
            <a class="dropdown-item" href="{{ route($portal.'.incident-reports.create', $query) }}">
                <i class="bi bi-exclamation-triangle me-2 text-danger" aria-hidden="true"></i>
                Incident Report
            </a>
        </li>
    </ul>
</div>
