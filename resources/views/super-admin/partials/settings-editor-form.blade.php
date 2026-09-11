{{-- The half of a System Settings editor that is the same in every category:
     the fields of whichever type is chosen in the dropdown, and the bar that
     saves them. systemContents.js finds all of it by its data- hooks inside
     the [data-content-editor] this is included into, so the two categories
     cannot drift apart.

     Expects $loadingLabel - what the spinner says while a type loads. --}}
<div class="text-secondary small py-3 px-1 d-none" data-content-loading>
    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
    {{ $loadingLabel }}&hellip;
</div>

<div class="content-section-panel">
    <div class="content-section-panel-title" data-content-section-title></div>

    <form data-content-form novalidate>
        <div class="row g-4" data-content-fields></div>

        {{-- The one place these fields are saved from. Sticky to the bottom of
             the screen while the form is on it, so a long type - the Terms, a
             page of services - never has its Save button scrolled out of
             reach; it stops at the end of the form, so it is never mistaken
             for the save of a panel beneath it.

             The error sits in the bar too: a refusal from the server is about
             the Save that was just pressed, and it belongs where the eye
             already is. --}}
        <div class="settings-save-bar" data-content-savebar>
            <div class="alert alert-danger small w-100 mb-0 d-none" role="alert" data-content-error></div>

            <span class="settings-save-state" data-content-state aria-live="polite"></span>

            <div class="settings-save-actions">
                {{-- A re-read rather than an undo stack: the saved values are
                     the only thing that was ever true, so fetching them back is
                     what discarding a change means. --}}
                <button type="button" class="btn btn-outline-secondary px-3" data-content-cancel disabled>
                    Discard Changes
                </button>

                <button type="submit" class="btn btn-primary settings-save-btn" data-content-save disabled>
                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"
                        data-content-save-spinner></span>
                    <i class="bi bi-floppy" aria-hidden="true" data-content-save-icon></i>
                    <span data-content-save-label>Save Changes</span>
                </button>
            </div>
        </div>
    </form>
</div>
