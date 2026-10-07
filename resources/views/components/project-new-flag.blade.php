@props(['project'])

{{--
    "Nobody has opened this project yet."

    Shown from the moment a project is created until its details page is first
    opened by an Admin or Super Admin - see ProjectController::show().
--}}
@if ($project->isNew())
    <span {{ $attributes->merge(['class' => 'project-new-flag']) }} title="Not opened yet." data-project-new-flag>
        <i class="bi bi-stars" aria-hidden="true"></i>
        NEW
    </span>
@endif
