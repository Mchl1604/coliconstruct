{{--
    The shell every error page sits in: 403, 404, 419, 429, 500, 503 and the
    4xx / 5xx catch-alls in resources/views/errors.

    Deliberately standalone rather than built on publicSite. That layout reads
    its branding from the database and draws the bell and account menu, and
    an error page must still render when the database is what failed, or when
    maintenance mode answers before any session exists. So the logo is the
    file on disk, there is no CSRF token and no flash toast, and the one thing
    that asks who is reading - ErrorRecovery - cannot throw.

    Nothing about the exception itself is ever printed: the log has the
    detail, and the reader gets a status code and one sentence.

    Pages pass flags through @extends: `retry` (Try Again), `back` (Go Back),
    `home` (the reader's own home, default on) and `signIn` (Sign In, for a
    guest only).
--}}
@php
    $homeAction = \App\Support\ErrorRecovery::homeAction();
    $showHome = $home ?? true;
    $showSignIn = ($signIn ?? false) && \App\Support\ErrorRecovery::isGuest();
    // Reloading a failed POST would resubmit it, so Try Again is only a link
    // back to a page that was a plain GET.
    $retryUrl = ($retry ?? false) && request()->isMethod('GET') ? request()->fullUrl() : null;
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>@yield('title') | Coliconstruct</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/theme.css') }}" rel="stylesheet">
    <link href="{{ asset('css/publicSite.css') }}" rel="stylesheet">
    <style>
        body {
            background: var(--brand-blue-tint, #f4f9fe);
        }

        .error-code {
            font-size: clamp(3.5rem, 14vw, 5.5rem);
            font-weight: 800;
            line-height: 1;
            letter-spacing: -.02em;
            color: var(--brand-blue-deep, #2370b4);
        }

        .error-title {
            color: var(--brand-ink, #1b3a52);
        }
    </style>
</head>

<body>

    <main class="d-flex align-items-center justify-content-center min-vh-100 py-4 px-3">
        <div class="card shadow-sm border-0" style="width: 480px; max-width: 100%;">
            <div class="card-body p-4 p-md-5 text-center">
                <a href="{{ $homeAction['url'] }}" class="d-inline-block mb-3">
                    <img src="{{ asset('img/coliconstructlogor.png') }}" alt="Coliconstruct" width="72">
                </a>

                <div class="error-code mb-2">@yield('code')</div>
                <h1 class="h4 error-title mb-2">@yield('heading')</h1>
                <p class="text-muted mb-4">@yield('message')</p>

                <div class="d-flex flex-column flex-sm-row flex-wrap justify-content-center gap-2">
                    @if ($retryUrl)
                        <a href="{{ $retryUrl }}" class="btn btn-brand-blue btn-pill px-4">
                            <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Try Again
                        </a>
                    @endif

                    @if ($showSignIn)
                        <a href="{{ route('auth.login') }}" class="btn btn-brand-blue btn-pill px-4">
                            <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Sign In
                        </a>
                    @elseif ($showHome)
                        <a href="{{ $homeAction['url'] }}" class="btn btn-brand-blue btn-pill px-4">
                            <i class="bi bi-house-door me-1" aria-hidden="true"></i>{{ $homeAction['label'] }}
                        </a>
                    @endif

                    @if ($back ?? false)
                        {{-- Hidden until the script below finds somewhere to go
                             back to, so a page opened in a new tab does not
                             offer a button that does nothing. --}}
                        <button type="button" class="btn btn-outline-secondary btn-pill px-4" data-error-back hidden>
                            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Go Back
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </main>

    @if ($back ?? false)
        <script>
            (function () {
                var button = document.querySelector('[data-error-back]');

                if (button && window.history.length > 1) {
                    button.hidden = false;
                    button.addEventListener('click', function () {
                        window.history.back();
                    });
                }
            })();
        </script>
    @endif

</body>

</html>
