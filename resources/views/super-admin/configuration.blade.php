@extends('layouts.superadminNav')

@section('title', 'Configuration')

@push('styles')
    <link href="/css/super-admin/projects.css" rel="stylesheet">
    <link href="/css/super-admin/technicians.css" rel="stylesheet">
    <link href="/css/super-admin/configuration.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Configuration</h4>
            <p class="text-secondary small mb-0">Accounts, audit history and system-wide settings.</p>
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
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="systemSettingsTab" data-bs-toggle="tab" data-bs-target="#systemSettingsPane"
                type="button" role="tab" aria-controls="systemSettingsPane" aria-selected="false">
                <i class="bi bi-gear me-1" aria-hidden="true"></i>
                System Settings
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
                    <span class="text-secondary small">Every employee and client account in the system.</span>
                </div>

                <button type="button" class="btn btn-primary" data-add-user-open>
                    <i class="bi bi-person-plus me-1" aria-hidden="true"></i>
                    Add New User
                </button>
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

            {{-- ---------------- Clients ---------------- --}}
            <div class="card shadow-sm border-0 rounded-2">
                <div class="card-body p-3">
                    <div class="config-table-header">
                        <div>
                            <h6 class="config-table-title mb-0">
                                <i class="bi bi-building me-1" aria-hidden="true"></i>
                                Clients
                            </h6>
                            <span class="text-secondary small" data-client-count></span>
                        </div>

                        <div class="config-table-controls">
                            <input type="search" class="form-control form-control-sm config-search"
                                placeholder="Search user ID, name, company or email&hellip;" aria-label="Search clients"
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
                                    <th>Company Name</th>
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
                        Loading clients&hellip;
                    </div>

                    <div class="schedule-empty-state mt-2 d-none" data-client-empty>
                        No client accounts match these filters.
                    </div>

                    <nav class="config-pagination d-none" aria-label="Client pages" data-client-pagination></nav>
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
        </div>

        {{-- ==================== TAB 3: SYSTEM SETTINGS ==================== --}}
        <div class="tab-pane fade" id="systemSettingsPane" role="tabpanel" aria-labelledby="systemSettingsTab">

            @if (auth()->user()?->isSuperAdmin())
                {{-- System Contents lives inside System Settings. The public
                     website belongs to the Super Admin alone, so an Admin gets
                     the placeholder below and never sees this card. --}}
                <div class="card shadow-sm border-0 rounded-2 mb-4" id="systemContentsPane">
                    <div class="card-body p-4">

                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                            <div>
                                <h5 class="fw-bold mb-1">
                                    <i class="bi bi-globe me-1 text-primary" aria-hidden="true"></i>
                                    System Contents
                                </h5>
                                <p class="text-secondary small mb-0">
                                    Everything the public website shows. Changes go live immediately.
                                </p>
                            </div>

                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('landing.home') }}"
                                target="_blank" rel="noopener">
                                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                                View website
                            </a>
                        </div>

                        {{-- Blue pills, so which part of the website is being
                             edited is obvious at a glance. --}}
                        <ul class="nav nav-pills gap-2 mb-4 content-section-nav" data-content-sections>
                            @foreach ($contentSections as $key => $label)
                                <li class="nav-item">
                                    <button type="button"
                                        class="nav-link {{ $loop->first ? 'active' : '' }}"
                                        data-content-section="{{ $key }}">
                                        {{ $label }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>

                        <div class="text-secondary small py-3 px-1 d-none" data-content-loading>
                            <span class="spinner-border spinner-border-sm me-2" role="status"
                                aria-hidden="true"></span>
                            Loading content&hellip;
                        </div>

                        <div class="content-section-panel">
                            <div class="content-section-panel-title" data-content-section-title></div>

                            <form data-content-form novalidate>
                                <div class="row g-4" data-content-fields></div>

                                <div class="d-flex align-items-center gap-2 mt-4">
                                    <button type="submit" class="btn btn-primary px-4" data-content-save>
                                        <span class="spinner-border spinner-border-sm me-1 d-none"
                                            role="status" aria-hidden="true" data-content-save-spinner></span>
                                        Save Changes
                                    </button>

                                    <span class="text-success small d-none" data-content-saved>
                                        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Saved
                                    </span>
                                </div>
                            </form>
                        </div>

                        <div class="alert alert-danger mt-3 d-none" role="alert" data-content-error></div>

                    </div>
                </div>
            @endif

            <div class="card shadow-sm border-0 rounded-2">
                <div class="card-body p-5 text-center">
                    <i class="bi bi-gear config-placeholder-icon" aria-hidden="true"></i>
                    <h5 class="fw-bold mt-3 mb-1">System Settings</h5>
                    <p class="text-secondary mb-0">
                        The remaining system settings will be implemented in a future update.
                    </p>
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
                             account never changes between employee and client. --}}
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
                                    <i class="bi bi-building" aria-hidden="true"></i>
                                    <strong>Client</strong>
                                    <span>A company or homeowner account</span>
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
                                        Middle Name
                                    </label>
                                    <input type="text" id="userMiddleName" class="form-control" name="middle_name"
                                        maxlength="100" placeholder="Optional" autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="userLastName">
                                        Last Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" id="userLastName" class="form-control" name="last_name"
                                        maxlength="100" autocomplete="off">
                                </div>
                            </div>

                            {{-- Client name --}}
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
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold mb-1" for="userContactNumber">
                                        Contact Number <span class="text-danger">*</span>
                                    </label>
                                    <input type="tel" id="userContactNumber" class="form-control"
                                        name="contact_number" maxlength="32" placeholder="09XX XXX XXXX"
                                        autocomplete="off">
                                </div>
                                <div class="col-md-6" data-email-field>
                                    <label class="form-label small fw-semibold mb-1" for="userEmail">
                                        Email Address <span class="text-danger">*</span>
                                    </label>
                                    <input type="email" id="userEmail" class="form-control" name="email"
                                        maxlength="255" autocomplete="off">
                                    <div class="form-text d-none" data-email-locked-note>
                                        This is the client's sign-in address. Use Change Email on their row to move it.
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4" data-photo-block>
                                <label class="form-label small fw-semibold mb-1" for="userPhoto">
                                    Profile Picture
                                </label>
                                <div class="config-photo-row">
                                    <div class="config-photo-preview" data-photo-preview>
                                        <span data-photo-initials>?</span>
                                    </div>
                                    <div class="flex-grow-1">
                                        <input type="file" id="userPhoto" class="form-control" name="profile_photo"
                                            accept="image/*" data-photo-input>
                                        <small class="text-muted">Optional. JPG, PNG or WEBP, up to 5 MB.</small>
                                    </div>
                                </div>
                            </div>

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
                                            name="password" minlength="8" maxlength="72" autocomplete="off"
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
                                        Generated for you, or type your own - at least 8 characters. The account must
                                        choose a new password at first sign-in.
                                        @if ($mailEnabled)
                                            A copy is emailed to them automatically.
                                        @else
                                            Email delivery is not configured, so hand this over directly.
                                        @endif
                                    </div>
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
            };
            window.configurationOptions = {
                technicianRoles: @json($technicianRoles),
                mailEnabled: @json($mailEnabled),
            };
        </script>
        <script src="/js/super-admin/configuration.js"></script>
        @if (auth()->user()?->isSuperAdmin())
            <script src="/js/super-admin/systemContents.js"></script>
        @endif
    @endpush
@endsection
