{{--
    The centred card every signed-out page sits in: sign in, register, and the
    forced password change.
--}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Coliconstruct')</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/css/theme.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }
    </style>
</head>

<body>

    <x-flash-toasts />

    {{-- Most pages here are one narrow column. Registration asks for enough
         that it reads as two on a desktop, so the card's width is the page's
         to choose rather than this shell's; `max-width: 100%` keeps every one
         of them single-column on a phone. --}}
    <div class="d-flex align-items-center justify-content-center min-vh-100 py-4 px-3">
        <div class="card shadow-sm" style="width: @yield('card-width', '460px'); max-width: 100%;">
            <div class="card-body p-4 p-md-5 text-center">
                @yield('card')
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    {{-- The show/hide eye, and the live "do these two match" indication. --}}
    <script src="/js/passwordField.js"></script>
    @stack('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.toast').forEach(function (toastEl) {
                new bootstrap.Toast(toastEl).show();
            });
        });
    </script>

</body>

</html>
