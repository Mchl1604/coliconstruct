{{--
    The notification bell, shared by every authenticated portal.

    The markup is a shell only: the count and the list are fetched from
    /notifications/feed and refreshed while the page is open, so one component
    serves the administrative and technician shells without either of them
    knowing what is in it.
--}}
<div class="notification-bell dropdown" data-notification-bell>
    <button type="button" class="notification-bell-button" data-bs-toggle="dropdown" data-bs-auto-close="outside"
        aria-expanded="false" aria-label="Notifications" data-notification-toggle>
        <i class="bi bi-bell" aria-hidden="true"></i>

        {{-- Hidden until there is something to count. --}}
        <span class="notification-badge d-none" data-notification-badge aria-live="polite"></span>
    </button>

    <div class="dropdown-menu dropdown-menu-end notification-dropdown" data-notification-dropdown>
        <div class="notification-dropdown-header">
            <span>Notifications</span>
            <button type="button" class="notification-mark-all d-none" data-notification-mark-all>
                Mark all as read
            </button>
        </div>

        <div class="notification-dropdown-body" data-notification-list>
            <div class="notification-empty" data-notification-loading>
                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                Loading&hellip;
            </div>
        </div>

        <a class="notification-dropdown-footer" href="{{ route('notifications.index') }}">
            View All Notifications
        </a>
    </div>
</div>
