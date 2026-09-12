<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Coliconstruct')</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    {{-- The one date picker, everywhere: flatpickr, pinned, with the house
         style after it. Pages do not load their own copy - a second copy would
         replace window.flatpickr and lose the defaults datePicker.js sets. --}}
    <link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" rel="stylesheet">
    <link href="/css/datePicker.css" rel="stylesheet">
    <link href="/css/theme.css" rel="stylesheet">
    <link href="/css/superAdminNav.css" rel="stylesheet">
    <link href="/css/notifications.css" rel="stylesheet">
   <link rel="stylesheet"
href="https://cdn.datatables.net/2.3.8/css/dataTables.dataTables.css">
  {{-- What colour a project's status is, everywhere. Loaded by the layout
       rather than by each page because every page in this portal draws one -
       tables, calendars, task boards, project details. --}}
  <link href="/css/projectStatus.css" rel="stylesheet">
  @stack('styles')
</head>

<body>
    @php
        $user = auth()->user();
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
        {{-- A collapsed sidebar stays collapsed from one page to the next.
             Applied here, before the sidebar below is drawn, so it never
             appears open and then folds away. The key is adminShell.js's. --}}
        <script>
            try {
                if (localStorage.getItem('adminShell.sidebarCollapsed') === '1') {
                    document.currentScript.parentElement.classList.add('sidebar-collapsed');
                }
            } catch (error) {}
        </script>

        <aside class="admin-sidebar" aria-label="Admin navigation">
            {{-- Home for this shell is the dashboard, for an Admin as much as
                 a Super Admin - both land there after signing in. Named rather
                 than written out: /admin/dashboard was a path this app has
                 never served, so the logo answered with a 404. --}}
            <a class="admin-brand" href="{{ route('super-admin.dashboard') }}">
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
        </aside>

        <div class="admin-backdrop" data-sidebar-backdrop></div>

        <div class="admin-content">
            <header class="admin-topbar">
                <button class="admin-menu-btn" type="button" data-sidebar-toggle aria-label="Toggle sidebar" aria-expanded="false">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>

                <x-notification-bell />

                {{-- Who is signed in, and Profile and Logout behind it.
                     Settings is still Configuration in the sidebar; what the
                     caret is for now is signing out, which moved here from the
                     foot of the sidebar - see x-account-menu, shared with the
                     technician shell. --}}
                <x-account-menu :user="$user" />
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
    {{-- Before the page's own scripts, so every picker they build inherits
         the shared defaults and the Clear button. --}}
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script src="/js/datePicker.js"></script>
    <script>
        window.notificationRoutes = @json([
            'feed' => route('notifications.feed'),
            'readAll' => route('notifications.read-all'),
        ]);
    </script>
    <script src="/js/notifications.js"></script>
    {{-- The sidebar and the toasts, shared with the technician portal. --}}
    <script src="/js/adminShell.js"></script>
    {{-- Which tab and how far down, kept across a save's redirect. Before
         the page's own scripts - see the file. --}}
    <script src="/js/pageMemory.js"></script>

    @stack('scripts')
</body>

</html>