/**
 * Registration form behaviour.
 *
 * One thing, opt in from the markup: any `[data-digits-only]` field keeps
 * nothing but digits, whether they are typed, pasted or dropped in. The rule
 * itself lives on User::CONTACT_NUMBER_RULE and is enforced on the server -
 * this only means nobody has to be told about a space they could not see.
 */
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-digits-only]").forEach(function (input) {
        const limit = parseInt(input.getAttribute("maxlength") || "0", 10);

        function clean() {
            let digits = input.value.replace(/\D+/g, "");

            if (limit > 0) {
                digits = digits.slice(0, limit);
            }

            if (digits === input.value) {
                return;
            }

            // Typing should carry on at the end rather than jump to the
            // start, which is what writing `value` does on its own.
            const atEnd = input.selectionStart === input.value.length;

            input.value = digits;

            if (atEnd) {
                input.setSelectionRange(digits.length, digits.length);
            }
        }

        input.addEventListener("input", clean);
        // `paste` and `drop` fire before the value changes, so the clean-up
        // waits for the browser to apply them.
        ["paste", "drop"].forEach(function (event) {
            input.addEventListener(event, function () {
                window.setTimeout(clean, 0);
            });
        });

        // A message the browser's own bubble cannot phrase: "please match the
        // requested format" says nothing about eleven digits.
        input.addEventListener("invalid", function () {
            input.setCustomValidity(
                limit > 0
                    ? "Enter a " + limit + "-digit contact number, digits only."
                    : "Digits only.",
            );
        });

        input.addEventListener("input", function () {
            input.setCustomValidity("");
        });

        clean();
    });
});
