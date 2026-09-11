@props([
    'project',
    'type',
    'events',
])

{{--
    What has happened to one document type's files on this project.

    Read-only: nothing here changes the project. The files it holds now are the
    ones listed on the page itself. The quotation has a dialog of its own -
    x-quotation-history-modal - because its amount changes belong beside its
    file changes.
--}}
@php
    $label = \App\Models\Document::TYPES[$type] ?? ucfirst($type);
    $modalId = $type.'HistoryModal';
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="{{ $modalId }}Label">
                    <i class="bi bi-clock-history me-2" aria-hidden="true"></i>
                    {{ $label }} History &mdash; {{ $project->reference_no ?? $project->name }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <h6 class="project-history-heading">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    File changes
                </h6>

                <x-document-history-events :events="$events" :label="$label" />
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
