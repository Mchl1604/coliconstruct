/**
 * The calendar header every FullCalendar in the system wears:
 *
 *     ‹   September 2026   ›
 *
 * with the title clickable, opening a month and year picker that jumps
 * straight there. Lifted out of the Super Admin schedules page so the
 * technician calendars read the same way.
 *
 * Pair it with `toolbar()` for the matching prev/title/next arrangement, and
 * with the `calendar-standard` class on the container for the styling.
 */
(function (global) {
    "use strict";

    const MONTHS = [
        "January",
        "February",
        "March",
        "April",
        "May",
        "June",
        "July",
        "August",
        "September",
        "October",
        "November",
        "December",
    ];

    /**
     * The header layout: an arrow either side of the title, nothing else.
     */
    function toolbar() {
        return { left: "prev", center: "title", right: "next" };
    }

    function buildJump(calendar, titleEl, anchorEl) {
        const panel = document.createElement("div");

        panel.className = "calendar-jump";
        panel.innerHTML =
            "<select data-jump-month></select><select data-jump-year></select>";
        anchorEl.appendChild(panel);

        const monthSelect = panel.querySelector("[data-jump-month]");
        const yearSelect = panel.querySelector("[data-jump-year]");

        MONTHS.forEach(function (name, index) {
            const option = document.createElement("option");

            option.value = String(index);
            option.textContent = name;
            monthSelect.appendChild(option);
        });

        function populateYears(centerYear) {
            yearSelect.innerHTML = "";

            for (let year = centerYear - 6; year <= centerYear + 6; year++) {
                const option = document.createElement("option");

                option.value = String(year);
                option.textContent = String(year);
                yearSelect.appendChild(option);
            }
        }

        function syncToDate(date) {
            monthSelect.value = String(date.getMonth());

            // Scrolling far enough out of the initial window needs a new one.
            if (
                !yearSelect.querySelector(
                    'option[value="' + date.getFullYear() + '"]',
                )
            ) {
                populateYears(date.getFullYear());
            }

            yearSelect.value = String(date.getFullYear());
        }

        function closePanel() {
            panel.classList.remove("is-open");
        }

        titleEl.addEventListener("click", function (event) {
            event.stopPropagation();

            if (panel.classList.contains("is-open")) {
                closePanel();

                return;
            }

            syncToDate(calendar.getDate());
            panel.classList.add("is-open");
        });

        panel.addEventListener("click", function (event) {
            event.stopPropagation();
        });

        document.addEventListener("click", closePanel);

        function jumpToSelection() {
            calendar.gotoDate(
                new Date(
                    parseInt(yearSelect.value, 10),
                    parseInt(monthSelect.value, 10),
                    1,
                ),
            );
            closePanel();
        }

        monthSelect.addEventListener("change", jumpToSelection);
        yearSelect.addEventListener("change", jumpToSelection);

        // The arrows move the calendar too, so the picker follows it.
        calendar.on("datesSet", function () {
            syncToDate(calendar.getDate());
        });

        populateYears(calendar.getDate().getFullYear());
        syncToDate(calendar.getDate());
    }

    /**
     * Call once, after calendar.render(): the toolbar has to exist for the
     * title to be found.
     */
    function attach(calendar, calendarEl) {
        if (!calendar || !calendarEl) {
            return;
        }

        const titleEl = calendarEl.querySelector(".fc-toolbar-title");
        const anchorEl = titleEl ? titleEl.closest(".fc-toolbar-chunk") : null;

        if (!titleEl || !anchorEl) {
            return;
        }

        titleEl.classList.add("calendar-title");
        anchorEl.classList.add("calendar-jump-anchor");

        buildJump(calendar, titleEl, anchorEl);
    }

    global.calendarHeader = { attach: attach, toolbar: toolbar };
})(window);
