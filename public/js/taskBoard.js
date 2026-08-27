/**
 * The task board: a DataTable inside every project card, the project filter
 * above them, and the Urgent Actions chips that narrow the board to the tasks
 * that cannot proceed.
 *
 * Two filters run at once and they are different shapes:
 *
 *   - The project filter hides whole cards, so picking one project leaves that
 *     project's card and nothing else. The card format survives.
 *   - The attention chips hide ROWS, because a project's board usually has
 *     both good tasks and stuck ones, and the question the chip asks is "which
 *     tasks", not "which projects". A card left with no matching rows then
 *     drops out too, so pressing "Missing Date" does not leave a column of
 *     empty cards behind.
 *
 * Row filtering goes through DataTables' own search pipeline rather than
 * setting `display: none` on the rows: hidden rows would still be counted,
 * paginated and searched, so page 1 of 3 could legitimately come back empty.
 */
document.addEventListener("DOMContentLoaded", function () {
    const board = document.querySelector("[data-task-board]");

    if (!board || !window.jQuery || !window.jQuery.fn.DataTable) {
        return;
    }

    const $ = window.jQuery;

    // Which gap the board is narrowed to: "" for no narrowing, "all" for every
    // affected task, or one of the three gap keys.
    let activeGap = "";

    /**
     * The row-level filter. Registered once and scoped to this board, so a
     * DataTable elsewhere on the page is never touched by it.
     */
    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        if (!activeGap || !settings.nTable.closest("[data-task-board]")) {
            return true;
        }

        const row = settings.aoData[dataIndex].nTr;
        const gap = row ? row.dataset.taskGap || "" : "";

        // A task with nothing wrong with it has no data-task-gap at all, so it
        // fails every narrowing - which is the point.
        return activeGap === "all" ? gap !== "" : gap === activeGap;
    });

    // Each project card with its own table, so a card can be asked whether it
    // still has anything to show.
    const cards = Array.prototype.slice
        .call(board.querySelectorAll("[data-project-card]"))
        .map(function (card) {
            const table = card.querySelector("[data-project-tasks-table]");

            return {
                card: card,
                projectId: card.dataset.projectId,
                api: table
                    ? $(table).DataTable({
                          responsive: true,
                          autoWidth: false,
                          pageLength: 10,
                          lengthMenu: [10, 25, 50, 100],
                          info: false,
                          // Due Date.
                          order: [[3, "asc"]],
                          columnDefs: [
                              // Start Date and Due Date carry a `data-order`
                              // timestamp, which DataTables reads as numeric
                              // and then right-aligns on its own. Only those
                              // two need saying; every other column is left by
                              // default.
                              { targets: [2, 3], className: "text-start" },
                              { targets: -1, orderable: false },
                          ],
                          language: {
                              search: "",
                              searchPlaceholder: "Search tasks...",
                              emptyTable: "No tasks on this project yet.",
                              zeroRecords: "No tasks match your search.",
                          },
                      })
                    : null,
            };
        });

    const projectFilter = document.querySelector("[data-project-filter]");
    const noMatch = board.querySelector("[data-board-no-match]");
    const chips = Array.prototype.slice.call(
        document.querySelectorAll("[data-task-gap-chip]"),
    );
    const clearButton = document.querySelector("[data-task-gap-clear]");

    /**
     * Redraw every table under the current chip, then decide which cards are
     * on screen: it has to match the project filter, and - when a chip is on -
     * still have a row left after the narrowing.
     */
    function apply() {
        const projectId = projectFilter ? projectFilter.value : "all";
        let shown = 0;

        cards.forEach(function (entry) {
            if (entry.api) {
                // `false` keeps the current page and ordering; only the rows
                // on screen change.
                entry.api.draw(false);
            }

            const matchesProject =
                projectId === "all" || entry.projectId === projectId;

            const hasRows =
                !activeGap ||
                !entry.api ||
                entry.api.rows({ search: "applied" }).count() > 0;

            const visible = matchesProject && hasRows;

            entry.card.classList.toggle("d-none", !visible);

            if (visible) {
                shown += 1;
            }
        });

        if (noMatch) {
            noMatch.classList.toggle("d-none", shown > 0 || cards.length === 0);
        }

        chips.forEach(function (chip) {
            const on = chip.dataset.taskGapChip === activeGap;

            chip.classList.toggle("is-active", on);
            chip.setAttribute("aria-pressed", on ? "true" : "false");
        });

        if (clearButton) {
            clearButton.classList.toggle("d-none", !activeGap);
        }

        // A table drawn inside a hidden card measures its columns at zero, so
        // the ones now on screen are re-measured.
        $(".dataTable").DataTable().columns.adjust();
    }

    /**
     * Turn a chip on, or - pressed a second time - off again. A filter you
     * cannot release from the control that set it is a trap.
     */
    function setGap(gap) {
        activeGap = activeGap === gap ? "" : gap || "";
        apply();
    }

    chips.forEach(function (chip) {
        chip.addEventListener("click", function () {
            setGap(chip.dataset.taskGapChip);
        });
    });

    if (clearButton) {
        clearButton.addEventListener("click", function () {
            activeGap = "";
            apply();
        });
    }

    if (projectFilter) {
        projectFilter.addEventListener("change", apply);
    }

    // Arriving from the dashboard's Urgent Actions, or from a chip's own link:
    // ?attention=all narrows to every affected task, ?attention=date to one
    // kind. An unknown value is ignored rather than emptying the board.
    const requested = new URLSearchParams(window.location.search).get(
        "attention",
    );

    if (requested && chips.some((chip) => chip.dataset.taskGapChip === requested)) {
        activeGap = requested;
        apply();

        document
            .querySelector("[data-task-attention]")
            ?.scrollIntoView({ block: "nearest" });
    }
});
