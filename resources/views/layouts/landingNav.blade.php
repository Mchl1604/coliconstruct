<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'My App')</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/css/theme.css" rel="stylesheet">

    <style>
        @media (min-width: 992px) {
            .navbar-center {
                position: absolute;
                left: 50%;
                transform: translateX(-50%);
            }
        }
    </style>

</head>

<body>

<nav class="navbar navbar-expand-lg bg-body-tertiary shadow-sm">
    <div class="container-fluid px-4">

        <!-- Logo -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="#">
            <img src="/img/coliconstructlogor.png"
                 alt="Logo"
                 width="50"
                 class="img-fluid">
            <span>Coliconstruct</span>
        </a>

        <!-- Burger Button -->
        <button class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#navbarSupportedContent"
                aria-controls="navbarSupportedContent"
                aria-expanded="false"
                aria-label="Toggle navigation">

            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navbar -->
        <div class="collapse navbar-collapse" id="navbarSupportedContent">

            <!-- Center Navigation -->
            <ul class="navbar-nav navbar-center mb-2 mb-lg-0">

                <li class="nav-item">
                    <a class="nav-link active" href="#">Home</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#">Services</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#">About</a>
                </li>

            </ul>

            <!-- Right Buttons -->
            <div class="ms-lg-auto d-flex flex-column flex-lg-row gap-2">

                <a class="btn btn-outline-secondary" href="{{ route('auth.login') }}">
                    Login
                </a>

                <a class="btn btn-primary" href="#">
                    Register
                </a>

            </div>

        </div>

    </div>
</nav>

{{-- Success Toast --}}
@if (session('success'))
<div class="toast-container position-fixed top-0 end-0 p-3">
    <div class="toast align-items-center bg-success text-white border-0"
         role="alert"
         data-bs-autohide="true"
         data-bs-delay="3000">

        <div class="toast-body">
            {{ session('success') }}
        </div>

    </div>
</div>
@endif

{{-- Error Toast --}}
@if (session('error'))
<div class="toast-container position-fixed top-0 end-0 p-3">
    <div class="toast align-items-center bg-danger text-white border-0"
         role="alert"
         data-bs-autohide="true"
         data-bs-delay="3000">

        <div class="toast-body">
            {{ session('error') }}
        </div>

    </div>
</div>
@endif

{{-- Page Content --}}
<main>
    @yield('content')
</main>

{{-- Bootstrap JS --}}
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const toastElList = document.querySelectorAll('.toast');

    toastElList.forEach(function (toastEl) {
        const toast = new bootstrap.Toast(toastEl);
        toast.show();
    });

});
</script>

</body>
</html>