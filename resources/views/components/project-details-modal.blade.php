{{--
    Project Details -> Edit Description. Draws each of the project's types
    through project-details-editor, the same sections the create wizard uses,
    filled with what was saved - or with what was typed, reopened on its own
    error bag after a refused save. Opened from project-description's pencil.
    Notes stay in the Edit Project dialog, beside the project's other text.
--}}
@props([
    'project',
    'id' => 'editProjectDescriptionModal',
])

@php
    $project->loadMissing(['details', 'projectTypes.detailForm.activeFields']);

    $detailTypes = app(\App\Services\ProjectDetailForms::class)->withForms($project->projectTypes);
    $savedByType = $project->details->groupBy('type_id');
    $detailErrors = $errors->getBag(\App\Http\Controllers\ProjectDetailController::ERROR_BAG);
    $reopen = $detailErrors->any();
@endphp

@if ($detailTypes->isNotEmpty())
    <div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true" aria-labelledby="{{ $id }}Title"
        data-project-details-modal @if ($reopen) data-reopen="1" @endif>
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" action="{{ route('super-admin.projects.details.update', $project->project_id) }}"
                    class="d-flex flex-column overflow-hidden" data-project-details-form>
                    @csrf
                    @method('PUT')

                    <div class="modal-header">
                        <h5 class="modal-title" id="{{ $id }}Title">Edit Description</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body" data-project-details-editor>
                        @if ($reopen)
                            <div class="alert alert-danger small py-2" role="alert">
                                <ul class="mb-0 ps-3">
                                    @foreach (collect($detailErrors->all())->unique() as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @foreach ($detailTypes as $type)
                            @php
                                $saved = $savedByType->get($type->type_id, collect());
                                $old = (array) old('details.'.$type->type_id, []);

                                $projectValues = $reopen
                                    ? (array) ($old['project'] ?? [])
                                    : (array) ($saved->first(fn ($detail) => $detail->scope === \App\Support\DetailFieldScope::Project)?->values ?? []);

                                $units = $reopen
                                    ? collect((array) ($old['units'] ?? []))
                                        ->filter(fn ($row) => is_array($row))
                                        ->map(fn ($row) => [
                                            'values' => $row,
                                            'detail_id' => $row[\App\Services\ProjectDetailForms::DETAIL_ID_KEY] ?? null,
                                        ])
                                    : $saved->filter(fn ($detail) => $detail->scope === \App\Support\DetailFieldScope::Unit)
                                        ->map(fn ($detail) => [
                                            'values' => (array) $detail->values,
                                            'detail_id' => $detail->detail_id,
                                        ]);
                            @endphp

                            <x-project-details-editor :type="$type" :project="$projectValues"
                                :units="$units->values()->all()" />
                        @endforeach
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="/js/projectDetailsForm.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const modal = document.querySelector('[data-project-details-modal]');

                if (!modal || !window.projectDetailsForm) {
                    return;
                }

                window.projectDetailsForm.init(modal.querySelector('[data-project-details-editor]'));

                // A refused save comes back with the dialog open on what was typed.
                if (modal.dataset.reopen === '1') {
                    bootstrap.Modal.getOrCreateInstance(modal).show();
                }
            });
        </script>
    @endpush
@endif
