@props([
    // The dialog's id, so a page that already has a #termsModal - or that
    // wants two of these - can name its own.
    'id' => 'termsModal',
])

{{--
    The Terms and Conditions, to read.

    A dialog rather than a page on purpose. Every place the document is opened
    from, somebody is in the middle of something: filling in the registration
    form, reading a project, looking at the footer of the page they are on.
    Sending them away would cost them it; closing this puts them back exactly
    where they were.

    Opening this records nothing. It is the reading copy - the footer link and
    the registration form both use it - and it is deliberately NOT the dialog
    that asks a client to agree, which is x-terms-agreement-modal. Somebody who
    opens the terms out of interest has not agreed to anything, and a dialog
    that quietly took that as consent would be the worst kind of small print.

    The text is not written here. It is one editable field - Configuration >
    System Settings > Terms & Conditions - so what this shows is what the
    company last agreed to rather than a copy in a Blade file that a deployment
    is needed to change.

    Plain text, escaped, laid out by `white-space: pre-wrap`. Whoever writes the
    company's terms should not have to close a <p> tag to say what they mean,
    and this is the one page a visitor reads before agreeing to anything - so
    there is no markup to get wrong and nothing typed into that box can put
    markup on this page.
--}}
@php
    $terms = app(\App\Services\SystemContentService::class)->terms();
@endphp

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}Label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="{{ $id }}Label">Terms and Conditions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body text-start small" style="white-space: pre-wrap;">{{ $terms }}</div>

            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>
