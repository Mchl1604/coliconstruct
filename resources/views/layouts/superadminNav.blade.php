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
    <link href="/css/notifications.css" rel="stylesheet">
   <link rel="stylesheet"
href="https://cdn.datatables.net/2.3.8/css/dataTables.dataTables.css">
  @stack('styles')
</head>

<body>
    @php
        $user = auth()->user();
        $displayName = $user?->fullName() ?? 'Guest';
        $displayRole = $user?->roleLabel() ?? '';
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
    'url' => route('super-admin.schedules.index'),
    'active' => request()->routeIs('super-admin.schedules.*'),
],

[
    'label' => 'Task',
    'icon' => 'bi-list-task',
    'url' => route('super-admin.tasks.index'),
    'active' => request()->routeIs('super-admin.tasks.*'),
],

[
    'label' => 'Technicians',
    'icon' => 'bi-tools',
    'url' => route('super-admin.technicians.index'),
    'active' => request()->routeIs('super-admin.technicians.*'),
],

[
    'label' => 'Reports',
    'icon' => 'bi-graph-up',
    'url' => route('super-admin.reports.index'),
    'active' => request()->routeIs('super-admin.reports.*'),
],

[
    'label' => 'Configuration',
    'icon' => 'bi-sliders',
    'url' => route('super-admin.configuration.index'),
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
                {{-- Signing out changes state, so it is a POST rather than a
                     link anything can follow. --}}
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
                <button class="admin-menu-btn" type="button" data-sidebar-toggle aria-label="Toggle sidebar" aria-expanded="false">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>

                <x-notification-bell />

                {{-- The whole block is the link to Profile, and that is all it
                     is. There is no account menu beside it: Settings is
                     Configuration in the sidebar, and Logout is at the foot of
                     it, so the caret only stood between people and their own
                     page. --}}
                <a class="admin-user-menu admin-user-link" href="{{ route('profile.edit') }}"
                    aria-label="Signed in as {{ $displayName }} - open your profile">
                    <x-user-avatar :user="$user" size="md" alt="" />

                    <span>
                        <span class="admin-user-name">{{ $displayName }}</span>
                        <span class="admin-user-role">{{ $displayRole }}</span>
                    </span>
                </a>
            </header>

            <main class="admin-page">
                @yield('content')
            </main>
        </div>
    </div>
    
<x-flash-toasts />

       <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/2.3.8/js/dataTables.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.notificationRoutes = @json([
            'feed' => route('notifications.feed'),
            'readAll' => route('notifications.read-all'),
        ]);
    </script>
    <script src="/js/notifications.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const toastElList = document.querySelectorAll('.toast');

    toastElList.forEach(function (toastEl) {
        const toast = new bootstrap.Toast(toastEl);
        toast.show();
    });
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