/**
 * The material picker: type a few letters, choose a material from the list.
 *
 * Shared by the setup screen's Materials step and the project's Add Material
 * dialog, so both search the same way and both offer the same way out when the
 * material is not listed yet - "Add ... to list", which the page handles with
 * quickAdd() below.
 *
 *     materialPicker.attach(input, {
 *         url: "/super-admin/projects/7/materials/suggest",
 *         onPick: function (material) { ... },     // a Material::toOption()
 *         exclude: function () { return [3, 9]; }, // optional ids to hide
 *         onAddNew: function (typedName) { ... },  // optional
 *     });
 *
 * Every value from the server is written with textContent, so a material
 * name can never become markup.
 */
(function (global) {
    "use strict";

    const DEBOUNCE = 250;

    function token() {
        const meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.content : "";
    }

    /** "2.50" as 2.5 and "12.00" as 12 - how a quantity is read back. */
    function formatQty(value) {
        const number = Number(value);

        if (!isFinite(number)) {
            return "";
        }

        return number.toLocaleString(undefined, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        });
    }

    function metaLine(material) {
        return [material.type, material.unit_label + " (" + material.unit + ")", material.description]
            .filter(Boolean)
            .join(" · ");
    }

    /**
     * Add a material to the master list. Resolves { ok, body }; body carries
     * `material` on success and `error` on a refusal.
     */
    function quickAdd(url, payload) {
        return fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": token(),
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
                return { ok: false, body: { error: "Unable to reach the server." } };
            });
    }

    function attach(input, options) {
        if (!input || input.dataset.materialPickerBound === "1") {
            return;
        }

        input.dataset.materialPickerBound = "1";
        input.setAttribute("autocomplete", "off");

        const settings = options || {};
        const host = input.parentElement;

        host.classList.add("material-picker");

        const menu = document.createElement("ul");

        menu.className = "material-picker-menu d-none";
        menu.setAttribute("role", "listbox");
        host.appendChild(menu);

        let timer = null;
        let items = [];
        let active = -1;
        let requestNumber = 0;

        function close() {
            menu.classList.add("d-none");
            active = -1;
        }

        function highlight(index) {
            const options = menu.querySelectorAll(".material-picker-option");

            options.forEach(function (option, optionIndex) {
                option.classList.toggle("is-active", optionIndex === index);
            });

            active = index;

            if (options[index]) {
                options[index].scrollIntoView({ block: "nearest" });
            }
        }

        function choose(index) {
            const item = items[index];

            if (!item) {
                return;
            }

            close();

            if (item.addNew) {
                if (typeof settings.onAddNew === "function") {
                    settings.onAddNew(item.name);
                }

                return;
            }

            input.value = "";

            if (typeof settings.onPick === "function") {
                settings.onPick(item.material);
            }
        }

        function render(materials, typed) {
            const excluded = typeof settings.exclude === "function" ? settings.exclude() : [];

            items = materials
                .filter(function (material) {
                    return excluded.indexOf(material.material_id) === -1;
                })
                .map(function (material) {
                    return { material: material };
                });

            const exact = materials.some(function (material) {
                return material.name.toLowerCase() === typed.toLowerCase();
            });

            if (typed && !exact && typeof settings.onAddNew === "function") {
                items.push({ addNew: true, name: typed });
            }

            menu.replaceChildren();

            if (!items.length) {
                const empty = document.createElement("li");

                empty.className = "material-picker-empty";
                empty.textContent = typed ? "No matches." : "No materials listed.";
                menu.appendChild(empty);
            }

            items.forEach(function (item, index) {
                const option = document.createElement("li");

                option.className = "material-picker-option" + (item.addNew ? " is-add" : "");
                option.setAttribute("role", "option");
                option.dataset.index = String(index);

                if (item.addNew) {
                    const label = document.createElement("span");

                    label.innerHTML = '<i class="bi bi-plus-circle me-1" aria-hidden="true"></i>';
                    label.appendChild(document.createTextNode('Add "' + item.name + '" to list'));
                    option.appendChild(label);
                } else {
                    const label = document.createElement("span");
                    const meta = document.createElement("span");

                    label.className = "material-picker-option-label";
                    label.textContent = item.material.label;
                    meta.className = "material-picker-option-meta";
                    meta.textContent = metaLine(item.material);

                    option.append(label, meta);
                }

                // mousedown, not click: the input's blur would close the
                // menu before a click ever landed on it.
                option.addEventListener("mousedown", function (event) {
                    event.preventDefault();
                    choose(index);
                });

                menu.appendChild(option);
            });

            menu.classList.remove("d-none");
            highlight(items.length ? 0 : -1);
        }

        function search() {
            const typed = input.value.trim();
            const number = ++requestNumber;
            const url = settings.url + (settings.url.indexOf("?") === -1 ? "?" : "&") + "q=" + encodeURIComponent(typed);

            fetch(url, {
                credentials: "same-origin",
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            })
                .then(function (response) {
                    return response.ok ? response.json() : { materials: [] };
                })
                .catch(function () {
                    return { materials: [] };
                })
                .then(function (body) {
                    // A slower earlier answer must not replace a newer one.
                    if (number !== requestNumber || document.activeElement !== input) {
                        return;
                    }

                    render(body.materials || [], typed);
                });
        }

        input.addEventListener("input", function () {
            clearTimeout(timer);
            timer = setTimeout(search, DEBOUNCE);
        });

        input.addEventListener("focus", search);

        input.addEventListener("blur", function () {
            setTimeout(close, 120);
        });

        input.addEventListener("keydown", function (event) {
            if (menu.classList.contains("d-none")) {
                if (event.key === "ArrowDown") {
                    search();
                }

                return;
            }

            if (event.key === "ArrowDown") {
                event.preventDefault();
                highlight(Math.min(active + 1, items.length - 1));
            } else if (event.key === "ArrowUp") {
                event.preventDefault();
                highlight(Math.max(active - 1, 0));
            } else if (event.key === "Enter") {
                event.preventDefault();
                choose(active);
            } else if (event.key === "Escape") {
                event.preventDefault();
                close();
            }
        });
    }

    global.materialPicker = {
        attach: attach,
        quickAdd: quickAdd,
        formatQty: formatQty,
        metaLine: metaLine,
    };
})(window);
