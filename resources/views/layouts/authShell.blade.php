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

    @foreach (['success' => 'bg-success', 'error' => 'bg-danger'] as $key => $background)
        @if (session($key))
            <div class="toast-container position-fixed top-0 end-0 p-3">
                <div class="toast align-items-center {{ $background }} text-white border-0" role="alert"
                    data-bs-autohide="true" data-bs-delay="3000">
                    <div class="toast-body">{{ session($key) }}</div>
                </div>
            </div>
        @endif
    @endforeach

    <div class="d-flex align-items-center justify-content-center min-vh-100 py-4">
        <div class="card shadow-sm" style="width: 460px; max-width: 100%;">
            <div class="card-body p-5 text-center">
                @yield('card')
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.toast').forEach(function (toastEl) {
                new bootstrap.Toast(toastEl).show();
            });
        });
    </script>

</body>

</html>
