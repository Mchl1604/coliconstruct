/**
 * The task board: a server-paged table inside every project card, the project
 * filter above them, and the Urgent Actions chips that narrow the board to the
 * tasks that cannot proceed.
 *
 * Each card's table asks for its first page only when the card comes near the
 * screen - see App\Services\TaskBoard - so a board of fifty projects costs the
 * requests for the two or three anybody is looking at. The dialogs a row opens
 * come back with its page and replace the previous page's.
 *
 * Two filters run at once and they are different shapes:
 *
 *   - The project filter hides whole cards, so picking one project leaves that
 *     project's card and nothing else. The card format survives.
 *   - The attention chips narrow ROWS, on the server, because a project's
 *     board usually has both good tasks and stuck ones, and the question the
 *     chip asks is "which tasks", not "which projects". A card the chip would
 *     leave empty drops out too - known from the counts each card carries, so
 *     pressing "Missing Date" does not leave a column of empty cards behind
 *     or fetch them to find out.
 */
document.addEventListener("DOMContentLoaded", function () {
    const board = document.querySelector("[data-task-board]");

    if (!board || !window.serverTable) {
        return;
    }

    // Which gap the board is narrowed to: "" for no narrowing, "all" for every
    // affected task, or one of the gap keys.
    let activeGap = "";

    // A task a notification linked to has its dialogs on the page already -
    // see openTaskFromQuery.js - so no card writes them a second time.
    const linkedTask = new URLSearchParams(window.location.search).get("task");

    function gapCountsOf(card) {
        try {
            return JSON.parse(card.dataset.gapCounts || "{}") || {};
        } catch (error) {
            return {};
        }
    }

    const cards = Array.prototype.slice
        .call(board.querySelectorAll("[data-project-card]"))
        .map(function (card) {
            return {
                card: card,
                projectId: card.dataset.projectId,
                gapCounts: gapCountsOf(card),
                table: card.querySelector("[data-project-tasks-table]"),
                dialogs: board.querySelector(
                    '[data-task-dialogs="' + card.dataset.projectId + '"]',
                ),
                api: null,
            };
        });

    /** Build a card's table - which fetches its first page - once. */
    function load(entry) {
        if (entry.api || !entry.table) {
            return;
        }

        entry.api = window.serverTable(entry.table, {
            extras: entry.dialogs,
            params: function () {
                return { gap: activeGap, linked_task: linkedTask || "" };
            },
            onLoad: function () {
                // The edit dialogs' date pickers, for the dialogs just put in.
                if (window.taskDatePickers && entry.dialogs) {
                    window.taskDatePickers.initInlineRows(entry.dialogs);
                }
            },
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            info: false,
            // Due Date.
            order: [[4, "asc"]],
            columnDefs: [
                { targets: [1, 3, 4], className: "text-start" },
                { targets: -1, orderable: false },
            ],
            language: {
                search: "",
                searchPlaceholder: "Search tasks...",
                emptyTable: "No tasks on this project yet.",
                zeroRecords: "No tasks match your search.",
            },
        });
    }

    // Load a card when it comes within a screen's reach of being seen. A
    // hidden card never intersects, so a filtered-out card costs nothing.
    const observer =
        "IntersectionObserver" in window
            ? new IntersectionObserver(
                  function (records) {
                      records.forEach(function (record) {
                          if (!record.isIntersecting) {
                              return;
                          }

                          const entry = cards.find(function (item) {
                              return item.card === record.target;
                          });

                          if (entry) {
                              load(entry);
                              observer.unobserve(entry.card);
                          }
                      });
                  },
                  { rootMargin: "400px 0px" },
              )
            : null;

    cards.forEach(function (entry) {
        if (observer) {
            observer.observe(entry.card);
        } else {
            load(entry);
        }
    });

    const projectFilter = document.querySelector("[data-project-filter]");
    const noMatch = board.querySelector("[data-board-no-match]");
    const chips = Array.prototype.slice.call(
        document.querySelectorAll("[data-task-gap-chip]"),
    );
    const clearButton = document.querySelector("[data-task-gap-clear]");

    /**
     * Decide which cards are on screen - matching the project filter and,
     * when a chip is on, holding something the chip keeps - and redraw the
     * tables already built under the current chip.
     */
    function apply(gapChanged) {
        const projectId = projectFilter ? projectFilter.value : "all";
        let shown = 0;

        cards.forEach(function (entry) {
            const matchesProject =
                projectId === "all" || entry.projectId === projectId;

            const hasRows = !activeGap || (entry.gapCounts[activeGap] || 0) > 0;

            const visible = matchesProject && hasRows;

            entry.card.classList.toggle("d-none", !visible);

            if (visible) {
                shown += 1;
            }

            // A full draw: back to page one under the new chip. A table not
            // built yet picks the chip up when it is.
            if (gapChanged && entry.api) {
                entry.api.draw();
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
        cards.forEach(function (entry) {
            if (entry.api && !entry.card.classList.contains("d-none")) {
                entry.api.columns.adjust();
            }
        });
    }

    /**
     * Turn a chip on, or - pressed a second time - off again. A filter you
     * cannot release from the control that set it is a trap.
     */
    function setGap(gap) {
        activeGap = activeGap === gap ? "" : gap || "";
        apply(true);
    }

    chips.forEach(function (chip) {
        chip.addEventListener("click", function () {
            setGap(chip.dataset.taskGapChip);
        });
    });

    if (clearButton) {
        clearButton.addEventListener("click", function () {
            activeGap = "";
            apply(true);
        });
    }

    if (projectFilter) {
        projectFilter.addEventListener("change", function () {
            apply(false);
        });
    }

    // Arriving from the dashboard's Urgent Actions, or from a chip's own link:
    // ?attention=all narrows to every affected task, ?attention=date to one
    // kind. An unknown value is ignored rather than emptying the board. Set
    // before any card has fetched, so the first page is already narrowed.
    const requested = new URLSearchParams(window.location.search).get(
        "attention",
    );

    if (requested && chips.some((chip) => chip.dataset.taskGapChip === requested)) {
        activeGap = requested;
        apply(false);

        document
            .querySelector("[data-task-attention]")
            ?.scrollIntoView({ block: "nearest" });
    }
});
