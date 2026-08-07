{{--
    The Notification Center, as a centred dialog.

    The client portal has no page of its own for notifications - the bell's
    "View All Notifications" opens this instead. It reads the same
    /notifications endpoints the page does, so nothing about how a
    notification behaves changes; only where it is read.

    There is deliberately no Module filter here: a client's notifications all
    concern their own projects, so filtering them by module narrowed nothing.
--}}
<div class="modal fade" id="notificationCenterModal" tabindex="-1" aria-hidden="true"
    aria-labelledby="notificationCenterModalLabel" data-notification-modal>
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="notificationCenterModalLabel">Notifications</h5>
                    <p class="text-secondary small mb-0">
                        Everything that needed your attention, newest first.
                    </p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <div class="row g-2 align-items-end mb-3">
                    <div class="col-sm-8">
                        <label class="form-label small text-secondary" for="notificationModalSearch">Search</label>
                        <input type="search" class="form-control" id="notificationModalSearch"
                            placeholder="Search title or message&hellip;" aria-label="Search notifications"
                            data-modal-search>
                    </div>

                    <div class="col-sm-4">
                        <label class="form-label small text-secondary" for="notificationModalStatus">Status</label>
                        <select class="form-select" id="notificationModalStatus" data-modal-status>
                            <option value="all" selected>All</option>
                            <option value="unread">Unread</option>
                            <option value="read">Read</option>
                        </select>
                    </div>
                </div>

                <div class="notification-modal-list" data-modal-list></div>

                <div class="text-secondary small py-3 px-1 d-none" data-modal-loading>
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Loading notifications&hellip;
                </div>

                <div class="notification-empty-state mt-2 d-none" data-modal-empty>
                    No notifications found.
                </div>
            </div>

            <div class="modal-footer justify-content-between flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" data-modal-mark-all>
                    <i class="bi bi-check2-all me-1" aria-hidden="true"></i>
                    Mark All as Read
                </button>

                <div class="d-flex align-items-center gap-2 flex-wrap" data-modal-pagination></div>
            </div>

        </div>
    </div>
</div>

@push('scripts')
    @php
        // Built in a @php block rather than inline: Blade's @json directive
        // cannot parse an argument containing nested arrays, which the two
        // parameterised routes below need. __id__ is substituted per row by
        // the browser.
        $modalRoutes = [
            'list' => route('notifications.list'),
            'readAll' => route('notifications.read-all'),
            'read' => route('notifications.read', ['notification' => '__id__']),
            'destroy' => route('notifications.destroy', ['notification' => '__id__']),
        ];
    @endphp

    <script>
        window.notificationModalRoutes = @json($modalRoutes);
    </script>
    <script src="/js/notificationModal.js"></script>
@endpush
