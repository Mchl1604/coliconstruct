/**
 * The task board: a DataTable inside every project card, and the filter above
 * them that decides which cards are on screen.
 *
 * Filtering hides whole cards rather than rows, so the card format survives -
 * picking one project leaves that project's card and nothing else.
 */
document.addEventListener("DOMContentLoaded", function () {
    const board = document.querySelector("[data-task-board]");

    if (!board || !window.jQuery || !window.jQuery.fn.DataTable) {
        return;
    }

    board.querySelectorAll("[data-project-tasks-table]").forEach(function (table) {
        window.jQuery(table).DataTable({
            responsive: true,
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            info: false,
            // Due Date.
            order: [[3, "asc"]],
            columnDefs: [
                // Start Date and Due Date carry a `data-order` timestamp,
                // which DataTables reads as numeric and then right-aligns on
                // its own. Only those two need saying; every other column is
                // left by default.
                { targets: [2, 3], className: "text-start" },
                { targets: -1, orderable: false },
            ],
            language: {
                search: "",
                searchPlaceholder: "Search tasks...",
                emptyTable: "No tasks on this project yet.",
                zeroRecords: "No tasks match your search.",
            },
        });
    });

    const filter = document.querySelector("[data-project-filter]");

    if (!filter) {
        return;
    }

    const cards = Array.prototype.slice.call(
        board.querySelectorAll("[data-project-card]"),
    );
    const noMatch = board.querySelector("[data-board-no-match]");

    filter.addEventListener("change", function () {
        const projectId = filter.value;
        let shown = 0;

        cards.forEach(function (card) {
            const matches =
                projectId === "all" || card.dataset.projectId === projectId;

            card.classList.toggle("d-none", !matches);

            if (matches) {
                shown += 1;
            }
        });

        if (noMatch) {
            noMatch.classList.toggle("d-none", shown > 0 || cards.length === 0);
        }

        // A table drawn inside a hidden card measures its columns at zero, so
        // the ones now on screen are re-measured.
        window.jQuery(".dataTable").DataTable().columns.adjust();
    });
});
