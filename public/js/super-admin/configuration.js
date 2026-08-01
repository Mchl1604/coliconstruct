document.addEventListener("DOMContentLoaded", function () {
    const routes = window.configurationRoutes || {};
    const options = window.configurationOptions || {};
    const technicianRoles = options.technicianRoles || [];

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    function escapeHtml(value) {
        return String(value == null ? "" : value).replace(
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

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute("content") : "";
    }

    function setAlert(element, message) {
        if (!element) {
            return;
        }

        element.textContent = message || "";
        element.classList.toggle("d-none", !message);
    }

    function setBusy(button, spinner, busy) {
        if (button) {
            button.disabled = busy;
        }

        if (spinner) {
            spinner.classList.toggle("d-none", !busy);
        }
    }

    /**
     * Every request goes through here so a non-JSON error page can never
     * reject as an unhandled parse failure.
     */
    function requestJson(url, config) {
        const settings = Object.assign(
            {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
            },
            config || {},
        );

        return fetch(url, settings).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (body) {
                    return { ok: response.ok, status: response.status, body: body };
                });
        });
    }

    /**
     * POST/PUT/DELETE with the CSRF token attached. FormData is sent as-is so
     * the profile picture rides along; anything else goes as JSON.
     */
    function sendJson(url, method, payload) {
        const headers = {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": csrfToken(),
        };

        let body = payload;

        if (payload instanceof FormData) {
            payload.set("_token", csrfToken());
        } else if (payload !== undefined) {
            headers["Content-Type"] = "application/json";
            body = JSON.stringify(payload);
        }

        return requestJson(url, { method: method, headers: headers, body: body });
    }

    function bootstrapModal(element) {
        return window.bootstrap
            ? window.bootstrap.Modal.getOrCreateInstance(element)
            : null;
    }

    /**
     * The Clipboard API needs a secure context, which a plain http:// host is
     * not, so fall back to selecting the field and letting execCommand copy.
     */
    function copyToClipboard(input, button) {
        const done = function () {
            if (!button) {
                return;
            }

            const icon = button.querySelector("i");

            if (!icon) {
                return;
            }

            icon.className = "bi bi-check-lg";
            window.setTimeout(function () {
                icon.className = "bi bi-clipboard";
            }, 1200);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(done, function () {
                legacyCopy(input);
                done();
            });

            return;
        }

        legacyCopy(input);
        done();
    }

    function legacyCopy(input) {
        input.removeAttribute("readonly");
        input.select();
        input.setSelectionRange(0, input.value.length);
        document.execCommand("copy");
        input.setAttribute("readonly", "readonly");
        input.blur();
    }

    function avatarCell(row) {
        const avatar = row.photo_url
            ? '<img class="config-avatar" src="' +
              escapeHtml(row.photo_url) +
              '" alt="">'
            : '<span class="config-avatar">' +
              escapeHtml(row.initials) +
              "</span>";

        return (
            '<div class="config-user-cell">' +
            avatar +
            '<span class="fw-semibold">' +
            escapeHtml(row.full_name) +
            "</span></div>"
        );
    }

    function statusCell(row) {
        return (
            '<span class="badge ' +
            row.status_badge_class +
            '">' +
            escapeHtml(row.status_label) +
            "</span>"
        );
    }

    function actionButton(action, id, icon, title, variant) {
        return (
            '<button type="button" class="btn btn-sm btn-outline-' +
            variant +
            ' py-1 px-2" data-action="' +
            action +
            '" data-user-id="' +
            id +
            '" title="' +
            escapeHtml(title) +
            '" aria-label="' +
            escapeHtml(title) +
            '"><i class="bi ' +
            icon +
            '"></i></button>'
        );
    }

    const pageError = document.querySelector("[data-user-error]");

    // ---------------------------------------------------------------
    // Tables
    // ---------------------------------------------------------------

    /**
     * Both tables behave identically apart from their columns and filters, so
     * one factory drives them: it owns the request token, the debounce, the
     * paging state and the rendering.
     */
    function createTable(config) {
        const body = document.querySelector(config.bodySelector);
        const loading = document.querySelector(config.loadingSelector);
        const empty = document.querySelector(config.emptySelector);
        const count = document.querySelector(config.countSelector);
        const pagination = document.querySelector(config.paginationSelector);
        const search = document.querySelector(config.searchSelector);
        const filters = (config.filterSelectors || []).map(function (selector) {
            return document.querySelector(selector);
        });

        let requestToken = 0;
        let searchTimer = null;
        let page = 1;

        function params() {
            const query = new URLSearchParams();

            query.set("page", String(page));

            if (search && search.value.trim()) {
                query.set("search", search.value.trim());
            }

            filters.forEach(function (filter) {
                if (filter && filter.value) {
                    query.set(filter.dataset.filterKey, filter.value);
                }
            });

            return query;
        }

        function renderPagination(meta) {
            if (!pagination) {
                return;
            }

            if (!meta || meta.total === 0) {
                pagination.classList.add("d-none");
                pagination.innerHTML = "";

                return;
            }

            pagination.classList.remove("d-none");
            pagination.innerHTML =
                '<span class="config-pagination-summary">Showing ' +
                meta.from +
                "&ndash;" +
                meta.to +
                " of " +
                meta.total +
                '</span><div class="btn-group btn-group-sm">' +
                '<button type="button" class="btn btn-outline-secondary" data-page="' +
                (meta.current_page - 1) +
                '"' +
                (meta.current_page <= 1 ? " disabled" : "") +
                ">Previous</button>" +
                '<button type="button" class="btn btn-outline-secondary" disabled>Page ' +
                meta.current_page +
                " of " +
                meta.last_page +
                "</button>" +
                '<button type="button" class="btn btn-outline-secondary" data-page="' +
                (meta.current_page + 1) +
                '"' +
                (meta.current_page >= meta.last_page ? " disabled" : "") +
                ">Next</button></div>";
        }

        function load() {
            const token = ++requestToken;

            loading.classList.remove("d-none");
            empty.classList.add("d-none");

            requestJson(config.url + "?" + params().toString()).then(
                function (result) {
                    if (token !== requestToken) {
                        return;
                    }

                    loading.classList.add("d-none");

                    if (!result.ok) {
                        body.innerHTML = "";
                        setAlert(
                            pageError,
                            result.body.error || "Could not load accounts.",
                        );
                        renderPagination(null);

                        return;
                    }

                    setAlert(pageError, "");

                    const rows = result.body.rows || [];

                    body.innerHTML = rows.map(config.renderRow).join("");
                    empty.classList.toggle("d-none", rows.length > 0);

                    const meta = result.body.meta || {};

                    if (count) {
                        count.textContent =
                            meta.total + (meta.total === 1 ? " account" : " accounts");
                    }

                    renderPagination(meta);
                },
            );
        }

        if (search) {
            // Debounced so a request isn't fired on every keystroke.
            search.addEventListener("input", function () {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(function () {
                    page = 1;
                    load();
                }, 300);
            });
        }

        filters.forEach(function (filter) {
            if (filter) {
                filter.addEventListener("change", function () {
                    page = 1;
                    load();
                });
            }
        });

        if (pagination) {
            pagination.addEventListener("click", function (event) {
                const button = event.target.closest("[data-page]");

                if (!button || button.disabled) {
                    return;
                }

                page = Number(button.dataset.page);
                load();
            });
        }

        return {
            load: load,
            reload: function () {
                load();
            },
            reset: function () {
                page = 1;
                load();
            },
            element: body,
        };
    }

    const employeeTable = createTable({
        url: routes.employees,
        bodySelector: "[data-employee-body]",
        loadingSelector: "[data-employee-loading]",
        emptySelector: "[data-employee-empty]",
        countSelector: "[data-employee-count]",
        paginationSelector: "[data-employee-pagination]",
        searchSelector: "[data-employee-search]",
        filterSelectors: ["[data-employee-role]", "[data-employee-status]"],
        renderRow: function (row) {
            return (
                "<tr>" +
                '<td class="config-code-cell">' +
                escapeHtml(row.user_code) +
                "</td>" +
                "<td>" +
                avatarCell(row) +
                "</td>" +
                "<td>" +
                escapeHtml(row.role_label) +
                "</td>" +
                "<td>" +
                escapeHtml(row.email) +
                "</td>" +
                "<td>" +
                statusCell(row) +
                "</td>" +
                '<td class="text-center"><div class="config-row-actions">' +
                actionButton("edit", row.id, "bi-pencil", "Edit employee", "primary") +
                (row.is_active
                    ? actionButton(
                          "deactivate",
                          row.id,
                          "bi-lock",
                          "Deactivate account",
                          "warning",
                      )
                    : actionButton(
                          "activate",
                          row.id,
                          "bi-unlock",
                          "Activate account",
                          "success",
                      )) +
                actionButton("archive", row.id, "bi-trash", "Archive account", "danger") +
                "</div></td>" +
                "</tr>"
            );
        },
    });

    const clientTable = createTable({
        url: routes.clients,
        bodySelector: "[data-client-body]",
        loadingSelector: "[data-client-loading]",
        emptySelector: "[data-client-empty]",
        countSelector: "[data-client-count]",
        paginationSelector: "[data-client-pagination]",
        searchSelector: "[data-client-search]",
        filterSelectors: ["[data-client-status]"],
        renderRow: function (row) {
            return (
                "<tr>" +
                '<td class="config-code-cell">' +
                escapeHtml(row.user_code) +
                "</td>" +
                "<td>" +
                avatarCell(row) +
                "</td>" +
                "<td>" +
                escapeHtml(row.company_name) +
                "</td>" +
                "<td>" +
                escapeHtml(row.email) +
                "</td>" +
                "<td>" +
                statusCell(row) +
                "</td>" +
                '<td class="text-center"><div class="config-row-actions">' +
                actionButton("edit", row.id, "bi-pencil", "Edit client", "primary") +
                actionButton(
                    "reset-password",
                    row.id,
                    "bi-key",
                    "Reset password",
                    "secondary",
                ) +
                (row.is_active
                    ? actionButton(
                          "deactivate",
                          row.id,
                          "bi-lock",
                          "Deactivate account",
                          "warning",
                      )
                    : actionButton(
                          "activate",
                          row.id,
                          "bi-unlock",
                          "Activate account",
                          "success",
                      )) +
                actionButton("archive", row.id, "bi-trash", "Archive account", "danger") +
                "</div></td>" +
                "</tr>"
            );
        },
    });

    // The filter key each select contributes to the query string.
    [
        ["[data-employee-role]", "role"],
        ["[data-employee-status]", "status"],
        ["[data-client-status]", "status"],
    ].forEach(function (pair) {
        const element = document.querySelector(pair[0]);

        if (element) {
            element.dataset.filterKey = pair[1];
        }
    });

    // ---------------------------------------------------------------
    // Add / edit user form
    // ---------------------------------------------------------------

    const userModalEl = document.querySelector("[data-user-modal]");
    const userForm = document.querySelector("[data-user-form]");
    const typeStep = document.querySelector("[data-account-type-step]");
    const typeInputs = document.querySelectorAll("[data-account-type]");
    const fieldsWrap = document.querySelector("[data-user-fields]");
    const employeeOnly = document.querySelectorAll("[data-employee-only]");
    const clientOnly = document.querySelectorAll("[data-client-only]");
    const photoBlock = document.querySelector("[data-photo-block]");
    const emailField = document.querySelector("[data-email-field]");
    const emailInput = userForm.querySelector('[name="email"]');
    const emailLockedNote = document.querySelector("[data-email-locked-note]");
    const roleSelect = document.querySelector("[data-role-select]");
    const specialtiesSection = document.querySelector("[data-specialties-section]");
    const specialtySelect = document.querySelector("[data-specialty-select]");
    const specialtyAdd = document.querySelector("[data-specialty-add]");
    const specialtyList = document.querySelector("[data-specialty-list]");
    const specialtyEmpty = document.querySelector("[data-specialty-empty]");
    const passwordBlock = document.querySelector("[data-password-block]");
    const passwordDisplay = document.querySelector("[data-password-display]");
    const passwordCopy = document.querySelector("[data-password-copy]");
    const passwordRegenerate = document.querySelector("[data-password-regenerate]");
    const accountHistory = document.querySelector("[data-account-history]");
    const photoInput = document.querySelector("[data-photo-input]");
    const photoPreview = document.querySelector("[data-photo-preview]");
    const userSubmit = document.querySelector("[data-user-submit]");
    const userSubmitLabel = document.querySelector("[data-user-submit-label]");
    const userSpinner = document.querySelector("[data-user-spinner]");
    const userFormError = document.querySelector("[data-user-form-error]");
    const userModalTitle = document.querySelector("[data-user-modal-title]");

    // The account being edited, or null while creating.
    let editing = null;
    let accountType = null;
    let selectedSkills = [];

    function toggleAll(elements, visible) {
        elements.forEach(function (element) {
            element.classList.toggle("d-none", !visible);
        });
    }

    function renderSpecialties() {
        specialtyList.innerHTML = selectedSkills
            .map(function (skill) {
                return (
                    '<span class="config-chip">' +
                    escapeHtml(skill.name) +
                    '<button type="button" data-specialty-remove="' +
                    skill.id +
                    '" aria-label="Remove ' +
                    escapeHtml(skill.name) +
                    '">&times;</button></span>'
                );
            })
            .join("");

        specialtyEmpty.classList.toggle("d-none", selectedSkills.length > 0);
    }

    function refreshSpecialtyOptions() {
        // An already-assigned specialty is removed from the picker, so a
        // duplicate cannot be chosen in the first place.
        Array.from(specialtySelect.options).forEach(function (option) {
            if (!option.value) {
                return;
            }

            option.hidden = selectedSkills.some(function (skill) {
                return String(skill.id) === option.value;
            });
        });

        specialtySelect.value = "";
    }

    function applyRoleVisibility() {
        const needsSpecialties =
            accountType === "employee" &&
            technicianRoles.indexOf(roleSelect.value) !== -1;

        specialtiesSection.classList.toggle("d-none", !needsSpecialties);
    }

    /**
     * Super Admin is not on offer in the picker, so an account that already
     * holds it gets a locked, read-only entry rather than an empty select
     * that would quietly demote them on save.
     */
    function lockRoleSelect(label) {
        const option = document.createElement("option");

        option.value = "super_admin";
        option.textContent = label;
        option.dataset.lockedRole = "1";

        roleSelect.appendChild(option);
        roleSelect.value = "super_admin";
        roleSelect.disabled = true;
    }

    function restoreRoleSelect() {
        const locked = roleSelect.querySelector("[data-locked-role]");

        if (locked) {
            locked.remove();
        }

        roleSelect.disabled = false;
    }

    function setPhotoPreview(url, initials) {
        if (url) {
            photoPreview.innerHTML =
                '<img src="' + escapeHtml(url) + '" alt="Profile picture">';

            return;
        }

        photoPreview.innerHTML = "<span>" + escapeHtml(initials || "?") + "</span>";
    }

    function setAccountType(type) {
        accountType = type;

        toggleAll(employeeOnly, type === "employee");
        toggleAll(clientOnly, type === "client");
        // A new client account is opened with just their name, number and
        // email; the company details and picture come later, on the edit form.
        photoBlock.classList.toggle("d-none", type === "client" && !editing);

        fieldsWrap.classList.remove("d-none");
        userSubmit.disabled = false;

        applyRoleVisibility();
    }

    function loadGeneratedPassword() {
        return requestJson(routes.generatePassword).then(function (result) {
            if (result.ok && result.body.password) {
                passwordDisplay.value = result.body.password;
            }
        });
    }

    function resetForm() {
        userForm.reset();
        editing = null;
        accountType = null;
        selectedSkills = [];

        fieldsWrap.classList.add("d-none");
        typeStep.classList.remove("d-none");
        passwordBlock.classList.remove("d-none");
        photoBlock.classList.remove("d-none");
        accountHistory.classList.add("d-none");
        emailField.classList.remove("d-none");
        emailInput.disabled = false;
        emailLockedNote.classList.add("d-none");
        specialtiesSection.classList.add("d-none");
        restoreRoleSelect();

        passwordDisplay.value = "";
        userSubmit.disabled = true;
        userModalTitle.textContent = "Add New User";
        userSubmitLabel.textContent = "Create Account";

        typeInputs.forEach(function (input) {
            input.checked = false;
        });

        setPhotoPreview(null, "?");
        renderSpecialties();
        refreshSpecialtyOptions();
        setAlert(userFormError, "");
    }

    function openCreate() {
        resetForm();
        loadGeneratedPassword();
        bootstrapModal(userModalEl)?.show();
    }

    function openEdit(account) {
        resetForm();

        editing = account;
        typeStep.classList.add("d-none");
        // A generated password belongs to account creation only; an existing
        // account changes its password through the reset workflow.
        passwordBlock.classList.add("d-none");
        accountHistory.classList.remove("d-none");

        userModalTitle.textContent = account.is_client
            ? "Edit Client Account"
            : "Edit Employee Account";
        userSubmitLabel.textContent = "Save Changes";

        document.querySelector("[data-history-registered]").textContent =
            account.registered_at || "—";
        document.querySelector("[data-history-creator]").textContent =
            account.created_by || "—";
        document.querySelector("[data-history-login]").textContent =
            account.last_login_at || "Never";

        setAccountType(account.is_client ? "client" : "employee");

        userForm.querySelector('[name="contact_number"]').value =
            account.contact_number || "";
        emailInput.value = account.email || "";

        if (account.is_client) {
            userForm.querySelector('[name="full_name"]').value = account.full_name || "";

            // The address is the client's login credential, so it moves only
            // through the Change Email workflow.
            emailInput.disabled = true;
            emailLockedNote.classList.remove("d-none");
        } else {
            userForm.querySelector('[name="first_name"]').value =
                account.first_name || "";
            userForm.querySelector('[name="middle_name"]').value =
                account.middle_name || "";
            userForm.querySelector('[name="last_name"]').value = account.last_name || "";

            if (account.role === "super_admin") {
                lockRoleSelect(account.role_label);
            } else {
                roleSelect.value = account.role || "";
            }

            selectedSkills = (account.skill_ids || []).map(function (id, index) {
                return { id: id, name: (account.skill_names || [])[index] || "Specialty" };
            });

            renderSpecialties();
            refreshSpecialtyOptions();
            applyRoleVisibility();
        }

        setPhotoPreview(account.photo_url, account.initials);
        bootstrapModal(userModalEl)?.show();
    }

    typeInputs.forEach(function (input) {
        input.addEventListener("change", function () {
            setAccountType(input.value);
        });
    });

    roleSelect.addEventListener("change", applyRoleVisibility);

    specialtyAdd.addEventListener("click", function () {
        const id = Number(specialtySelect.value);

        if (!id) {
            return;
        }

        // Belt and braces: the option is hidden once chosen, but a duplicate
        // still cannot get into the list from here.
        if (
            selectedSkills.some(function (skill) {
                return skill.id === id;
            })
        ) {
            return;
        }

        selectedSkills.push({
            id: id,
            name: specialtySelect.options[specialtySelect.selectedIndex].text,
        });

        renderSpecialties();
        refreshSpecialtyOptions();
    });

    specialtyList.addEventListener("click", function (event) {
        const button = event.target.closest("[data-specialty-remove]");

        if (!button) {
            return;
        }

        const id = Number(button.dataset.specialtyRemove);

        selectedSkills = selectedSkills.filter(function (skill) {
            return skill.id !== id;
        });

        renderSpecialties();
        refreshSpecialtyOptions();
    });

    photoInput.addEventListener("change", function () {
        const file = photoInput.files && photoInput.files[0];

        if (!file || !file.type.startsWith("image/")) {
            return;
        }

        const url = URL.createObjectURL(file);

        setPhotoPreview(url, "");
        photoPreview.querySelector("img").onload = function () {
            URL.revokeObjectURL(url);
        };
    });

    passwordCopy.addEventListener("click", function () {
        copyToClipboard(passwordDisplay, passwordCopy);
    });

    passwordRegenerate.addEventListener("click", function () {
        loadGeneratedPassword();
    });

    userForm.addEventListener("submit", function (event) {
        event.preventDefault();

        if (!accountType) {
            setAlert(userFormError, "Choose an account type first.");

            return;
        }

        setAlert(userFormError, "");
        setBusy(userSubmit, userSpinner, true);

        const payload = new FormData();

        payload.set("contact_number", userForm.querySelector('[name="contact_number"]').value);

        if (accountType === "client") {
            payload.set("full_name", userForm.querySelector('[name="full_name"]').value);

            // An existing client's email address is fixed.
            if (!editing) {
                payload.set("email", emailInput.value);
            }
        } else {
            payload.set("first_name", userForm.querySelector('[name="first_name"]').value);
            payload.set("middle_name", userForm.querySelector('[name="middle_name"]').value);
            payload.set("last_name", userForm.querySelector('[name="last_name"]').value);
            payload.set("email", emailInput.value);

            // A locked Super Admin role is never sent; the server keeps it.
            if (!roleSelect.disabled) {
                payload.set("role", roleSelect.value);
            }

            selectedSkills.forEach(function (skill) {
                payload.append("skill_ids[]", String(skill.id));
            });
        }

        // The password is only ever set when the account is created.
        if (!editing && passwordDisplay.value.trim()) {
            payload.set("password", passwordDisplay.value.trim());
        }

        if (photoInput.files && photoInput.files[0]) {
            payload.set("profile_photo", photoInput.files[0]);
        }

        const url = editing
            ? routes.userBase +
              "/" +
              editing.id +
              (accountType === "client" ? "/client" : "/employee")
            : accountType === "client"
              ? routes.storeClient
              : routes.storeEmployee;

        sendJson(url, "POST", payload).then(function (result) {
            setBusy(userSubmit, userSpinner, false);

            if (!result.ok) {
                setAlert(
                    userFormError,
                    result.body.error || "The account could not be saved.",
                );

                return;
            }

            bootstrapModal(userModalEl)?.hide();
            refreshTables();

            if (result.body.password) {
                showCredentials(
                    result.body.account,
                    result.body.password,
                    result.body.emailed,
                    false,
                );
            }
        });
    });

    userModalEl.addEventListener("hidden.bs.modal", resetForm);

    document.querySelector("[data-add-user-open]").addEventListener("click", openCreate);

    // ---------------------------------------------------------------
    // Credentials result
    // ---------------------------------------------------------------

    const credentialsModalEl = document.querySelector("[data-credentials-modal]");
    const credentialsCopy = document.querySelector("[data-credentials-copy]");
    const credentialsPassword = document.querySelector("[data-credentials-password]");

    function showCredentials(account, password, emailed, isReset) {
        document.querySelector("[data-credentials-title]").textContent = isReset
            ? "Password Reset"
            : "Account Created";

        document.querySelector("[data-credentials-intro]").textContent = isReset
            ? "A new temporary password has been issued for " + account.full_name + "."
            : account.full_name + " can sign in with these details.";

        document.querySelector("[data-credentials-code]").value = account.user_code;
        document.querySelector("[data-credentials-email]").value = account.email;
        credentialsPassword.value = password;

        document.querySelector("[data-credentials-note]").textContent = emailed
            ? "A copy has been emailed to the account. This password is shown only once - it cannot be read back later."
            : "Email delivery is not configured, so hand this password over directly. It is shown only once and cannot be read back later.";

        bootstrapModal(credentialsModalEl)?.show();
    }

    credentialsCopy.addEventListener("click", function () {
        copyToClipboard(credentialsPassword, credentialsCopy);
    });

    // ---------------------------------------------------------------
    // Confirmations: activate, deactivate, archive, reset password
    // ---------------------------------------------------------------

    const confirmModalEl = document.querySelector("[data-confirm-modal]");
    const confirmTitle = document.querySelector("[data-confirm-title]");
    const confirmBody = document.querySelector("[data-confirm-body]");
    const confirmLabel = document.querySelector("[data-confirm-label]");
    const confirmSubmit = document.querySelector("[data-confirm-submit]");
    const confirmSpinner = document.querySelector("[data-confirm-spinner]");
    const confirmError = document.querySelector("[data-confirm-error]");

    let confirmHandler = null;

    function askConfirmation(settings) {
        confirmTitle.textContent = settings.title;
        confirmBody.textContent = settings.body;
        confirmLabel.textContent = settings.label;
        // Only the classes change; the spinner and label stay as they are.
        confirmSubmit.className = "btn " + (settings.variant || "btn-primary");
        confirmHandler = settings.onConfirm;

        setAlert(confirmError, "");
        bootstrapModal(confirmModalEl)?.show();
    }

    confirmSubmit.addEventListener("click", function () {
        if (!confirmHandler) {
            return;
        }

        setAlert(confirmError, "");
        setBusy(confirmSubmit, confirmSpinner, true);

        confirmHandler().then(function (result) {
            setBusy(confirmSubmit, confirmSpinner, false);

            if (!result.ok) {
                setAlert(
                    confirmError,
                    result.body.error || "That action could not be completed.",
                );

                return;
            }

            bootstrapModal(confirmModalEl)?.hide();
            refreshTables();

            if (result.body.password && result.body.account) {
                showCredentials(
                    result.body.account,
                    result.body.password,
                    result.body.emailed,
                    true,
                );
            }
        });
    });

    // The confirm button is rebuilt with a new class each time, which would
    // otherwise strip the spinner it contains.
    confirmModalEl.addEventListener("hidden.bs.modal", function () {
        confirmHandler = null;
    });

    // ---------------------------------------------------------------
    // Row actions
    // ---------------------------------------------------------------

    function fetchAccount(id) {
        return requestJson(routes.userBase + "/" + id).then(function (result) {
            if (!result.ok) {
                setAlert(pageError, result.body.error || "Could not load that account.");

                return null;
            }

            return result.body.account;
        });
    }

    function setStatus(account, active) {
        return sendJson(routes.userBase + "/" + account.id + "/status", "PUT", {
            status: active ? "active" : "deactivated",
        });
    }

    function handleAction(action, id) {
        fetchAccount(id).then(function (account) {
            if (!account) {
                return;
            }

            if (action === "edit") {
                openEdit(account);

                return;
            }

            if (action === "reset-password") {
                askConfirmation({
                    title: "Reset Password",
                    body:
                        "Issue a new temporary password for " +
                        account.full_name +
                        "? Their current password stops working immediately, and they will have to choose a new one at next sign-in.",
                    label: "Reset Password",
                    variant: "btn-warning",
                    onConfirm: function () {
                        return sendJson(
                            routes.userBase + "/" + account.id + "/password",
                            "PUT",
                        ).then(function (result) {
                            if (result.ok) {
                                result.body.account = account;
                            }

                            return result;
                        });
                    },
                });

                return;
            }

            if (action === "activate" || action === "deactivate") {
                const activating = action === "activate";

                askConfirmation({
                    title: activating ? "Activate Account" : "Deactivate Account",
                    body: activating
                        ? "Allow " + account.full_name + " to sign in again?"
                        : account.full_name +
                          " will no longer be able to sign in. Their assignments, reports and historical records are all kept.",
                    label: activating ? "Activate" : "Deactivate",
                    variant: activating ? "btn-success" : "btn-warning",
                    onConfirm: function () {
                        return setStatus(account, activating);
                    },
                });

                return;
            }

            if (action === "archive") {
                askConfirmation({
                    title: "Archive Account",
                    body:
                        "Archive " +
                        account.full_name +
                        "? The account is removed from the active lists and can no longer sign in. Nothing is deleted - projects, reports, documents and audit history all stay exactly as they are.",
                    label: "Archive",
                    variant: "btn-danger",
                    onConfirm: function () {
                        return sendJson(routes.userBase + "/" + account.id, "DELETE");
                    },
                });
            }
        });
    }

    [employeeTable.element, clientTable.element].forEach(function (body) {
        body.addEventListener("click", function (event) {
            const button = event.target.closest("[data-action]");

            if (button) {
                handleAction(button.dataset.action, button.dataset.userId);
            }
        });
    });

    function refreshTables() {
        employeeTable.reload();
        clientTable.reload();
    }

    // ---------------------------------------------------------------
    // Initial load
    // ---------------------------------------------------------------

    employeeTable.load();
    clientTable.load();
});
