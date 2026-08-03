{{--
    The shell for every non-administrative portal.

    One layout serves the technician, lead technician and client sidebars: the
    chrome is identical, only the navigation differs, and that is derived from
    the signed-in role rather than passed in by each page. A technician cannot
    be shown a lead's link by a view that forgot to check.
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
    <link href="/css/superAdminNav.css" rel="stylesheet">
    {{-- Same DataTables build the Super Admin shell loads, so a table looks
         and behaves identically in either portal. --}}
    <link rel="stylesheet" href="https://cdn.datatables.net/2.3.8/css/dataTables.dataTables.css">
    @stack('styles')
</head>

<body>
    @php
        $user = auth()->user();

        $portalNavItems = match ($user?->role) {
            // Both technician roles get the same pages; Tasks shows the whole
            // board for their projects rather than only their own slice, so it
            // loses the "My". Reports is the one page a lead has and a
            // technician does not.
            'lead_technician' => [
                ['label' => 'My Schedule', 'icon' => 'bi-calendar-event', 'route' => 'technician.schedule'],
                ['label' => 'My Projects', 'icon' => 'bi-folder2-open', 'route' => 'technician.projects'],
                ['label' => 'Tasks', 'icon' => 'bi-list-task', 'route' => 'technician.tasks'],
                ['label' => 'Reports', 'icon' => 'bi-file-earmark-text', 'route' => 'technician.reports'],
            ],
            'technician' => [
                ['label' => 'My Schedule', 'icon' => 'bi-calendar-event', 'route' => 'technician.schedule'],
                ['label' => 'My Projects', 'icon' => 'bi-folder2-open', 'route' => 'technician.projects'],
                ['label' => 'Tasks', 'icon' => 'bi-list-task', 'route' => 'technician.tasks'],
            ],
            'client' => [
                ['label' => 'My Projects', 'icon' => 'bi-folder2-open', 'route' => 'client.dashboard'],
            ],
            default => [],
        };
    @endphp

    <div class="admin-shell" data-admin-shell>
        <aside class="admin-sidebar" aria-label="Portal navigation">
            <a class="admin-brand" href="{{ url('/') }}">
                <img src="{{ asset('img/coliconstructlogor.png') }}" alt="Coliconstruct" class="admin-brand-logo">
                <span>Coliconstruct</span>
            </a>

            <nav class="py-2">
                <p class="admin-nav-heading">Navigation</p>

                <div class="admin-sidebar-nav">
                    @foreach ($portalNavItems as $item)
                        <a href="{{ route($item['route']) }}"
                            class="admin-sidebar-link {{ request()->routeIs($item['route']) ? 'active' : '' }}">
                            <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </nav>

            <div class="admin-sidebar-footer">
                <form method="POST" action="{{ route('auth.logout') }}">
                    @csrf
                    <button type="submit" class="admin-sidebar-link admin-sidebar-logout">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                        <span>Logout</span>
                    </button>
                </form>
            </div>
        </aside>

        <div class="admin-backdrop" data-sidebar-backdrop></div>

        <div class="admin-content">
            <header class="admin-topbar">
                <button class="admin-menu-btn" type="button" data-sidebar-toggle aria-label="Toggle sidebar"
                    aria-expanded="false">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>

                <div class="admin-user-menu" role="group" aria-label="Signed in as">
                    <span>
                        <span class="admin-user-name">{{ $user?->fullName() }}</span>
                        <span class="admin-user-role">{{ $user?->roleLabel() }}</span>
                    </span>
                </div>
            </header>

            <main class="admin-page">
                @yield('content')
            </main>
        </div>
    </div>

    @if (session('success'))
        <div class="toast-container position-fixed top-0 end-0 p-3">
            <div class="toast align-items-center bg-success text-white border-0" role="alert"
                data-bs-autohide="true" data-bs-delay="3000">
                <div class="toast-body">{{ session('success') }}</div>
            </div>
        </div>
    @endif

    @if (session('error'))
        <div class="toast-container position-fixed top-0 end-0 p-3">
            <div class="toast align-items-center bg-danger text-white border-0" role="alert" data-bs-autohide="true"
                data-bs-delay="3000">
                <div class="toast-body">{{ session('error') }}</div>
            </div>
        </div>
    @endif

    {{-- The toasts an AJAX action raises are appended here. --}}
    <div class="toast-container position-fixed top-0 end-0 p-3" data-toast-container></div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.8/js/dataTables.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/technician/portal.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.toast').forEach(function (toastEl) {
                new bootstrap.Toast(toastEl).show();
            });

            const shell = document.querySelector('[data-admin-shell]');
            const toggle = document.querySelector('[data-sidebar-toggle]');
            const backdrop = document.querySelector('[data-sidebar-backdrop]');

            if (!shell || !toggle || !backdrop) {
                return;
            }

            function setSidebarOpen(isOpen) {
                shell.classList.toggle('sidebar-open', isOpen);
                toggle.setAttribute('aria-expanded', String(isOpen));
            }

            toggle.addEventListener('click', function () {
                if (window.innerWidth >= 992) {
                    shell.classList.toggle('sidebar-collapsed');
                    setSidebarOpen(false);

                    return;
                }

                setSidebarOpen(!shell.classList.contains('sidebar-open'));
            });

            backdrop.addEventListener('click', function () {
                setSidebarOpen(false);
            });

            window.addEventListener('resize', function () {
                if (window.innerWidth >= 992) {
                    setSidebarOpen(false);
                }
            });
        });
    </script>

    @stack('scripts')
</body>

</html>
