{{--
    Complete Project, last step: after the Service Report form's Next. The
    file input and the button belong to that form by its id, so one post sends
    the report and the photos together - see ProjectCompletionForm. A lead
    must choose one unless the project already has one; the office may skip.
--}}
@props([
    'project',
    'form',
    'detailsModal' => 'editProjectDescriptionModal',
])

@php
    $photosRequired = (bool) auth()->user()?->isLeadTechnician() && ! $project->completionPhotos()->exists();
@endphp

<div class="modal fade" id="completionPhotosModal" tabindex="-1" aria-hidden="true"
    aria-labelledby="completionPhotosTitle" data-completion-photos-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="completionPhotosTitle">Completion Photos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small mb-1" for="completionPhotosInput">
                    {{ $photosRequired ? 'Add at least one photo.' : 'Photos are optional.' }}
                </label>
                <input type="file" class="form-control" id="completionPhotosInput" name="completion_photos[]"
                    form="{{ $form }}" accept=".jpg,.jpeg,.png" multiple
                    @if ($photosRequired) data-photos-required @endif>
                <div class="form-text">JPG or PNG, up to 5 MB each.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-completion-photos-back>Back</button>
                <button type="button" class="btn btn-success" data-completion-photos-submit>Complete Project</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        document.addEventListener('click', function (event) {
            const back = event.target.closest('[data-completion-photos-back]');

            if (back) {
                const photos = back.closest('.modal');

                photos.addEventListener('hidden.bs.modal', function () {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById(@json($detailsModal))).show(back);
                }, { once: true });
                bootstrap.Modal.getInstance(photos).hide();

                return;
            }

            if (!event.target.closest('[data-completion-photos-submit]')) {
                return;
            }

            const input = document.getElementById('completionPhotosInput');
            const form = document.getElementById(@json($form));

            input.required = input.dataset.photosRequired !== undefined;

            if (!input.reportValidity()) {
                return;
            }

            form.dataset.photosSent = '1';
            event.target.closest('[data-completion-photos-submit]').disabled = true;
            form.requestSubmit();
        });
    </script>
@endpush
