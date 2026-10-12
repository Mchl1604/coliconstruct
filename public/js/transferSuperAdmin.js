/**
 * Transfer Super Admin, on the Super Admin's own profile.
 *
 * Transfer opens the dialog and, in the same moment, asks the server to email
 * a code to the Super Admin's address. The role moves only when that code is
 * confirmed. Who it goes to is held by the server from the moment the code is
 * sent, so the confirmation carries the code alone.
 */
document.addEventListener("DOMContentLoaded", function () {
    const modalEl = document.querySelector("[data-transfer-modal]");
    const select = document.querySelector("[data-transfer-select]");
    const openButton = document.querySelector("[data-transfer-open]");
    const selectError = document.querySelector("[data-transfer-select-error]");

    // No verified Admin to offer, so there is no Transfer button either.
    if (!modalEl || !select || !openButton) {
        return;
    }

    const form = modalEl.querySelector("[data-transfer-form]");
    const nameEl = modalEl.querySelector("[data-transfer-name]");
    const statusEl = modalEl.querySelector("[data-transfer-status]");
    const codeInput = modalEl.querySelector("[data-transfer-code]");
    const resendButton = modalEl.querySelector("[data-transfer-resend]");
    const errorBox = modalEl.querySelector("[data-transfer-error]");
    const submitButton = modalEl.querySelector("[data-transfer-submit]");
    const spinner = modalEl.querySelector("[data-transfer-spinner]");

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrf = csrfMeta ? csrfMeta.getAttribute("content") : "";

    let countdown = null;

    function send(url, method, payload) {
        return fetch(url, {
            method: method,
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": csrf,
                "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify(payload),
        })
            .then(function (response) {
                return response
                    .json()
                    .catch(function () {
                        return {};
                    })
                    .then(function (body) {
                        return { ok: response.ok, body: body };
                    });
            })
            .catch(function () {
                return { ok: false, body: { error: "Connection lost. Try again." } };
            });
    }

    function showError(message) {
        errorBox.textContent = message || "";
        errorBox.classList.toggle("d-none", !message);
    }

    function setSelectError(message) {
        selectError.textContent = message || "";
        selectError.classList.toggle("d-none", !message);
        select.classList.toggle("is-invalid", Boolean(message));
    }

    function setBusy(busy) {
        submitButton.disabled = busy;
        spinner.classList.toggle("d-none", !busy);
    }

    /**
     * The 60-second wait between codes, counted down on the button so it says
     * how long is left. The server holds the same wait either way.
     */
    function startCountdown(seconds) {
        clearInterval(countdown);

        let remaining = seconds;

        function render() {
            if (remaining <= 0) {
                clearInterval(countdown);
                resendButton.disabled = false;
                resendButton.textContent = "Resend code";

                return;
            }

            resendButton.disabled = true;
            resendButton.textContent = "Resend code in " + remaining + "s";
            remaining -= 1;
        }

        render();
        countdown = setInterval(render, 1000);
    }

    function requestCode() {
        showError("");
        statusEl.textContent = "Sending code…";
        resendButton.disabled = true;

        send(modalEl.dataset.sendUrl, "POST", { new_owner_id: select.value }).then(
            function (result) {
                if (!result.ok) {
                    statusEl.textContent = "";
                    showError(result.body.error || result.body.message || "Unable to send a code.");
                    clearInterval(countdown);
                    resendButton.disabled = false;
                    resendButton.textContent = "Resend code";

                    return;
                }

                statusEl.textContent = "Code sent to " + result.body.email + ".";
                codeInput.disabled = false;
                submitButton.disabled = false;
                codeInput.focus();
                startCountdown(result.body.retry_after || 0);
            },
        );
    }

    openButton.addEventListener("click", function () {
        if (!select.value) {
            setSelectError("Choose an Admin.");

            return;
        }

        setSelectError("");

        nameEl.textContent = select.options[select.selectedIndex].text.trim();
        codeInput.value = "";
        codeInput.disabled = true;
        submitButton.disabled = true;
        spinner.classList.add("d-none");

        if (window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        requestCode();
    });

    select.addEventListener("change", function () {
        setSelectError("");
    });

    resendButton.addEventListener("click", requestCode);

    // Digits only, so a pasted "123 456" still submits cleanly.
    codeInput.addEventListener("input", function () {
        codeInput.value = codeInput.value.replace(/\D/g, "").slice(0, 6);
    });

    form.addEventListener("submit", function (event) {
        event.preventDefault();

        if (codeInput.value.length !== 6) {
            showError("Enter the 6-digit code.");

            return;
        }

        showError("");
        setBusy(true);

        send(modalEl.dataset.confirmUrl, "PUT", { code: codeInput.value }).then(
            function (result) {
                if (!result.ok) {
                    setBusy(false);
                    showError(
                        result.body.error || result.body.message || "Unable to transfer the role.",
                    );

                    return;
                }

                // A full reload: this is an Admin's profile now.
                window.location.assign(result.body.redirect);
            },
        );
    });

    modalEl.addEventListener("hidden.bs.modal", function () {
        clearInterval(countdown);
        codeInput.value = "";
        showError("");
    });
});
