{{--
    A project's Service Report in a dialog, printable. Opened from
    project-description on every portal; serviceReport.js moves it to the end
    of the page and prints the sheet alone.

    $editTarget: the dialog that edits the details, or null where the reader
    may not.
--}}
@props([
    'project',
    'editTarget' => null,
    'editDisabled' => false,
])

@php
    $modalId = 'serviceReportModal'.$project->project_id;
@endphp

@once
    <link href="/css/serviceReport.css" rel="stylesheet">
    <script src="/js/serviceReport.js" defer></script>
@endonce

<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}">
    <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>
    View Project Details
</button>

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true" aria-labelledby="{{ $modalId }}Title"
    data-service-report-modal>
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="{{ $modalId }}Title">Service Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body sr-modal-body">
                <x-service-report-sheet :project="$project" />
            </div>

            <div class="modal-footer">
                @if ($editTarget)
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal"
                        data-bs-target="#{{ $editTarget }}" @disabled($editDisabled)>
                        <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                        Edit
                    </button>
                @endif
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" data-service-report-print>
                    <i class="bi bi-printer me-1" aria-hidden="true"></i>
                    Print
                </button>
            </div>
        </div>
    </div>
</div>
