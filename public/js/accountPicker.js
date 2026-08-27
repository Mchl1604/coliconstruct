/**
 * The type-to-search account picker.
 *
 * The same control as the one on Export Activity Logs - and drawn with the
 * same stylesheet, actorSearch.css - but written once and wired by data
 * attributes rather than pasted a third time. A container marked
 * `data-account-picker="<key>"` names a global array of candidates, and the
 * four parts inside it are found by their own attributes:
 *
 *   [data-picker-search]   the box somebody types into
 *   [data-picker-id]       the hidden field that is actually submitted
 *   [data-picker-results]  the list the matches drop into
 *   [data-picker-clear]    the button that undoes a choice
 *
 * One rule matters more than the rest and every part of this exists to keep
 * it: typing never sets the value. Only picking somebody does. A box reading
 * one name while the form submits another - or submits a name that was only
 * half typed - is the failure this control is shaped to make impossible, so
 * editing the text after choosing drops the choice again.
 *
 * `[data-picker-submit]`, where a container has one, is disabled while nothing
 * is chosen. That is for the dialogs where a choice is the whole point; a
 * filter that means "everybody" when empty simply leaves it out.
 */
(function () {
    "use strict";

    var RESULT_LIMIT = 8;

    function escapeHtml(value) {
        return String(value === null || value === undefined ? "" : value).replace(
            /[&<>"']/g,
            function (character) {
                return {
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#39;",
                }[character];
            },
        );
    }

    /**
     * How a chosen account reads in the box. Name and address together,
     * because two people share a name far more often than two accounts share
     * an address - and the address is what somebody checks they got right.
     */
    function label(account) {
        return account.email ? account.name + " — " + account.email : account.name;
    }

    function setUp(container) {
        var search = container.querySelector("[data-picker-search]");
        var hidden = container.querySelector("[data-picker-id]");
        var results = container.querySelector("[data-picker-results]");
        var clear = container.querySelector("[data-picker-clear]");

        if (!search || !hidden || !results) {
            return;
        }

        // The form's own submit button, when it has one - looked up from the
        // form rather than the container, since it lives in the modal footer.
        var form = container.closest("form");
        var submit = form ? form.querySelector("[data-picker-submit]") : null;

        var source = window[container.dataset.accountPicker];
        var accounts = Array.isArray(source) ? source : [];

        function close() {
            results.classList.add("d-none");
            results.innerHTML = "";
            search.setAttribute("aria-expanded", "false");
        }

        function syncSubmit() {
            if (submit) {
                submit.disabled = !hidden.value;
            }
        }

        function choose(account) {
            hidden.value = String(account.id);
            search.value = label(account);

            if (clear) {
                clear.classList.remove("d-none");
            }

            close();
            syncSubmit();
        }

        function reset(refocus) {
            hidden.value = "";
            search.value = "";

            if (clear) {
                clear.classList.add("d-none");
            }

            close();
            syncSubmit();

            if (refocus) {
                search.focus();
            }
        }

        function render(term) {
            var needle = term.trim().toLowerCase();

            if (!needle) {
                close();

                return;
            }

            // Name, address and account code all match, because all three are
            // things somebody knows an account by.
            var matches = accounts
                .filter(function (account) {
                    return [account.name, account.email, account.code].some(function (
                        field,
                    ) {
                        return (
                            field && String(field).toLowerCase().indexOf(needle) !== -1
                        );
                    });
                })
                .slice(0, RESULT_LIMIT);

            if (!matches.length) {
                results.innerHTML =
                    '<li class="config-actor-empty">No matching account.</li>';
                results.classList.remove("d-none");
                search.setAttribute("aria-expanded", "true");

                return;
            }

            results.innerHTML = matches
                .map(function (account) {
                    // The status is printed only when it is worth knowing. A
                    // deactivated account is still one a project may belong
                    // to, and saying so here is how somebody works out why
                    // their client cannot sign in.
                    var meta = escapeHtml(account.email || "");

                    if (account.code) {
                        meta += (meta ? " · " : "") + escapeHtml(account.code);
                    }

                    if (account.status) {
                        meta += (meta ? " · " : "") + escapeHtml(account.status);
                    }

                    return (
                        '<li><button type="button" class="config-actor-option" data-account-id="' +
                        escapeHtml(String(account.id)) +
                        '" role="option">' +
                        '<span class="config-actor-name">' +
                        escapeHtml(account.name) +
                        "</span>" +
                        '<span class="config-actor-meta">' +
                        meta +
                        "</span>" +
                        "</button></li>"
                    );
                })
                .join("");

            results.classList.remove("d-none");
            search.setAttribute("aria-expanded", "true");
        }

        search.addEventListener("input", function () {
            // Editing after picking somebody drops the pick: the box must
            // never show one name and submit another.
            if (hidden.value) {
                hidden.value = "";

                if (clear) {
                    clear.classList.add("d-none");
                }

                syncSubmit();
            }

            render(search.value);
        });

        // Typing nothing and focusing an empty box should still show that
        // there is something to search, so the first few are offered.
        search.addEventListener("focus", function () {
            if (!search.value.trim() && accounts.length) {
                render(accounts[0].name.slice(0, 1));
            }
        });

        search.addEventListener("keydown", function (event) {
            if (event.key === "Escape") {
                close();
            }

            // Enter picks the first match rather than submitting the form
            // half-filled.
            if (event.key === "Enter") {
                var first = results.querySelector("[data-account-id]");

                if (first) {
                    event.preventDefault();
                    first.click();
                }
            }
        });

        results.addEventListener("click", function (event) {
            var option = event.target.closest("[data-account-id]");

            if (!option) {
                return;
            }

            var chosen = accounts.find(function (account) {
                return String(account.id) === option.dataset.accountId;
            });

            if (chosen) {
                choose(chosen);
            }
        });

        if (clear) {
            clear.addEventListener("click", function () {
                reset(true);
            });
        }

        // A click anywhere else closes the list. Without this it stays open
        // behind the rest of the dialog.
        document.addEventListener("click", function (event) {
            if (!container.contains(event.target)) {
                close();
            }
        });

        // A name typed but never picked is not a choice, and it must not look
        // like one on the way out either.
        if (form) {
            form.addEventListener("submit", function () {
                if (!hidden.value) {
                    search.value = "";
                }
            });
        }

        syncSubmit();
    }

    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll("[data-account-picker]").forEach(setUp);
    });
})();
