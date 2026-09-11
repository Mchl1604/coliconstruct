@extends('layouts.superadminNav')

@section('title', 'Configuration')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
    <link href="/css/super-admin/configuration.css" rel="stylesheet">
    {{-- The user search in the Export Logs dialog, shared with the
         Technicians page. --}}
    <link href="/css/actorSearch.css" rel="stylesheet">
@endpush

@section('content')
    @php
        // Archiving - and everything that hangs off it - belongs to the Super
        // Admin. An Admin creates, reads, edits, activates and deactivates.
        $isSuperAdmin = (bool) auth()->user()?->isSuperAdmin();
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Configuration</h4>
            <p class="text-secondary small mb-0">
                {{ $isSuperAdmin ? 'Accounts, audit history and system-wide settings.' : 'Accounts and audit history.' }}
            </p>
        </div>
    </div>

    <ul class="nav nav-tabs technician-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="userManagementTab" data-bs-toggle="tab"
                data-bs-target="#userManagementPane" type="button" role="tab" aria-controls="userManagementPane"
                aria-selected="true">
                <i class="bi bi-people me-1" aria-hidden="true"></i>
                User Management
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="activityLogsTab" data-bs-toggle="tab" data-bs-target="#activityLogsPane"
                type="button" role="tab" aria-controls="activityLogsPane" aria-selected="false">
                <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
                Activity Logs
            </button>
        </li>
        @if ($isSuperAdmin)
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="systemSettingsTab" data-bs-toggle="tab"
                    data-bs-target="#systemSettingsPane" type="button" role="tab"
                    aria-controls="systemSettingsPane" aria-selected="false">
                    <i class="bi bi-gear me-1" aria-hidden="true"></i>
                    System Settings
                </button>
            </li>
        @endif
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="inquiriesTab" data-bs-toggle="tab" data-bs-target="#inquiriesPane"
                type="button" role="tab" aria-controls="inquiriesPane" aria-selected="false">
                <i class="bi bi-envelope-paper me-1" aria-hidden="true"></i>
                Inquiries
                {{-- How many messages nobody has picked up yet. Hidden until
                     the table has been read once and there is a number. --}}
                <span class="badge rounded-pill bg-danger ms-1 d-none" data-inquiry-new-count></span>
            </button>
        </li>
    </ul>

    <div class="tab-content">

        {{-- ==================== TAB 1: USER MANAGEMENT ==================== --}}
        <div class="tab-pane fade show active" id="userManagementPane" role="tabpanel"
            aria-labelledby="userManagementTab">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0">User Management</h5>
                    <span class="text-secondary small">Every employee and Registered User account in the system.</span>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    @if ($isSuperAdmin)
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal"
                            data-bs-target="#archivedAccountsModal">
                            <i class="bi bi-archive me-1" aria-hidden="true"></i>
                            View Archived Accounts
                        </button>
                    @endif

                    <button type="button" class="btn btn-primary" data-add-user-open>
                        <i class="bi bi-person-plus me-1" aria-hidden="true"></i>
                        Add New User
                    </button>
                </div>
            </div>

            <div class="alert alert-danger d-none" role="alert" data-user-error></div>

            {{-- ---------------- Employees ---------------- --}}
            <div class="card shadow-sm border-0 rounded-2 mb-4">
                <div class="card-body p-3">
                    <div class="config-table-header">
                        <div>
                            <h6 class="config-table-title mb-0">
                                <i class="bi bi-person-badge me-1" aria-hidden="true"></i>
                                Employees
                            </h6>
                            <span class="text-secondary small" data-employee-count></span>
                        </div>

                        <div class="config-table-controls">
                            <input type="search" class="form-control form-control-sm config-search"
                                placeholder="Search user ID, name or email&hellip;" aria-label="Search employees"
                                data-employee-search>

                            <select class="form-select form-select-sm config-filter" aria-label="Filter by role"
                                data-employee-role>
                                <option value="all">All Roles</option>
                                @foreach ($employeeRoles as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>

                            <select class="form-select form-select-sm config-filter" aria-label="Filter by status"
                                data-employee-status>
                                <option value="all">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="deactivated">Deactivated</option>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-info">
                                <tr>
                                    <th>User ID</th>
                                    <th>Full Name</th>
                                    <th>Role</th>
                                    <th>Email Address</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody data-employee-body></tbody>
                        </table>
                    </div>

                    <div class="text-secondary small py-3 px-1" data-employee-loading>
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Loading employees&hellip;
                    </div>

                    <div class="schedule-empty-state mt-2 d-none" data-employee-empty>
                        No employee accounts match these filters.
                    </div>

                    <nav class="config-pagination d-none" aria-label="Employee pages" data-employee-pagination></nav>
                </div>
            </div>

            {{-- ---------------- Registered Users ----------------
                 A Registered User is an account on the public website - opened
                 by the person themselves or by an administrator here. Not to be
                 confused with a project's client, which is contact information
                 held on the project and keeps that name throughout.

                 `id` stays `clients` so the dashboard's quick action, and any
                 link already saved, still open this tab at this table. --}}
            <div class="card shadow-sm border-0 rounded-2" id="clients">
                <div class="card-body p-3">
                    <div class="config-table-header">
                        <div>
                            <h6 class="config-table-title mb-0">
                                <i class="bi bi-person-check me-1" aria-hidden="true"></i>
                                Registered Users
                            </h6>
                            <span class="text-secondary small" data-client-count></span>
                        </div>

                        <div class="config-table-controls">
                            <input type="search" class="form-control form-control-sm config-search"
                                placeholder="Search user ID, name or email&hellip;" aria-label="Search registered users"
                                data-client-search>

                            <select class="form-select form-select-sm config-filter" aria-label="Filter by status"
                                data-client-status>
                                <option value="all">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="deactivated">Deactivated</option>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-info">
                                <tr>
                                    <th>User ID</th>
                                    <th>Full Name</th>
                                    <th>Email Address</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody data-client-body></tbody>
                        </table>
                    </div>

                    <div class="text-secondary small py-3 px-1" data-client-loading>
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Loading registered users&hellip;
                    </div>

                    <div class="schedule-empty-state mt-2 d-none" data-client-empty>
                        No Registered User accounts match these filters.
                    </div>

                    <nav class="config-pagination d-none" aria-label="Registered User pages" data-client-pagination></nav>
                </div>
            </div>
        </div>

        {{-- ==================== TAB 2: ACTIVITY LOGS ==================== --}}
        <div class="tab-pane fade" id="activityLogsPane" role="tabpanel" aria-labelledby="activityLogsTab">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0">Activity Logs</h5>
                    <span class="text-secondary small">
                        Every recorded action across the system, newest first.
                        @if (! auth()->user()?->isSuperAdmin())
                            Entries by a Super Admin or another Admin are not shown.
                        @endif
                    </span>
                </div>

                {{-- Not drawn conditionally: whoever reaches this tab may
                     export it, and the endpoint sits behind the same role
                     check the tab does. What differs between an Admin and a
                     Super Admin is which rows come out, and that is decided
                     server-side by the same scope this table is drawn
                     through - see ActivityLog::scopeVisibleTo(). --}}
                <button type="button" class="btn btn-success" data-bs-toggle="modal"
                    data-bs-target="#exportLogsModal">
                    <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>
                    Export Logs
                </button>
            </div>

            <div class="card shadow-sm border-0 rounded-2">
                <div class="card-body p-3">
                    <div class="config-table-header">
                        <div>
                            <h6 class="config-table-title mb-0">
                                <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
                                Audit Trail
                            </h6>
                            <span class="text-secondary small" data-log-count></span>
                        </div>

                        <div class="config-table-controls">
                            <input type="search" class="form-control form-control-sm config-search"
                                placeholder="Search name, action or details&hellip;" aria-label="Search activity logs"
                                data-log-search>

                            <select class="form-select form-select-sm config-filter" aria-label="Filter by role"
                                data-log-role>
                                <option value="all">All Roles</option>
                                @foreach ($logRoles as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>

                            <select class="form-select form-select-sm config-filter" aria-label="Filter by module"
                                data-log-module>
                                <option value="all">All Modules</option>
                                @foreach ($logModules as $module)
                                    <option value="{{ $module }}">{{ $module }}</option>
                                @endforeach
                            </select>

                            <select class="form-select form-select-sm config-filter" aria-label="Filter by date"
                                data-log-range>
                                <option value="all">All Time</option>
                                <option value="today">Today</option>
                                <option value="week">Last 7 Days</option>
                                <option value="month">Last 30 Days</option>
                                <option value="custom">Custom Range</option>
                            </select>
                        </div>
                    </div>

                    {{-- Only meaningful once Custom Range is chosen, so it stays
                         out of the way until then. --}}
                    <div class="config-log-dates d-none" data-log-custom-range>
                        <label class="form-label small fw-semibold mb-0" for="logFrom">From</label>
                        <input type="date" class="form-control form-control-sm" id="logFrom" data-log-from>

                        <label class="form-label small fw-semibold mb-0" for="logTo">To</label>
                        <input type="date" class="form-control form-control-sm" id="logTo" data-log-to>
                    </div>

                    <div class="table-responsive config-log-table">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-info">
                                <tr>
                                    <th>Log ID</th>
                                    {{-- Every sortable heading is a button, so the
                                         column can be reordered from the keyboard
                                         as well as the mouse. --}}
                                    <th>
                                        <button type="button" class="config-sort is-active" data-log-sort="date">
                                            Date &amp; Time
                                            <i class="bi bi-caret-down-fill" aria-hidden="true"></i>
                                        </button>
                                    </th>
                                    <th>
                                        <button type="button" class="config-sort" data-log-sort="name">
                                            Name
                                            <i class="bi bi-caret-down-fill" aria-hidden="true"></i>
                                        </button>
                                    </th>
                                    <th>
                                        <button type="button" class="config-sort" data-log-sort="role">
                                            Role
                                            <i class="bi bi-caret-down-fill" aria-hidden="true"></i>
                                        </button>
                                    </th>
                                    <th>
                                        <button type="button" class="config-sort" data-log-sort="module">
                                            Module
                                            <i class="bi bi-caret-down-fill" aria-hidden="true"></i>
                                        </button>
                                    </th>
                                    <th>
                                        <button type="button" class="config-sort" data-log-sort="action">
                                            Action
                                            <i class="bi bi-caret-down-fill" aria-hidden="true"></i>
                                        </button>
                                    </th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody data-log-body></tbody>
                        </table>
                    </div>

                    <div class="text-secondary small py-3 px-1" data-log-loading>
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Loading activity logs&hellip;
                    </div>

                    <div class="schedule-empty-state mt-2 d-none" data-log-empty>
                        No activity logs found.
                    </div>

                    <nav class="config-pagination d-none" aria-label="Activity log pages" data-log-pagination></nav>
                </div>
            </div>

            {{-- ==================== EXPORT ACTIVITY LOGS ==================== --}}
            {{-- A plain GET form, so the browser downloads the file itself and
                 nothing has to be held in the page.

                 The two visible fields are the export's own. The hidden ones
                 carry whatever the table is currently filtered by, filled in
                 by the script when the dialog opens, so the file matches the
                 list it was asked for from. Every one of them is validated
                 again server-side; none of them is trusted for being
                 hidden. --}}
            <div class="modal fade" id="exportLogsModal" tabindex="-1" aria-labelledby="exportLogsModalLabel"
                aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="GET" action="{{ route('super-admin.configuration.activity-logs.export') }}"
                            data-log-export-form>

                            <div class="modal-header">
                                <h5 class="modal-title" id="exportLogsModalLabel">
                                    <i class="bi bi-file-earmark-arrow-down me-2" aria-hidden="true"></i>
                                    Export Activity Logs
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                            </div>

                            <div class="modal-body">
                                {{-- Both ends are required. An export is a
                                     document somebody files, and one with no
                                     period on it is the whole trail every time
                                     - which is neither useful to read nor
                                     something a PDF can hold. `max` is today
                                     because nothing was ever logged later than
                                     that; the endpoint checks all of it
                                     again. --}}
                                <div class="row g-2 mb-3">
                                    <div class="col-sm-6">
                                        <label class="form-label fw-semibold" for="exportLogsFrom">From</label>
                                        <input type="date" class="form-control" id="exportLogsFrom" name="from"
                                            max="{{ \App\Support\BusinessTime::today()->format('Y-m-d') }}" required
                                            data-log-export-from>
                                    </div>

                                    <div class="col-sm-6">
                                        <label class="form-label fw-semibold" for="exportLogsTo">To</label>
                                        <input type="date" class="form-control" id="exportLogsTo" name="to"
                                            max="{{ \App\Support\BusinessTime::today()->format('Y-m-d') }}" required
                                            data-log-export-to>
                                    </div>
                                </div>

                                {{-- Searched rather than chosen from a list.
                                     Every account that has ever done anything
                                     can appear here, which is a list nobody
                                     scrolls - so it is typed into, and the
                                     matches drop down beneath. Empty means
                                     every user, which is the default and
                                     needs no choosing. --}}
                                <div class="mb-3 config-actor-search" data-log-export-actor>
                                    <label class="form-label fw-semibold" for="exportLogsUser">User</label>

                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bi bi-search" aria-hidden="true"></i>
                                        </span>
                                        <input type="text" class="form-control" id="exportLogsUser"
                                            placeholder="All users &mdash; type a name to narrow"
                                            autocomplete="off" role="combobox" aria-expanded="false"
                                            aria-controls="exportLogsUserResults" data-log-export-user-search>
                                        <button type="button" class="btn btn-outline-secondary d-none"
                                            aria-label="Clear the chosen user" data-log-export-user-clear>
                                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    {{-- The value that is actually submitted.
                                         Typing alone never sets it: only
                                         picking somebody does, so a half-typed
                                         name cannot quietly become a filter. --}}
                                    <input type="hidden" name="actor_id" data-log-export-user-id>

                                    <ul class="config-actor-results d-none" id="exportLogsUserResults" role="listbox"
                                        data-log-export-user-results></ul>

                                    <div class="form-text" data-log-export-user-hint>
                                        Leave this empty to include every user.
                                    </div>
                                </div>

                                {{-- What the table is filtered by right now, so
                                     the file matches the list it was asked for
                                     from. Filled in by the script as the dialog
                                     opens, and every one of them validated
                                     again server-side - none is trusted for
                                     being hidden. The date range is not among
                                     them: the two fields above are the export's
                                     own, and they are what decides its
                                     period. --}}
                                <input type="hidden" name="search" data-log-export-search>
                                <input type="hidden" name="role" data-log-export-role>
                                <input type="hidden" name="module" data-log-export-module>
                                <input type="hidden" name="sort" data-log-export-sort>
                                <input type="hidden" name="direction" data-log-export-direction>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>
                                    Download PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- ==================== TAB 3: SYSTEM SETTINGS ==================== --}}
        {{-- Super Admin only. An Admin's Configuration is User Management and
             Activity Logs, so the whole pane goes rather than being emptied.

             A sidebar of categories. Choosing one opens the list of setting
             types it holds right beneath it, and choosing a type puts that
             type's fields - and only those - on screen. Both lists are the
             catalogue's own - SystemContent::SETTINGS_SECTIONS and
             SystemContent::SECTIONS - so a section added there appears here
             with nothing in this file to change. --}}
        @if ($isSuperAdmin)
        @php
            // Presentation only: an icon for each section the catalogue has
            // today. A section it gains later still draws, on its category's
            // icon.
            $sectionIcons = [
                \App\Models\SystemContent::SECTION_PROJECT_SETTINGS => 'bi-kanban',
                \App\Models\SystemContent::SECTION_INQUIRY_SETTINGS => 'bi-envelope-paper',
                \App\Models\SystemContent::SECTION_LEGAL => 'bi-file-earmark-text',
                \App\Models\SystemContent::SECTION_BRANDING => 'bi-palette',
                \App\Models\SystemContent::SECTION_HOME => 'bi-house-door',
                \App\Models\SystemContent::SECTION_ABOUT => 'bi-info-circle',
                \App\Models\SystemContent::SECTION_CONTACT => 'bi-telephone',
                \App\Models\SystemContent::SECTION_FOOTER => 'bi-layout-text-window-reverse',
                'project_types' => 'bi-diagram-3',
                'phase_templates' => 'bi-list-check',
            ];

            // Every entry in a category's list. Most are catalogue sections,
            // whose fields the editor fetches and builds. Project Types and
            // Default Phases & Tasks are project settings too, but tables with
            // endpoints of their own - `panel` entries, shown from the markup
            // below rather than fetched - and they follow Project Settings,
            // the setting they belong beside.
            $generalTypes = [];

            foreach ($settingsSections as $key => $label) {
                $generalTypes[$key] = ['label' => $label, 'panel' => false];

                if ($key === \App\Models\SystemContent::SECTION_PROJECT_SETTINGS) {
                    $generalTypes['project_types'] = ['label' => 'Project Types', 'panel' => true];
                    $generalTypes['phase_templates'] = ['label' => 'Default Phases & Tasks', 'panel' => true];
                }
            }

            $contentTypes = array_map(
                fn (string $label) => ['label' => $label, 'panel' => false],
                $contentSections
            );

            // General Settings first, then System Contents. `accents` is
            // whether each type wears a colour of its own - the website's
            // parts do, to tell them apart; the operational settings keep the
            // settings blue.
            $settingsCategories = [
                [
                    'id' => 'generalSettings',
                    'label' => 'General Settings',
                    'hint' => 'How the system behaves',
                    'icon' => 'bi-sliders',
                    'types' => $generalTypes,
                    'accents' => false,
                ],
                [
                    'id' => 'systemContents',
                    'label' => 'System Contents',
                    'hint' => 'What the public website shows',
                    'icon' => 'bi-globe',
                    'types' => $contentTypes,
                    'accents' => true,
                ],
            ];
        @endphp
        <div class="tab-pane fade" id="systemSettingsPane" role="tabpanel" aria-labelledby="systemSettingsTab">
            <div class="settings-layout">

                {{-- Each category is a button with its types folded away
                     beneath it. Opening a category shows its pane and slides
                     its types open; only the open category's list is ever
                     unfolded. The categories are systemSettingsNav.js, the
                     types are systemContents.js - each editor owns its own
                     list, found through data-content-nav. --}}
                <nav class="settings-sidebar" aria-label="System settings">
                    <div class="settings-sidebar-heading">
                        <i class="bi bi-gear" aria-hidden="true"></i>
                        System Settings
                    </div>

                    <ul class="settings-sidebar-nav" data-settings-nav>
                        @foreach ($settingsCategories as $category)
                            @php($isOpen = $loop->first)
                            <li class="settings-sidebar-group">
                                <button type="button" class="settings-sidebar-link {{ $isOpen ? 'active' : '' }}"
                                    id="{{ $category['id'] }}Tab" data-settings-category="{{ $category['id'] }}Pane"
                                    aria-controls="{{ $category['id'] }}Menu"
                                    aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                    @if ($isOpen) aria-current="true" @endif>
                                    <span class="settings-sidebar-icon">
                                        <i class="bi {{ $category['icon'] }}" aria-hidden="true"></i>
                                    </span>
                                    <span class="settings-sidebar-text">
                                        <span class="settings-sidebar-label">{{ $category['label'] }}</span>
                                        <span class="settings-sidebar-hint">{{ $category['hint'] }}</span>
                                    </span>
                                    <i class="bi bi-chevron-down settings-sidebar-chevron" aria-hidden="true"></i>
                                </button>

                                <div class="collapse {{ $isOpen ? 'show' : '' }}" id="{{ $category['id'] }}Menu">
                                    <ul class="settings-subnav" id="{{ $category['id'] }}Types" data-content-sections
                                        aria-label="{{ $category['label'] }} types">
                                        @foreach ($category['types'] as $key => $type)
                                            <li>
                                                <button type="button"
                                                    class="settings-subnav-link {{ $loop->first ? 'active' : '' }}"
                                                    data-content-section="{{ $key }}"
                                                    data-icon="{{ $sectionIcons[$key] ?? $category['icon'] }}"
                                                    @if ($type['panel']) data-content-kind="panel" @endif
                                                    @if ($category['accents']) data-settings-accent="{{ $key }}" @endif
                                                    @if ($loop->first) aria-current="true" @endif>
                                                    <i class="bi {{ $sectionIcons[$key] ?? $category['icon'] }}"
                                                        aria-hidden="true"></i>
                                                    <span>{{ $type['label'] }}</span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </nav>

                <div class="tab-content settings-main">

                {{-- ---------------- General Settings ----------------
                     The operational settings: how long a project waits on its
                     client, how often one visitor may write in, and the Terms
                     and Conditions everybody is asked to accept.

                     The same editor as System Contents, against the same
                     endpoints - one catalogue, one table, one audit entry. What
                     differs is only the list of types, because "rewrite the
                     About page" and "complete projects after five days instead
                     of seven" are not the same kind of decision. --}}
                <div class="tab-pane fade show active" id="generalSettingsPane" role="region"
                    aria-labelledby="generalSettingsTab">
                <div class="card shadow-sm border-0 rounded-2" data-content-editor
                    data-content-nav="generalSettingsTypes">
                    <div class="card-body p-4">

                        <div class="settings-panel-head">
                            <div>
                                <h5 class="fw-bold mb-1">
                                    <i class="bi bi-sliders me-1 text-primary" aria-hidden="true"></i>
                                    General Settings
                                </h5>
                                <p class="text-secondary small mb-0">
                                    How the system behaves. Saved changes apply immediately.
                                </p>
                            </div>
                        </div>

                        @include('super-admin.partials.settings-editor-form', ['loadingLabel' => 'Loading settings'])

                        {{-- The General Settings types that are not catalogue
                             fields: Project Types and Default Phases & Tasks.
                             Each is a table with its own endpoints and its own
                             saving, so the editor cannot build them - it shows
                             the one whose entry was chosen in the sidebar
                             (data-content-kind="panel") and hides its own form
                             while it does. See showPanel() in systemContents.js.

                             Project Types is the catalogue of work the company
                             does, and the one list serves two screens - it is
                             what a project may be, and what a technician may be
                             qualified for. --}}
                        <div data-content-extra="project_types" hidden>
                            <div class="project-types-panel" id="projectTypesPane">

                                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                                        <div>
                                            <h5 class="fw-bold mb-1 project-types-heading">
                                                <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>
                                                Project Types
                                            </h5>
                                            <p class="text-secondary small mb-0">
                                                Project types double as technician specialties.
                                            </p>
                                        </div>
                                    </div>

                                    <form class="row g-2 align-items-end mb-3 project-types-add" data-project-type-add-form
                                        novalidate>
                                        <div class="col-sm">
                                            <label class="form-label small fw-semibold mb-1" for="projectTypeName">
                                                Add a project type
                                            </label>
                                            <input type="text" class="form-control" id="projectTypeName"
                                                placeholder="e.g. Heating Installation" maxlength="255"
                                                data-project-type-name required>
                                        </div>
                                        <div class="col-sm-auto">
                                            <button type="submit" class="btn btn-primary px-4" data-project-type-add>
                                                <span class="spinner-border spinner-border-sm me-1 d-none" role="status"
                                                    aria-hidden="true" data-project-type-add-spinner></span>
                                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                                                Add
                                            </button>
                                        </div>
                                    </form>

                                    <div class="text-secondary small py-3 px-1" data-project-type-loading>
                                        <span class="spinner-border spinner-border-sm me-2" role="status"
                                            aria-hidden="true"></span>
                                        Loading project types&hellip;
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0 project-types-table">
                                            <thead>
                                                <tr>
                                                    <th>Project Type</th>
                                                    <th>Projects</th>
                                                    <th>Technicians</th>
                                                    <th class="text-center">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody data-project-type-body></tbody>
                                        </table>
                                    </div>

                                    <div class="schedule-empty-state mt-2 d-none" data-project-type-empty>
                                        No project types yet. Add the first one above.
                                    </div>

                                    <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-project-type-error></div>
                                    <div class="alert alert-success mt-3 mb-0 d-none" role="alert" data-project-type-success></div>
                            </div>
                        </div>

                        <div data-content-extra="phase_templates" hidden>
                            {{-- Default Phases & Tasks.
                                 =====================================

                                 Two editors, because a project can be more
                                 than one type at once and that is the whole
                                 problem this solves.

                                 The STAGES below are shared vocabulary: one
                                 "Installation", in one agreed order, that every
                                 project type refers to. That is what lets a
                                 project which is both Aircon and Electrical get
                                 ONE Installation phase carrying both trades'
                                 work, instead of two phases with the same name
                                 in whichever order they happened to be read.

                                 The TASKS are per project type, because that is
                                 the half that genuinely differs - what Aircon
                                 does during Installation is not what Electrical
                                 does.

                                 Neither of them reaches a project that has
                                 already been set up. A template is copied onto a
                                 project once, on its phase setup screen, and is
                                 fully editable there. --}}
                            <div class="phase-template-panel" id="phaseTemplatesPane">

                                <div class="phase-template-header">
                                    <div>
                                        <h5 class="phase-template-title mb-1">
                                            <i class="bi bi-list-check" aria-hidden="true"></i>
                                            Default Phases &amp; Tasks
                                        </h5>
                                        <p class="text-secondary small mb-0">
                                            What each kind of job starts with when a new project is set up.
                                        </p>
                                    </div>

                                    <span class="phase-template-safe-badge">
                                        <i class="bi bi-shield-check" aria-hidden="true"></i>
                                        Projects already set up are never changed
                                    </span>
                                </div>

                                {{-- The one idea nobody guesses from the controls:
                                     stages are shared so that a project which is
                                     two types at once gets ONE phase per stage
                                     carrying both types' work. Shown as a worked
                                     example, because the sentence alone has never
                                     been enough. --}}
                                <div class="phase-template-explainer">
                                    <div class="phase-explainer-copy">
                                        <h6 class="mb-1">
                                            <i class="bi bi-info-circle-fill me-1" aria-hidden="true"></i>
                                            Why there are two lists
                                        </h6>
                                        <p class="mb-0 small">
                                            A project can be more than one type at once. <strong>Stages</strong>
                                            are shared, so two types that both have a Site Preparation produce
                                            <em>one</em> phase &mdash; carrying both of their tasks.
                                            <strong>Tasks</strong> belong to a type, because that is the part
                                            that actually differs.
                                        </p>
                                    </div>

                                    <div class="phase-explainer-demo" aria-hidden="true">
                                        <div class="phase-demo-side">
                                            <span class="phase-demo-chip phase-demo-chip-a">Aircon</span>
                                            <span class="phase-demo-line">Site Preparation</span>
                                            <span class="phase-demo-task">Mark unit positions</span>
                                        </div>
                                        <div class="phase-demo-plus">+</div>
                                        <div class="phase-demo-side">
                                            <span class="phase-demo-chip phase-demo-chip-b">Ducting</span>
                                            <span class="phase-demo-line">Site Preparation</span>
                                            <span class="phase-demo-task">Survey duct routes</span>
                                        </div>
                                        <div class="phase-demo-arrow"><i class="bi bi-arrow-right"></i></div>
                                        <div class="phase-demo-side phase-demo-result">
                                            <span class="phase-demo-line">Phase 1 &mdash; Site Preparation</span>
                                            <span class="phase-demo-task">Mark unit positions</span>
                                            <span class="phase-demo-task">Survey duct routes</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-secondary small py-3 px-1" data-phase-template-loading>
                                    <span class="spinner-border spinner-border-sm me-2" role="status"
                                        aria-hidden="true"></span>
                                    Loading default phases&hellip;
                                </div>

                                <div class="alert alert-danger mb-3 d-none" role="alert" data-phase-template-error></div>
                                <div class="alert alert-success mb-3 d-none" role="alert" data-phase-template-success></div>

                                <div class="phase-template-grid d-none" data-phase-template-body>

                                    {{-- Step 1: the shared vocabulary. --}}
                                    <section class="phase-template-col phase-template-col-stages">
                                        <div class="phase-step-head">
                                            <span class="phase-step-number">1</span>
                                            <div>
                                                <h6 class="mb-0">Phase Stages</h6>
                                                <p class="phase-step-sub mb-0">
                                                    Shared by every project type, in the order jobs run.
                                                </p>
                                            </div>
                                        </div>

                                        <p class="phase-step-note">
                                            <i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>
                                            Changes here save straight away.
                                        </p>

                                        <button type="button" class="phase-add-toggle" data-stage-add-toggle
                                            aria-expanded="false">
                                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                            Add a stage
                                        </button>

                                        <form class="phase-add-form d-none" data-stage-add-form novalidate>
                                            <label class="form-label small fw-semibold mb-1" for="stageName">
                                                Stage name
                                            </label>
                                            <input type="text" class="form-control form-control-sm mb-2"
                                                id="stageName" maxlength="150"
                                                placeholder="e.g. Rough-In / Wiring" data-stage-name required>

                                            <label class="form-label small fw-semibold mb-1" for="stageDescription">
                                                Description
                                            </label>
                                            <textarea class="form-control form-control-sm mb-2" id="stageDescription"
                                                rows="2" maxlength="500"
                                                placeholder="What happens during this stage"
                                                data-stage-description required></textarea>

                                            <div class="d-flex gap-2">
                                                <button type="submit" class="btn btn-primary btn-sm px-3"
                                                    data-stage-add>
                                                    <span class="spinner-border spinner-border-sm me-1 d-none"
                                                        role="status" aria-hidden="true"
                                                        data-stage-add-spinner></span>
                                                    Add Stage
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm px-3"
                                                    data-stage-add-cancel>
                                                    Cancel
                                                </button>
                                            </div>
                                        </form>

                                        <ol class="phase-stage-list" data-stage-list></ol>

                                        <div class="phase-empty-state d-none" data-stage-empty>
                                            <i class="bi bi-layers" aria-hidden="true"></i>
                                            <p class="mb-0">No stages yet. Add the first one above.</p>
                                        </div>
                                    </section>

                                    {{-- Step 2: one type's template. --}}
                                    <section class="phase-template-col phase-template-col-types">
                                        <div class="phase-step-head">
                                            <span class="phase-step-number">2</span>
                                            <div>
                                                <h6 class="mb-0">Default work per project type</h6>
                                                <p class="phase-step-sub mb-0">
                                                    Pick a type, tick the stages its work goes through, then list
                                                    what it starts with.
                                                </p>
                                            </div>
                                        </div>

                                        {{-- A row of cards rather than a dropdown: which type is being
                                             edited is the thing most worth never having to check, and the
                                             ones nobody has written yet are visible without opening
                                             anything. --}}
                                        <div class="phase-type-picker" role="tablist"
                                            aria-label="Project type" data-phase-template-types></div>

                                        <div data-phase-template-stages></div>

                                        <div class="phase-empty-state d-none" data-phase-template-empty>
                                            <i class="bi bi-layers" aria-hidden="true"></i>
                                            <p class="mb-0">Add a phase stage in step 1 before writing a template.</p>
                                        </div>

                                        {{-- Unlike step 1, nothing here is written until this bar is used.
                                             It stays put at the bottom of the panel so a long stage list
                                             cannot scroll the Save button out of reach. --}}
                                        <div class="phase-save-bar" data-phase-template-savebar>
                                            <span class="phase-save-state" data-phase-template-state>
                                                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                                                All changes saved
                                            </span>

                                            <div class="d-flex gap-2">
                                                <button type="button" class="btn btn-outline-secondary btn-sm px-3"
                                                    data-phase-template-discard disabled>
                                                    Discard
                                                </button>
                                                <button type="button" class="btn btn-primary btn-sm px-4"
                                                    data-phase-template-save disabled>
                                                    <span class="spinner-border spinner-border-sm me-1 d-none"
                                                        role="status" aria-hidden="true"
                                                        data-phase-template-save-spinner></span>
                                                    Save
                                                </button>
                                            </div>
                                        </div>
                                    </section>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                </div>

                {{-- ---------------- System Contents ----------------
                     Everything the public website shows. It lives inside System
                     Settings because the public website belongs to the Super
                     Admin alone.

                     Each content type wears an accent colour of its own - on
                     its entry in the sidebar, the panel's top border, its
                     heading and its tint - so which part of the website is being
                     edited is never a guess. The colours only tell the types
                     apart; none of them means anything about state. They come
                     from data-settings-accent, which systemContents.js keeps in
                     step with the chosen type - see configuration.css. --}}
                <div class="tab-pane fade" id="systemContentsPane" role="region"
                    aria-labelledby="systemContentsTab">
                <div class="card shadow-sm border-0 rounded-2" data-content-editor
                    data-content-nav="systemContentsTypes" data-content-accents
                    data-settings-accent="{{ array_key_first($contentSections) }}">
                    <div class="card-body p-4">

                        <div class="settings-panel-head">
                            <div>
                                <h5 class="fw-bold mb-1">
                                    <i class="bi bi-globe me-1 text-primary" aria-hidden="true"></i>
                                    System Contents
                                </h5>
                                <p class="text-secondary small mb-0">
                                    Everything the public website shows. Saved changes go live immediately.
                                </p>
                            </div>

                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('landing.home') }}"
                                target="_blank" rel="noopener">
                                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                                View website
                            </a>
                        </div>

                        @include('super-admin.partials.settings-editor-form', ['loadingLabel' => 'Loading content'])

                    </div>
                </div>
                </div>

                </div>{{-- /.settings-main --}}
            </div>{{-- /.settings-layout --}}
        </div>
        @endif

        {{-- ==================== TAB 4: INQUIRIES ==================== --}}
        {{-- Messages written in from the public Contact page. Admin and Super
             Admin both work this list; only the archive is the Super Admin's,
             exactly as archived accounts are. --}}
        <div class="tab-pane fade" id="inquiriesPane" role="tabpanel" aria-labelledby="inquiriesTab">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0">Inquiries</h5>
                    <span class="text-secondary small">
                        Messages from the public Contact page.
                    </span>
                </div>

                @if ($isSuperAdmin)
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal"
                        data-bs-target="#archivedInquiriesModal">
                        <i class="bi bi-archive me-1" aria-hidden="true"></i>
                        View Archived Inquiries
                    </button>
                @endif
            </div>

            <div class="card shadow-sm border-0 rounded-2">
                <div class="card-body p-3">
                    <div class="config-table-header">
                        <div>
                            <h6 class="config-table-title mb-0">
                                <i class="bi bi-envelope-paper me-1" aria-hidden="true"></i>
                                Received Messages
                            </h6>
                            <span class="text-secondary small" data-inquiry-count></span>
                        </div>

                        <div class="config-table-controls">
                            <input type="search" class="form-control form-control-sm config-search"
                                placeholder="Search inquiry ID, name, email or subject&hellip;"
                                aria-label="Search inquiries" data-inquiry-search>

                            <select class="form-select form-select-sm config-filter" aria-label="Filter by status"
                                data-inquiry-status>
                                <option value="all">All Statuses</option>
                                {{-- Not a status a message can hold: the two
                                     unfinished ones together, which is what the
                                     dashboard counts as pending. --}}
                                <option value="{{ \App\Models\Inquiry::FILTER_PENDING }}">Pending</option>
                                @foreach ($inquiryStatuses as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-danger d-none" role="alert" data-inquiry-error></div>
                    <div class="alert alert-success d-none" role="alert" data-inquiry-success></div>

                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-info">
                                <tr>
                                    <th>Inquiry ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                    {{-- Newest first by default; the button flips
                                         it, and works from the keyboard as the
                                         Activity Logs headings do. --}}
                                    <th>
                                        <button type="button" class="config-sort is-active" data-inquiry-sort>
                                            Date Submitted
                                            <i class="bi bi-caret-down-fill" aria-hidden="true"></i>
                                        </button>
                                    </th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody data-inquiry-body></tbody>
                        </table>
                    </div>

                    <div class="text-secondary small py-3 px-1" data-inquiry-loading>
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Loading inquiries&hellip;
                    </div>

                    <div class="schedule-empty-state mt-2 d-none" data-inquiry-empty>
                        No inquiries match these filters.
                    </div>

                    <nav class="config-pagination d-none" aria-label="Inquiry pages" data-inquiry-pagination></nav>
                </div>
            </div>
        </div>
    </div>

    {{-- ==================== ADD / EDIT USER MODAL ==================== --}}
    <div class="modal fade" id="userFormModal" tabindex="-1" aria-hidden="true" data-user-modal>
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form data-user-form novalidate>
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-person-plus me-2" aria-hidden="true"></i>
                            <span data-user-modal-title>Add New User</span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        {{-- Step 1: account type. Hidden while editing, because an
                             account never changes between employee and Registered
                             User. The submitted value stays `client`: it is the
                             stored role, and only its label has changed. --}}
                        <div class="mb-4" data-account-type-step>
                            <label class="form-label fw-semibold">Account Type</label>
                            <div class="config-type-choices">
                                <label class="config-type-choice">
                                    <input type="radio" name="account_type" value="employee" class="visually-hidden"
                                        data-account-type>
                                    <i class="bi bi-person-badge" aria-hidden="true"></i>
                                    <strong>Employee</strong>
                                    <span>Admin, technician or lead technician</span>
                                </label>

                                <label class="config-type-choice">
                                    <input type="radio" name="account_type" value="client" class="visually-hidden"
                                        data-account-type>
                                    <i class="bi bi-person-check" aria-hidden="true"></i>
                                    <strong>Registered User</strong>
                                    <span>A public website account for a company or homeowner</span>
                                </label>
                            </div>
                        </div>

                        {{-- Everything below appears only once a type is chosen. --}}
                        <div class="d-none" data-user-fields>

                            <div class="config-section-heading">
                                <i class="bi bi-person-vcard me-1" aria-hidden="true"></i>
                                Personal Information
                            </div>

                            {{-- Employee name fields --}}
                            <div class="row g-3 mb-3" data-employee-only>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="userFirstName">
                                        First Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" id="userFirstName" class="form-control" name="first_name"
                                        maxlength="100" autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="userMiddleName">
                                        Middle Initial
                                    </label>
                                    {{-- One letter, which is what an initial is. The
                                         box stops at one and the server refuses
                                         anything else - see PersonName. --}}
                                    <input type="text" id="userMiddleName" class="form-control text-center"
                                        name="middle_name" maxlength="1" pattern="[A-Za-z]" placeholder="Optional"
                                        autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="userLastName">
                                        Last Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" id="userLastName" class="form-control" name="last_name"
                                        maxlength="100" autocomplete="off">
                                </div>
                            </div>

                            {{-- Registered User name --}}
                            <div class="row g-3 mb-3 d-none" data-client-only>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1" for="userFullName">
                                        Full Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" id="userFullName" class="form-control" name="full_name"
                                        maxlength="255" autocomplete="off">
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="userContactNumber">
                                        Contact Number <span class="text-danger">*</span>
                                    </label>
                                    {{-- Eleven digits and nothing else, the same
                                         rule User::CONTACT_NUMBER_RULE applies on
                                         the server. registerForm.js strips
                                         anything that is not a digit. --}}
                                    <input type="text" id="userContactNumber" class="form-control"
                                        name="contact_number" inputmode="numeric" data-digits-only
                                        maxlength="{{ \App\Models\User::CONTACT_NUMBER_LENGTH }}"
                                        placeholder="09171234567" autocomplete="off"
                                        aria-describedby="userContactNumberHelp">
                                    <div class="form-text" id="userContactNumberHelp">11 digits, numbers only.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="userBirthdate">
                                        Date of Birth <span class="text-danger">*</span>
                                    </label>
                                    {{-- The picker's bounds are the rule the
                                         server applies, so an under-age date
                                         is refused before the dialog is even
                                         submitted. --}}
                                    <input type="date" id="userBirthdate" class="form-control" name="birthdate"
                                        min="{{ \App\Support\AccountAge::earliestAllowed() }}"
                                        max="{{ \App\Support\AccountAge::latestAllowed() }}" autocomplete="off">
                                    <div class="form-text">Must be at least 18 years old.</div>
                                </div>
                                <div class="col-md-4" data-email-field>
                                    <label class="form-label small fw-semibold mb-1" for="userEmail">
                                        Email Address <span class="text-danger">*</span>
                                    </label>
                                    <input type="email" id="userEmail" class="form-control" name="email"
                                        maxlength="255" autocomplete="off">
                                    <div class="form-text d-none" data-email-locked-note>
                                        This is the Registered User's sign-in address.
                                    </div>
                                </div>
                            </div>

                            {{-- No profile picture field. An internal account
                                 starts on the default avatar and its owner sets
                                 their own picture from their Profile page;
                                 Registered Users never have one at all. --}}

                            {{-- ---------------- Employment ---------------- --}}
                            <div data-employee-only>
                                <div class="config-section-heading">
                                    <i class="bi bi-briefcase me-1" aria-hidden="true"></i>
                                    Employment Information
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold mb-1" for="userRole">
                                            Role <span class="text-danger">*</span>
                                        </label>
                                        <select id="userRole" class="form-select" name="role" data-role-select>
                                            <option value="">Select a role&hellip;</option>
                                            @foreach ($assignableRoles as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <div class="form-text">Sets what this account may do across the system.</div>
                                    </div>
                                </div>

                                {{-- Only for the two technician roles. --}}
                                <div class="d-none" data-specialties-section>
                                    <div class="config-section-heading">
                                        <i class="bi bi-tools me-1" aria-hidden="true"></i>
                                        Specialties <span class="text-danger">*</span>
                                    </div>

                                    <div class="row g-2 align-items-end mb-2">
                                        <div class="col-md-8">
                                            <label class="form-label small fw-semibold mb-1" for="userSpecialty">
                                                Add a specialty
                                            </label>
                                            <select id="userSpecialty" class="form-select" data-specialty-select>
                                                <option value="">Select a specialty&hellip;</option>
                                                @foreach ($skills as $skill)
                                                    <option value="{{ $skill->skill_id }}">{{ $skill->skill_name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <button type="button" class="btn btn-outline-primary w-100"
                                                data-specialty-add>
                                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                                                Add
                                            </button>
                                        </div>
                                    </div>

                                    <div class="config-chip-list" data-specialty-list></div>

                                    <div class="text-muted small d-none" data-specialty-empty>
                                        No specialties assigned yet.
                                    </div>
                                </div>
                            </div>

                            {{-- ---------------- Account ---------------- --}}
                            <div class="config-section-heading">
                                <i class="bi bi-key me-1" aria-hidden="true"></i>
                                Account Information
                            </div>

                            <div class="row g-3" data-account-info>
                                <div class="col-12" data-password-block>
                                    <label class="form-label small fw-semibold mb-1" for="userTempPassword">
                                        Temporary Password
                                    </label>
                                    <div class="input-group">
                                        <input type="text" id="userTempPassword" class="form-control config-password"
                                            name="password" minlength="{{ \App\Support\PasswordPolicy::MIN_LENGTH }}"
                                            maxlength="{{ \App\Support\PasswordPolicy::MAX_LENGTH }}" autocomplete="off"
                                            data-password-display>
                                        <button type="button" class="btn btn-outline-secondary" data-password-copy
                                            title="Copy password">
                                            <i class="bi bi-clipboard" aria-hidden="true"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary"
                                            data-password-regenerate title="Generate a new password">
                                            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">
                                        A new password is required at first sign-in.
                                        @if ($mailEnabled)
                                            A copy is emailed to them automatically.
                                        @else
                                            Email delivery is not configured, so hand this over directly.
                                        @endif
                                    </div>

                                    {{-- A password typed over the generated one is held
                                         to the same policy as every other. --}}
                                    <x-password-requirements for="userTempPassword" class="mt-2" />
                                </div>
                            </div>

                            {{-- Read-only history, shown while editing only. --}}
                            <div class="row g-3 mt-1 d-none" data-account-history>
                                <div class="col-md-4">
                                    <div class="technician-field-label">Registration Date</div>
                                    <div class="technician-field-value" data-history-registered>&mdash;</div>
                                </div>
                                <div class="col-md-4">
                                    <div class="technician-field-label">Created By</div>
                                    <div class="technician-field-value" data-history-creator>&mdash;</div>
                                </div>
                                <div class="col-md-4">
                                    <div class="technician-field-label">Last Login</div>
                                    <div class="technician-field-value" data-history-login>&mdash;</div>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-user-form-error></div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" data-user-submit disabled>
                            <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                                data-user-spinner></span>
                            <span data-user-submit-label>Create Account</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ==================== CREDENTIALS RESULT MODAL ==================== --}}
    <div class="modal fade" id="credentialsModal" tabindex="-1" aria-hidden="true" data-credentials-modal>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-check-circle me-2" aria-hidden="true"></i>
                        <span data-credentials-title>Account Created</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-3" data-credentials-intro></p>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">User ID</label>
                        <input type="text" class="form-control" readonly data-credentials-code>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Email</label>
                        <input type="text" class="form-control" readonly data-credentials-email>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Temporary Password</label>
                        <div class="input-group">
                            <input type="text" class="form-control config-password" readonly
                                data-credentials-password>
                            <button type="button" class="btn btn-outline-secondary" data-credentials-copy
                                title="Copy password">
                                <i class="bi bi-clipboard" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="alert alert-warning mb-0 small" data-credentials-note></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ==================== ARCHIVED ACCOUNTS MODAL ==================== --}}
    @if ($isSuperAdmin)
        <div class="modal fade" id="archivedAccountsModal" tabindex="-1" aria-hidden="true"
            aria-labelledby="archivedAccountsModalLabel" data-archived-modal>
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="archivedAccountsModalLabel">Archived Accounts</h5>
                            <p class="text-secondary small mb-0">
                                Nothing was deleted. Restoring brings an account back as it was.
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="config-table-header">
                            <div>
                                <h6 class="config-table-title mb-0">
                                    <i class="bi bi-archive me-1" aria-hidden="true"></i>
                                    Archive
                                </h6>
                                <span class="text-secondary small" data-archived-count></span>
                            </div>

                            <div class="config-table-controls">
                                <input type="search" class="form-control form-control-sm config-search"
                                    placeholder="Search user ID, name or email&hellip;"
                                    aria-label="Search archived accounts" data-archived-search>

                                <select class="form-select form-select-sm config-filter" aria-label="Filter by role"
                                    data-archived-role>
                                    <option value="all">All Roles</option>
                                    @foreach ($logRoles as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead class="table-info">
                                    <tr>
                                        <th>User ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Archived Date</th>
                                        <th>Archived By</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody data-archived-body></tbody>
                            </table>
                        </div>

                        <div class="text-secondary small py-3 px-1 d-none" data-archived-loading>
                            <span class="spinner-border spinner-border-sm me-2" role="status"
                                aria-hidden="true"></span>
                            Loading archived accounts&hellip;
                        </div>

                        <div class="schedule-empty-state mt-2 d-none" data-archived-empty>
                            No archived accounts.
                        </div>

                        <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-archived-error></div>

                        <nav class="config-pagination d-none" aria-label="Archived account pages"
                            data-archived-pagination></nav>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>

                </div>
            </div>
        </div>
    @endif

    {{-- ============ REGISTERED USER PROJECTS MODAL ============
         The other direction of the link the project details page edits: which
         projects one Registered User account is connected to. Read-only, and
         every value is written in by script with textContent rather than as
         markup - see the inquiry dialog below, which is written the same way
         and for the same reason. --}}
    <div class="modal fade" id="registeredUserProjectsModal" tabindex="-1" aria-hidden="true"
        aria-labelledby="registeredUserProjectsModalLabel" data-user-projects-modal>
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="registeredUserProjectsModalLabel">Projects</h5>
                        <p class="text-secondary small mb-0" data-user-projects-subtitle></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-info">
                                <tr>
                                    <th>Project ID</th>
                                    <th>Reference No.</th>
                                    <th>Project</th>
                                    <th>Project Type</th>
                                    <th>Schedule</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody data-user-projects-body></tbody>
                        </table>
                    </div>

                    <div class="text-secondary small py-3 px-1 d-none" data-user-projects-loading>
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Loading projects&hellip;
                    </div>

                    <div class="schedule-empty-state mt-2 d-none" data-user-projects-empty>
                        No Projects Assigned
                    </div>

                    <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-user-projects-error></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>

            </div>
        </div>
    </div>

    {{-- ==================== INQUIRY DETAILS MODAL ==================== --}}
    {{-- Every value is written in by script with textContent, never as markup:
         the whole record is a stranger's typing, and none of it may become an
         element on an administrator's screen. --}}
    <div class="modal fade" id="inquiryDetailsModal" tabindex="-1" aria-hidden="true"
        aria-labelledby="inquiryDetailsModalLabel" data-inquiry-modal>
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="inquiryDetailsModalLabel">
                        <i class="bi bi-envelope-open me-2" aria-hidden="true"></i>
                        Inquiry <span data-inquiry-detail-code></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">

                    <div class="text-secondary small py-3 px-1 d-none" data-inquiry-detail-loading>
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Loading this inquiry&hellip;
                    </div>

                    <div class="d-none" data-inquiry-detail-body>

                        <div class="config-section-heading">
                            <i class="bi bi-person-lines-fill me-1" aria-hidden="true"></i>
                            Who wrote in
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold mb-1">Name</label>
                                <p class="mb-0" data-inquiry-detail-name></p>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold mb-1">Email</label>
                                <p class="mb-0" data-inquiry-detail-email></p>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold mb-1">Date Submitted</label>
                                <p class="mb-0" data-inquiry-detail-submitted></p>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold mb-1">Date Updated</label>
                                <p class="mb-0" data-inquiry-detail-updated></p>
                            </div>
                        </div>

                        <div class="config-section-heading">
                            <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>
                            Their message
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-semibold mb-1">Subject</label>
                            <p class="fw-semibold mb-2" data-inquiry-detail-subject></p>
                        </div>

                        {{-- Read only, always. The message is the record; it is
                             never editable from this side. --}}
                        <div class="config-inquiry-message" data-inquiry-detail-message></div>

                        {{-- The reply, when one has been sent. It sits with the
                             message it answers rather than on a page of its own. --}}
                        <div class="d-none" data-inquiry-reply-record>
                            <div class="config-section-heading mt-4">
                                <i class="bi bi-reply me-1" aria-hidden="true"></i>
                                Our reply
                            </div>

                            <p class="text-secondary small mb-2">
                                Sent <span data-inquiry-replied-at></span> by <span data-inquiry-replied-by></span>.
                            </p>

                            {{-- Named apart from the reply box below it. Two
                                 elements sharing one hook is how the Send
                                 button came to read an empty message: the
                                 script found this block first and asked a
                                 <div> for its value. --}}
                            <div class="config-inquiry-message config-inquiry-reply" data-inquiry-reply-text></div>
                        </div>

                        <div class="config-section-heading mt-4">
                            <i class="bi bi-flag me-1" aria-hidden="true"></i>
                            Status
                        </div>

                        <div class="row g-2 align-items-end mb-2">
                            <div class="col-sm">
                                <label class="form-label small fw-semibold mb-1" for="inquiryStatusSelect">
                                    Current status
                                </label>
                                <select class="form-select" id="inquiryStatusSelect" data-inquiry-status-select>
                                    @foreach ($inquiryStatuses as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-auto">
                                <button type="button" class="btn btn-outline-primary px-4" data-inquiry-status-save>
                                    <span class="spinner-border spinner-border-sm me-1 d-none" role="status"
                                        aria-hidden="true" data-inquiry-status-spinner></span>
                                    Update Status
                                </button>
                            </div>
                        </div>

                        <p class="text-secondary small mb-0">
                            Sending a reply sets the status to Responded.
                        </p>

                        {{-- Reply form. The recipient is shown but never typed:
                             it is the address on the inquiry, and the server
                             reads it from there rather than from this field. --}}
                        <div data-inquiry-reply-form>
                            <div class="config-section-heading mt-4">
                                <i class="bi bi-send me-1" aria-hidden="true"></i>
                                Reply
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold mb-1" for="inquiryReplyTo">To</label>
                                <input type="email" class="form-control" id="inquiryReplyTo" readonly
                                    data-inquiry-reply-to>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold mb-1" for="inquiryReplyMessage">
                                    Message <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control" id="inquiryReplyMessage" rows="6"
                                    maxlength="{{ \App\Models\Inquiry::MAX_REPLY }}"
                                    placeholder="Write the reply that will be emailed to them&hellip;"
                                    data-inquiry-reply-message></textarea>
                            </div>

                            <button type="button" class="btn btn-primary px-4" data-inquiry-reply-send>
                                <span class="spinner-border spinner-border-sm me-1 d-none" role="status"
                                    aria-hidden="true" data-inquiry-reply-spinner></span>
                                <i class="bi bi-send me-1" aria-hidden="true"></i>
                                Send Reply
                            </button>
                        </div>

                        {{-- An archived inquiry is read-only until it is put
                             back on the active list. --}}
                        <div class="alert alert-secondary mt-4 mb-0 d-none" data-inquiry-archived-note>
                            <i class="bi bi-archive me-1" aria-hidden="true"></i>
                            This inquiry is archived. Restore it to change its status or reply.
                        </div>
                    </div>

                    <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-inquiry-detail-error></div>
                    <div class="alert alert-success mt-3 mb-0 d-none" role="alert" data-inquiry-detail-success></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger me-auto d-none" data-inquiry-archive>
                        <i class="bi bi-archive me-1" aria-hidden="true"></i>
                        Archive Inquiry
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>

            </div>
        </div>
    </div>

    {{-- ==================== ARCHIVED INQUIRIES MODAL ==================== --}}
    @if ($isSuperAdmin)
        <div class="modal fade" id="archivedInquiriesModal" tabindex="-1" aria-hidden="true"
            aria-labelledby="archivedInquiriesModalLabel" data-archived-inquiry-modal>
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="archivedInquiriesModalLabel">Archived Inquiries</h5>
                            <p class="text-secondary small mb-0">
                                Nothing was deleted. Restoring puts an inquiry back on the active list.
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="config-table-header">
                            <div>
                                <h6 class="config-table-title mb-0">
                                    <i class="bi bi-archive me-1" aria-hidden="true"></i>
                                    Archive
                                </h6>
                                <span class="text-secondary small" data-archived-inquiry-count></span>
                            </div>

                            <div class="config-table-controls">
                                <input type="search" class="form-control form-control-sm config-search"
                                    placeholder="Search inquiry ID, name, email or subject&hellip;"
                                    aria-label="Search archived inquiries" data-archived-inquiry-search>

                                <select class="form-select form-select-sm config-filter"
                                    aria-label="Filter by status" data-archived-inquiry-status>
                                    <option value="all">All Statuses</option>
                                    @foreach ($inquiryStatuses as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0">
                                <thead class="table-info">
                                    <tr>
                                        <th>Inquiry ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Subject</th>
                                        <th>Status</th>
                                        <th>Date Submitted</th>
                                        <th>Archived Date</th>
                                        <th>Archived By</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody data-archived-inquiry-body></tbody>
                            </table>
                        </div>

                        <div class="text-secondary small py-3 px-1 d-none" data-archived-inquiry-loading>
                            <span class="spinner-border spinner-border-sm me-2" role="status"
                                aria-hidden="true"></span>
                            Loading archived inquiries&hellip;
                        </div>

                        <div class="schedule-empty-state mt-2 d-none" data-archived-inquiry-empty>
                            No archived inquiries.
                        </div>

                        <div class="alert alert-danger mt-3 mb-0 d-none" role="alert"
                            data-archived-inquiry-error></div>

                        <nav class="config-pagination d-none" aria-label="Archived inquiry pages"
                            data-archived-inquiry-pagination></nav>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>

                </div>
            </div>
        </div>
    @endif

    {{-- ==================== CONFIRMATION MODAL ==================== --}}
    <div class="modal fade" id="confirmActionModal" tabindex="-1" aria-hidden="true" data-confirm-modal>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" data-confirm-title>Confirm</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-0" data-confirm-body></p>
                    <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-confirm-error></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" data-confirm-submit>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-confirm-spinner></span>
                        <span data-confirm-label>Confirm</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        {{-- Keeps the contact number field to digits only. --}}
        <script src="/js/registerForm.js"></script>
        {{-- The temporary password's requirements checklist, and the guard
             that stops the dialog submitting one that fails it. Ahead of
             configuration.js so its guard sees the submit first. --}}
        <script src="/js/passwordField.js"></script>
        <script>
            // Arriving on #clients from the dashboard's quick action. The table
            // is filled by a request, so the browser's own jump happens before
            // the card has its height - this repeats it once the page settles.
            document.addEventListener('DOMContentLoaded', function () {
                if (window.location.hash !== '#clients') {
                    return;
                }

                const target = document.getElementById('clients');

                if (target) {
                    window.setTimeout(function () {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 400);
                }
            });
        </script>
        <script>
            window.configurationRoutes = {
                employees: @json(route('super-admin.configuration.users.employees')),
                clients: @json(route('super-admin.configuration.users.clients')),
                generatePassword: @json(route('super-admin.configuration.users.password')),
                storeEmployee: @json(route('super-admin.configuration.users.employees.store')),
                storeClient: @json(route('super-admin.configuration.users.clients.store')),
                userBase: @json(url('super-admin/configuration/users')),
                activityLogs: @json(route('super-admin.configuration.activity-logs')),
                contentBase: @json(url('super-admin/configuration/contents')),
                inquiries: @json(route('super-admin.configuration.inquiries.index')),
                inquiryBase: @json(url('super-admin/configuration/inquiries')),
                @if ($isSuperAdmin)
                    archivedAccounts: @json(route('super-admin.configuration.users.archived')),
                    projectTypes: @json(route('super-admin.configuration.project-types.index')),
                    phaseTemplates: @json(route('super-admin.configuration.phase-templates.index')),
                    phaseTemplateBase: @json(url('super-admin/configuration/phase-templates')),
                    archivedInquiries: @json(route('super-admin.configuration.inquiries.archived')),
                @endif
            };
            window.configurationOptions = {
                technicianRoles: @json($technicianRoles),
                mailEnabled: @json($mailEnabled),
                // The row actions are drawn in JavaScript, so whether an
                // account may be archived has to reach it as data.
                canArchive: @json($isSuperAdmin),
                // Restoring an inquiry is the Super Admin's, exactly as
                // restoring an account is. Archiving one is not - handling a
                // message is ordinary work an Admin does.
                canRestoreInquiries: @json($isSuperAdmin),
                // Opened straight from a notification: ?inquiry=7 on the URL
                // shows the tab with that message already open.
                openInquiry: @json(request()->integer('inquiry') ?: null),
                // The dashboard's Pending Inquiries figure, which opens this
                // tab narrowed to the messages still waiting on somebody.
                openInquiries: @json(request()->query('inquiries') === \App\Models\Inquiry::FILTER_PENDING
                    ? \App\Models\Inquiry::FILTER_PENDING
                    : null),
                // Who the Export Logs dialog's user search may find. Already
                // narrowed server-side to the accounts this administrator may
                // read entries for, so the search itself needs no rules of its
                // own - see ConfigurationController::index().
                logActors: @json($logActors),
            };
        </script>
        <script src="/js/super-admin/configuration.js"></script>
        <script src="/js/super-admin/inquiries.js"></script>
        @if (auth()->user()?->isSuperAdmin())
            <script src="/js/super-admin/systemContents.js"></script>
            <script src="/js/super-admin/projectTypes.js"></script>
            <script src="/js/super-admin/phaseTemplates.js"></script>
            <script src="/js/super-admin/systemSettingsNav.js"></script>
        @endif
    @endpush
@endsection
