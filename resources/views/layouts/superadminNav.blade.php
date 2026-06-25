<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Coliconstruct')</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/css/theme.css" rel="stylesheet">
    <link href="/css/superAdminNav.css" rel="stylesheet">
   <link rel="stylesheet"
href="https://cdn.datatables.net/2.3.8/css/dataTables.dataTables.css">
  @stack('styles')
</head>

<body>
    @php
        $user = auth()->user();
        $displayName = $user?->name ?? 'Michael Capanayan';
        $displayRole = $user?->role ?? 'Admin';
        $adminNavItems = [
            [
    'label' => 'Dashboard',
    'icon' => 'bi-speedometer2',
    'url' => route('super-admin.dashboard'),
    'active' => request()->routeIs('super-admin.dashboard'),
],

[
    'label' => 'Projects',
    'icon' => 'bi-folder2-open',
    'url' => route('super-admin.projects'),
    'active' => request()->routeIs('super-admin.projects'),
],

[
    'label' => 'Schedules',
    'icon' => 'bi-calendar-event',
    'url' => '#',
    'active' => request()->routeIs('super-admin.schedules.*'),
],

[
    'label' => 'Technicians',
    'icon' => 'bi-tools',
    'url' => '#',
    'active' => request()->routeIs('super-admin.technicians.*'),
],

[
    'label' => 'Reports',
    'icon' => 'bi-graph-up',
    'url' => '#',
    'active' => request()->routeIs('super-admin.reports.*'),
],

[
    'label' => 'Configuration',
    'icon' => 'bi-sliders',
    'url' => '#',
    'active' => request()->routeIs('super-admin.configuration.*'),
],
        ];
    @endphp

    <div class="admin-shell" data-admin-shell>
        <aside class="admin-sidebar" aria-label="Admin navigation">
            <a class="admin-brand" href="{{ url('/admin/dashboard') }}">
                <img src="{{ asset('img/coliconstructlogor.png') }}" alt="Coliconstruct" class="admin-brand-logo">
                <span>Coliconstruct</span>
            </a>

            <nav class="py-2">
                <p class="admin-nav-heading">Navigation</p>

                <div class="admin-sidebar-nav">
                    @foreach ($adminNavItems as $item)
                        <a href="{{ $item['url'] }}" class="admin-sidebar-link {{ $item['active'] ? 'active' : '' }}">
                            <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </nav>

            <div class="admin-sidebar-footer">
                <a href="{{ route('landing.home') }}" class="admin-sidebar-link">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <div class="admin-backdrop" data-sidebar-backdrop></div>

        <div class="admin-content">
            <header class="admin-topbar">
                <button class="admin-menu-btn" type="button" data-sidebar-toggle aria-label="Toggle sidebar" aria-expanded="false">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>

                <div class="dropdown">
                    <button class="admin-user-menu" type="button" data-user-menu-toggle data-bs-toggle="dropdown" aria-expanded="false" aria-label="User menu">
                        <span>
                            <span class="admin-user-name">{{ $displayName }}</span>
                            <span class="admin-user-role">{{ $displayRole }}</span>
                        </span>
                        <i class="bi bi-caret-down-fill" aria-hidden="true"></i>
                    </button>

                    <ul class="dropdown-menu dropdown-menu-end admin-user-dropdown-menu">
                        <li><a class="dropdown-item" href="#">Profile</a></li>
                        <li><a class="dropdown-item" href="#">Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="{{ route('landing.home') }}">Logout</a></li>
                    </ul>
                </div>
            </header>

            <main class="admin-page">
                @yield('content')
            </main>
        </div>
    </div>

       <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/2.3.8/js/dataTables.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const shell = document.querySelector('[data-admin-shell]');
            const toggle = document.querySelector('[data-sidebar-toggle]');
            const backdrop = document.querySelector('[data-sidebar-backdrop]');
            const userMenuToggle = document.querySelector('[data-user-menu-toggle]');

            if (!shell || !toggle || !backdrop) {
                return;
            }

            function setSidebarOpen(isOpen) {
                shell.classList.toggle('sidebar-open', isOpen);
                toggle.setAttribute('aria-expanded', String(isOpen));
            }

            function setSidebarCollapsed(isCollapsed) {
                shell.classList.toggle('sidebar-collapsed', isCollapsed);
                toggle.setAttribute('aria-expanded', String(!isCollapsed));
            }

            toggle.addEventListener('click', function () {
                if (window.innerWidth >= 992) {
                    setSidebarCollapsed(!shell.classList.contains('sidebar-collapsed'));
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

            if (userMenuToggle) {
                new bootstrap.Dropdown(userMenuToggle, {
                    autoClose: true,
                    boundary: 'viewport'
                });
            }
        });
    </script>

    @stack('scripts')
</body>

</html>
