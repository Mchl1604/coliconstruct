{{--
    Every flashed toast in the system, in one place.

    Each layout used to carry its own copy of this markup, which is how the
    close button came to be missing from all of them at once. One component
    means a toast looks and behaves the same wherever it is raised, and the
    same container is reused by the toasts JavaScript appends.
--}}
@php
    $flashToasts = [
        'success' => 'bg-success text-white',
        'error' => 'bg-danger text-white',
        'warning' => 'bg-warning text-dark',
        'info' => 'bg-info text-dark',
    ];
@endphp

<div class="toast-container position-fixed top-0 end-0 p-3" data-toast-container>
    @foreach ($flashToasts as $key => $background)
        @continue(blank(session($key)))

        @php $isDark = str_contains($background, 'text-dark'); @endphp

        {{-- The body and the close button sit in an inner flex row rather
             than on the toast itself: `d-flex` carries !important, and on
             `.toast` it would beat the rule that keeps a toast hidden until
             it is shown. --}}
        <div class="toast align-items-center border-0 {{ $background }}" role="alert" aria-live="assertive"
            aria-atomic="true" data-bs-autohide="true" data-bs-delay="3000">

            <div class="d-flex">
                <div class="toast-body">{{ session($key) }}</div>

                {{-- Bootstrap's data API resolves the toast instance itself,
                     so this dismisses whether the toast was shown by the
                     layout's boot script or created in JavaScript. --}}
                <button type="button" class="btn-close {{ $isDark ? '' : 'btn-close-white' }} me-2 m-auto"
                    data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    @endforeach
</div>
