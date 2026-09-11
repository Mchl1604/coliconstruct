/**
 * Password field behaviour shared by every screen that takes a password.
 *
 * Opt-in from the markup:
 *
 *   - a show/hide eye on any `[data-password-field]` wrapper,
 *   - a live requirements checklist, `[data-password-requirements="<input id>"]`
 *     (see the password-requirements Blade component), which also stops the
 *     form being submitted while a requirement is unmet, and
 *   - a live match indication between `[data-password-new]` and
 *     `[data-password-confirm]`, so nobody discovers a typo only after
 *     submitting.
 *
 * The requirements themselves are not written here. Each checklist line
 * carries the pattern App\Support\PasswordPolicy tests on the server, so what
 * is ticked on screen is what the server will accept. The server still
 * decides: this only saves a round trip.
 */
(function () {
    /**
     * "a", "a and b", "a, b and c" - the same joining the server's message
     * uses.
     */
    function listed(items) {
        if (items.length === 1) {
            return items[0];
        }

        return items.slice(0, -1).join(", ") + " and " + items[items.length - 1];
    }

    function checklistFor(input) {
        if (!input || !input.id) {
            return null;
        }

        return document.querySelector(
            '[data-password-requirements="' + input.id + '"]',
        );
    }

    /**
     * Which lines of a checklist this value meets.
     */
    function evaluate(list, value) {
        return Array.from(list.querySelectorAll("[data-requirement]")).map(
            function (item) {
                let met;

                if (item.dataset.pattern) {
                    met = new RegExp(item.dataset.pattern, "u").test(value);
                } else {
                    // Code points rather than UTF-16 units, which is how the
                    // server's mb_strlen() counts.
                    met =
                        Array.from(value).length >=
                        parseInt(item.dataset.minLength || "0", 10);
                }

                return { item: item, met: met };
            },
        );
    }

    /**
     * What a value is missing, worded as the server words it - or "" when it
     * is missing nothing.
     */
    function failureMessage(input) {
        const list = checklistFor(input);

        if (!list) {
            return "";
        }

        const unmet = evaluate(list, input.value).filter(function (result) {
            return !result.met;
        });

        if (!unmet.length) {
            return "";
        }

        const clauses = [];
        const missing = [];

        unmet.forEach(function (result) {
            if (result.item.dataset.minLength) {
                clauses.push(
                    "be at least " +
                        result.item.dataset.minLength +
                        " characters long",
                );
            } else {
                missing.push(result.item.dataset.missing);
            }
        });

        if (missing.length) {
            clauses.push("contain at least " + listed(missing));
        }

        return "Password must " + clauses.join(" and ") + ".";
    }

    document.addEventListener("DOMContentLoaded", function () {
        // ---------------------------------------------------------------
        // Show / hide
        // ---------------------------------------------------------------

        document.querySelectorAll("[data-password-field]").forEach(function (wrapper) {
            const input = wrapper.querySelector("input");
            const toggle = wrapper.querySelector("[data-password-toggle]");

            if (!input || !toggle) {
                return;
            }

            const icon = toggle.querySelector("i");

            toggle.addEventListener("click", function () {
                const revealed = input.type === "text";

                input.type = revealed ? "password" : "text";
                toggle.setAttribute(
                    "aria-label",
                    revealed ? "Show password" : "Hide password",
                );
                toggle.setAttribute("aria-pressed", String(!revealed));

                if (icon) {
                    icon.className = revealed ? "bi bi-eye" : "bi bi-eye-slash";
                }

                // Typing should carry on where it left off, not at the start.
                const caret = input.value.length;
                input.focus();
                input.setSelectionRange(caret, caret);
            });
        });

        // ---------------------------------------------------------------
        // Requirements checklist
        // ---------------------------------------------------------------

        function paint(result, flagged) {
            const state = result.met ? "met" : flagged ? "flagged" : "pending";
            const icon = result.item.querySelector("[data-requirement-icon]");
            const label = result.item.querySelector("[data-requirement-state]");

            result.item.classList.remove("text-muted", "text-success", "text-danger");
            result.item.classList.add(
                state === "met"
                    ? "text-success"
                    : state === "flagged"
                      ? "text-danger"
                      : "text-muted",
            );

            if (icon) {
                icon.className =
                    "me-1 bi " +
                    (state === "met"
                        ? "bi-check-circle-fill"
                        : state === "flagged"
                          ? "bi-x-circle-fill"
                          : "bi-circle");
            }

            if (label) {
                label.textContent = result.met ? "(met)" : "(not met)";
            }
        }

        function refresh(list, input, flagged) {
            evaluate(list, input.value).forEach(function (result) {
                paint(result, flagged);
            });

            // Blocks a native submit and says why. Empty is left to
            // `required`, or - on the account dialog - means "generate one".
            input.setCustomValidity(input.value ? failureMessage(input) : "");
        }

        document.querySelectorAll("[data-password-requirements]").forEach(function (list) {
            const input = document.getElementById(list.dataset.passwordRequirements);

            if (!input) {
                return;
            }

            const describedBy = (input.getAttribute("aria-describedby") || "")
                .split(" ")
                .filter(Boolean);

            if (describedBy.indexOf(list.id) === -1) {
                describedBy.push(list.id);
                input.setAttribute("aria-describedby", describedBy.join(" "));
            }

            input.addEventListener("input", function () {
                refresh(list, input, false);
            });

            refresh(list, input, false);
        });

        // Captured at the document, ahead of any handler on the form itself,
        // so a page script that posts the form by fetch is stopped as well as
        // an ordinary submit. Catches the forms marked `novalidate`, where the
        // custom validity above is not consulted.
        document.addEventListener(
            "submit",
            function (event) {
                const form = event.target;
                let blocked = null;

                form.querySelectorAll("[data-password-requirements]").forEach(function (list) {
                    const input = document.getElementById(list.dataset.passwordRequirements);

                    if (!input || !form.contains(input) || input.disabled || !input.value) {
                        return;
                    }

                    if (failureMessage(input)) {
                        refresh(list, input, true);
                        blocked = blocked || input;
                    }
                });

                if (blocked) {
                    event.preventDefault();
                    event.stopPropagation();
                    blocked.focus();
                }
            },
            true,
        );

        // ---------------------------------------------------------------
        // Do the two passwords match?
        // ---------------------------------------------------------------

        const newPassword = document.querySelector("[data-password-new]");
        const confirmPassword = document.querySelector("[data-password-confirm]");
        const feedback = document.querySelector("[data-password-match]");

        if (!newPassword || !confirmPassword) {
            return;
        }

        function setFeedback(state, message) {
            [newPassword, confirmPassword].forEach(function (input) {
                input.classList.remove("is-valid", "is-invalid");

                if (state) {
                    input.classList.add(state === "match" ? "is-valid" : "is-invalid");
                }
            });

            if (!feedback) {
                return;
            }

            feedback.textContent = message || "";
            feedback.className =
                "form-text " +
                (state === "match"
                    ? "text-success"
                    : state === "differ"
                      ? "text-danger"
                      : "text-muted");
        }

        function check() {
            const value = newPassword.value;
            const confirmation = confirmPassword.value;

            // Nothing to say until there is something to compare.
            if (!value && !confirmation) {
                setFeedback(null, "");

                return;
            }

            // A difference is worth saying at any point.
            if (confirmation && value !== confirmation) {
                setFeedback("differ", "The two passwords do not match.");

                return;
            }

            // Two copies of a weak password are not green: the checklist says
            // what is still missing, and nothing here contradicts it.
            if (failureMessage(newPassword)) {
                setFeedback(null, "");

                return;
            }

            if (!confirmation) {
                setFeedback(null, "Re-type it to confirm.");

                return;
            }

            setFeedback("match", "Both passwords match.");
        }

        newPassword.addEventListener("input", check);
        confirmPassword.addEventListener("input", check);
    });
})();
