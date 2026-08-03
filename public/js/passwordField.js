/**
 * Password field behaviour shared by every auth screen.
 *
 * Two things, both opt-in from the markup:
 *
 *   - a show/hide eye on any `[data-password-field]` wrapper, and
 *   - a live match indication between `[data-password-new]` and
 *     `[data-password-confirm]`, so nobody discovers a typo only after
 *     submitting.
 */
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
    // Do the two passwords match?
    // ---------------------------------------------------------------

    const newPassword = document.querySelector("[data-password-new]");
    const confirmPassword = document.querySelector("[data-password-confirm]");
    const feedback = document.querySelector("[data-password-match]");

    if (!newPassword || !confirmPassword) {
        return;
    }

    const minLength = parseInt(newPassword.getAttribute("minlength") || "8", 10);

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

        if (value.length < minLength) {
            setFeedback(null, "At least " + minLength + " characters.");

            return;
        }

        if (!confirmation) {
            setFeedback(null, "Re-type it to confirm.");

            return;
        }

        if (value === confirmation) {
            setFeedback("match", "Both passwords match.");

            return;
        }

        setFeedback("differ", "The two passwords do not match.");
    }

    newPassword.addEventListener("input", check);
    confirmPassword.addEventListener("input", check);
});
