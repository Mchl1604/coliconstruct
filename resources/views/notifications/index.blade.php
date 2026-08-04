{{--
    The Notification Center.

    One page for every role, extending whichever shell the reader already
    lives in - the administrative portal and the technician/client portal
    have different chrome but the same list underneath.
--}}
@extends($layout)

@section('title', 'Notifications')

@section('content')
    @push('styles')
        <link rel="stylesheet" href="/css/notifications.css">
    @endpush

    <div class="container-fluid px-0">

        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-4">
            <div>
                <h4 class="mb-1">Notifications</h4>
                <p class="text-secondary small mb-0">Everything that needed your attention, newest first.</p>
            </div>

            <button type="button" class="btn btn-outline-primary" data-center-mark-all>
                <i class="bi bi-check2-all me-1" aria-hidden="true"></i>
                Mark All as Read
            </button>
        </div>

        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body p-4">

                <div class="row g-2 align-items-end mb-3">
                    <div class="col-md-5">
                        <label class="form-label small text-secondary" for="notificationSearch">Search</label>
                        <input type="search" class="form-control" id="notificationSearch"
                            placeholder="Search title, message or module&hellip;" aria-label="Search notifications"
                            data-center-search>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small text-secondary" for="notificationModule">Module</label>
                        <select class="form-select" id="notificationModule" aria-label="All Modules"
                            data-center-module>
                            <option value="all" selected>All Modules</option>
                            @foreach ($modules as $module)
                                <option value="{{ $module }}">{{ $module }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small text-secondary" for="notificationStatus">Status</label>
                        <select class="form-select" id="notificationStatus" aria-label="All Statuses"
                            data-center-status>
                            <option value="all" selected>All</option>
                            <option value="unread">Unread</option>
                            <option value="read">Read</option>
                        </select>
                    </div>
                </div>

                <div class="table-responsive notification-center-table">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col" class="text-start">Date</th>
                                <th scope="col" class="text-start">Notification</th>
                                <th scope="col" class="text-start">Module</th>
                                <th scope="col" class="text-start">Status</th>
                                <th scope="col" class="text-center">Action</th>
                            </tr>
                        </thead>

                        <tbody data-center-body></tbody>
                    </table>
                </div>

                <div class="text-secondary small py-3 px-1 d-none" data-center-loading>
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Loading notifications&hellip;
                </div>

                <div class="notification-empty-state mt-2 d-none" data-center-empty>
                    No notifications found.
                </div>

                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3"
                    data-center-pagination></div>

            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            window.notificationCenterRoutes = @json($centerRoutes);
        </script>
        <script src="/js/notificationCenter.js"></script>
    @endpush
@endsection
