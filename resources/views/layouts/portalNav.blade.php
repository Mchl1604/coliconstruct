{{--
    The shell for the technician portal.

    One layout serves both technician roles: the chrome is identical, only the
    navigation differs, and that is derived from the signed-in role rather than
    passed in by each page. A technician cannot be shown a lead's link by a
    view that forgot to check.

    Clients do not come through here - the public website is their portal.
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
    {{-- The one date picker, everywhere: flatpickr, pinned, with the house
         style after it. Pages do not load their own copy - a second copy would
         replace window.flatpickr and lose the defaults datePicker.js sets. --}}
    <link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" rel="stylesheet">
    <link href="/css/datePicker.css" rel="stylesheet">
    <link href="/css/theme.css" rel="stylesheet">
    <link href="/css/superAdminNav.css" rel="stylesheet">
    <link href="/css/notifications.css" rel="stylesheet">
    {{-- Same DataTables build the Super Admin shell loads, so a table looks
         and behaves identically in either portal. --}}
    <link rel="stylesheet" href="https://cdn.datatables.net/2.3.8/css/dataTables.dataTables.css">
    {{-- The same status palette the administrative portal uses: a project
         is the same colour to the crew as it is to the office. --}}
    <link href="/css/projectStatus.css" rel="stylesheet">
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
            // A client has no portal here: the public website is theirs.
            default => [],
        };
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

        <aside class="admin-sidebar" aria-label="Portal navigation">
            {{-- Home for a technician is their schedule - the first link in
                 the navigation below and the page they sign in to. The logo
                 used to leave the portal for the public website. --}}
            <a class="admin-brand" href="{{ route('technician.schedule') }}">
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
        </aside>

        <div class="admin-backdrop" data-sidebar-backdrop></div>

        <div class="admin-content">
            <header class="admin-topbar">
                <button class="admin-menu-btn" type="button" data-sidebar-toggle aria-label="Toggle sidebar"
                    aria-expanded="false">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>

                <x-notification-bell />

                {{-- Who is signed in, and Profile and Logout behind it.
                     The same menu the administrative shell carries - see
                     x-account-menu, which is also where Logout moved to from
                     the foot of the sidebar. --}}
                <x-account-menu :user="$user" />
            </header>

            <main class="admin-page">
                @yield('content')
            </main>
        </div>
    </div>

    {{-- Flashed toasts, and the container the toasts an AJAX action raises
         are appended to. --}}
    <x-flash-toasts />

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.8/js/dataTables.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    {{-- Before the page's own scripts, so every picker they build inherits
         the shared defaults and the Clear button. --}}
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script src="/js/datePicker.js"></script>
    <script src="/js/technician/portal.js"></script>
    <script>
        window.notificationRoutes = @json([
            'feed' => route('notifications.feed'),
            'readAll' => route('notifications.read-all'),
        ]);
    </script>
    <script src="/js/notifications.js"></script>
    {{-- The sidebar and the toasts, shared with the Super Admin shell. --}}
    <script src="/js/adminShell.js"></script>
    {{-- Which tab and how far down, kept across a save's redirect. Before
         the page's own scripts - see the file. --}}
    <script src="/js/pageMemory.js"></script>

    @stack('scripts')
</body>

</html>
