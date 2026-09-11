/**
 * The one date picker this application uses, everywhere.
 *
 * There used to be several. Some fields were the browser's own date input,
 * which looks different in every browser and cannot be cleared the same way
 * twice; the rest were flatpickr, configured separately on each page - three
 * different display formats, and three different "Clear" / "Reset" buttons
 * written three times. This file replaces all of that with one set of
 * defaults and one plugin, applied to every picker whoever creates it.
 *
 * What every picker gets:
 *
 *   - The same calendar, in the house style (datePicker.css).
 *   - Dates shown as "Sep 26, 2026" while the field still submits 2026-09-26.
 *     flatpickr's altInput does this: the real input keeps the ISO value the
 *     server parses and every page's comparisons are written against; the
 *     person sees and clicks a second, friendlier input.
 *   - A Clear button inside the calendar itself. Per field, because each end
 *     of a range is bounded by the other and only the person knows which one
 *     they meant to let go of; and inside the panel, because that is where they
 *     are looking when they find they cannot pick the date they want.
 *
 * How it reaches every picker:
 *
 *   - Pages that build their own flatpickr keep doing so. The defaults and the
 *     plugin are registered with flatpickr.setDefaults(), so those pickers pick
 *     them up without being edited. Plugin hooks are merged with a page's own
 *     hooks rather than replacing them, which is why this is a plugin and not
 *     an onReady default - a page's onReady would silently switch it off.
 *   - Any <input type="date"> nobody has turned into a picker is upgraded
 *     automatically, including ones added to the page later. Its min, max,
 *     readonly and disabled attributes carry over and stay live.
 *
 * Put `data-native-date` on an input to leave it alone.
 */
(function (global) {
    "use strict";

    if (!global.flatpickr) {
        return;
    }

    const flatpickr = global.flatpickr;

    /** What the server receives, and what every page compares against. */
    const VALUE_FORMAT = "Y-m-d";

    /** What a person reads. Short enough for the narrowest field that has one. */
    const DISPLAY_FORMAT = "M j, Y";

    const UPGRADE_SELECTOR = 'input[type="date"]:not([data-native-date]), input[data-date-picker]';

    /** The value setter inputs are born with, so ours can defer to it. */
    const nativeValue = Object.getOwnPropertyDescriptor(
        HTMLInputElement.prototype,
        "value",
    );

    // ------------------------------------------------------------------
    // The plugin every picker carries
    // ------------------------------------------------------------------

    function housePlugin(instance) {
        let observer = null;
        let clearButton = null;
        let ownsClasses = false;

        return {
            onReady: function () {
                instance.calendarContainer.classList.add("app-picker");

                decorateAltInput();
                addFooter();
                keepAltInStep();
                watchAttributes();
                refreshClear();
            },

            onOpen: function () {
                // A readonly field shows its date and keeps it. Refused here,
                // on every open, rather than by switching clickOpens off:
                // flatpickr reads that once when the picker is built, and pages
                // make fields readonly long after that.
                if (instance.input.readOnly) {
                    instance.close();

                    return;
                }

                refreshClear();
            },

            onChange: refreshClear,

            onDestroy: function () {
                if (observer) {
                    observer.disconnect();
                }

                // Hand the input back exactly as it was found.
                delete instance.input.value;
            },
        };

        /**
         * Make the visible half of the pair look like the field it replaced.
         *
         * flatpickr gives the alt input a fixed class list, which would turn a
         * small filter field into a full-size one and drop any validation state
         * the page puts on the real input. So unless the page chose classes of
         * its own (altInputClass), the real input's are copied, and kept copied.
         */
        function decorateAltInput() {
            const alt = instance.altInput;

            if (!alt) {
                return;
            }

            ownsClasses = !instance.config.altInputClass;
            copyClasses();

            alt.classList.add("app-date-input");

            if (!alt.placeholder) {
                alt.placeholder = "Select a date";
            }

            if (instance.input.title) {
                alt.title = instance.input.title;
            }

            // The <label for> still points at the real input, which is now
            // hidden. Name the visible one after the same label, so a screen
            // reader announces the field rather than "edit text".
            const label = labelFor(instance.input);

            if (label && !alt.hasAttribute("aria-label")) {
                alt.setAttribute("aria-label", label);
            }
        }

        function copyClasses() {
            if (!ownsClasses || !instance.altInput) {
                return;
            }

            const classes = instance.input.className
                .split(/\s+/)
                .filter(function (name) {
                    return name && name !== "flatpickr-input";
                });

            instance.altInput.className = classes
                .concat(["flatpickr-input", "app-date-input"])
                .join(" ");
        }

        function addFooter() {
            const footer = document.createElement("div");

            footer.className = "app-picker-footer";

            clearButton = document.createElement("button");
            // Explicitly not a submit button: most pickers live inside a form.
            clearButton.type = "button";
            clearButton.className = "app-picker-clear";
            clearButton.innerHTML =
                '<i class="bi bi-x-circle" aria-hidden="true"></i>Clear';

            const label = labelFor(instance.input);

            if (label) {
                clearButton.setAttribute("aria-label", "Clear " + label);
            }

            clearButton.addEventListener("click", function () {
                // Clearing fires the picker's own change handlers, so a page
                // that rebounds another field when this one changes does so on
                // a clear too - nothing else has to be told.
                instance.clear();
                instance.close();
            });

            footer.appendChild(clearButton);
            instance.calendarContainer.appendChild(footer);
        }

        /** Nothing to clear is said by the button, not discovered by pressing it. */
        function refreshClear() {
            if (clearButton) {
                clearButton.disabled = instance.selectedDates.length === 0;
            }
        }

        /**
         * Keep the visible date true when a page writes the value itself.
         *
         * Plenty of code in this application fills a date field by assigning
         * to input.value - opening an edit dialog, resetting a filter. With an
         * alt input that write lands on the hidden field and the one the person
         * is looking at goes on showing the old date. Rather than find and
         * rewrite every such line, the assignment itself is taught to tell the
         * picker.
         *
         * flatpickr writes the same property itself, and not always at a
         * moment when its own selection agrees: clear() empties the field
         * first and the selection second. Comparing the two on the spot would
         * see a page's write in that gap, call back into flatpickr, and clear
         * forever. So the comparison waits until whatever wrote the value has
         * finished - by then a write of flatpickr's own matches its selection
         * and nothing happens, and a page's write still differs and is picked
         * up.
         */
        function keepAltInStep() {
            const input = instance.input;
            let pending = false;

            Object.defineProperty(input, "value", {
                configurable: true,
                get: function () {
                    return nativeValue.get.call(this);
                },
                set: function (next) {
                    nativeValue.set.call(this, next);

                    if (pending) {
                        return;
                    }

                    pending = true;

                    queueMicrotask(function () {
                        pending = false;

                        const value = nativeValue.get.call(input);

                        if (value !== currentValue()) {
                            instance.setDate(value || null, false);
                            refreshClear();
                        }
                    });
                },
            });

            // form.reset() puts the real field back without going through the
            // setter above, so read it again once the reset has happened.
            if (input.form) {
                input.form.addEventListener("reset", function () {
                    setTimeout(function () {
                        instance.setDate(nativeValue.get.call(input) || null, false);
                        refreshClear();
                    }, 0);
                });
            }
        }

        function currentValue() {
            const date = instance.selectedDates[0];

            return date ? instance.formatDate(date, instance.config.dateFormat) : "";
        }

        /**
         * Pages switch fields on and off, and move one date's limits when the
         * other changes, by writing attributes onto the real input. Those
         * writes now have to reach the picker and the field that is showing.
         */
        function watchAttributes() {
            const input = instance.input;

            // What the field looked like before it was a picker.
            applyAttribute("readonly");

            observer = new MutationObserver(function (records) {
                records.forEach(function (record) {
                    applyAttribute(record.attributeName);
                });
            });

            observer.observe(input, {
                attributes: true,
                attributeFilter: ["disabled", "readonly", "required", "min", "max", "class", "title"],
            });
        }

        function applyAttribute(name) {
            const input = instance.input;
            const alt = instance.altInput;

            switch (name) {
                case "disabled":
                    if (alt) {
                        alt.disabled = input.disabled;
                    }
                    break;

                case "readonly":
                    // The alt input is always readonly unless typing is
                    // allowed - that is how flatpickr keeps people from typing
                    // a malformed date - so a readonly field is expressed by
                    // refusing to open (see onOpen) and by how it looks, not
                    // by the attribute.
                    if (alt) {
                        alt.classList.toggle("is-readonly", input.readOnly);
                    }
                    break;

                case "required":
                    if (alt) {
                        alt.required = input.required;
                    }
                    break;

                case "min":
                    instance.set("minDate", input.getAttribute("min") || null);
                    break;

                case "max":
                    instance.set("maxDate", input.getAttribute("max") || null);
                    break;

                case "class":
                    copyClasses();
                    break;

                case "title":
                    if (alt) {
                        alt.title = input.title;
                    }
                    break;
            }
        }
    }

    function labelFor(input) {
        if (input.getAttribute("aria-label")) {
            return input.getAttribute("aria-label");
        }

        if (input.id) {
            const label = document.querySelector('label[for="' + CSS.escape(input.id) + '"]');

            if (label) {
                return label.textContent.replace(/\s+/g, " ").replace(/\*$/, "").trim();
            }
        }

        const wrapping = input.closest("label");

        return wrapping ? wrapping.textContent.replace(/\s+/g, " ").trim() : "";
    }

    flatpickr.setDefaults({
        dateFormat: VALUE_FORMAT,
        altInput: true,
        altFormat: DISPLAY_FORMAT,
        // Empty on purpose: the plugin copies the real input's classes, so a
        // small field stays small. A page that names classes here keeps them.
        altInputClass: "",
        // The same calendar on a phone as on a desktop. flatpickr would
        // otherwise hand phones the browser's native picker, which is exactly
        // the inconsistency this file exists to remove.
        disableMobile: true,
        prevArrow: '<i class="bi bi-chevron-left" aria-hidden="true"></i>',
        nextArrow: '<i class="bi bi-chevron-right" aria-hidden="true"></i>',
        plugins: [housePlugin],
    });

    // ------------------------------------------------------------------
    // Upgrading plain date inputs
    // ------------------------------------------------------------------

    function upgrade(input) {
        if (input._flatpickr || input.hasAttribute("data-native-date")) {
            return;
        }

        const options = {};

        if (input.getAttribute("min")) {
            options.minDate = input.getAttribute("min");
        }

        if (input.getAttribute("max")) {
            options.maxDate = input.getAttribute("max");
        }

        // A date input left as type="date" would open the browser's calendar
        // on top of this one.
        if (input.type === "date") {
            input.type = "text";
        }

        flatpickr(input, options);
    }

    function upgradeWithin(root) {
        if (root.matches && root.matches(UPGRADE_SELECTOR)) {
            upgrade(root);
        }

        if (root.querySelectorAll) {
            root.querySelectorAll(UPGRADE_SELECTOR).forEach(upgrade);
        }
    }

    /**
     * A picker's calendar lives on <body>, not beside its field, so removing
     * the field does not remove the calendar. Screens that redraw their rows
     * would otherwise leave one orphaned calendar behind per date field per
     * redraw.
     */
    function releaseWithin(root) {
        const inputs = [];

        if (root._flatpickr) {
            inputs.push(root);
        }

        if (root.querySelectorAll) {
            root.querySelectorAll("input").forEach(function (input) {
                if (input._flatpickr) {
                    inputs.push(input);
                }
            });
        }

        inputs.forEach(function (input) {
            input._flatpickr.destroy();
        });
    }

    function watchDocument() {
        const observer = new MutationObserver(function (records) {
            records.forEach(function (record) {
                record.removedNodes.forEach(function (node) {
                    // A node that is back in the page by now was moved, not
                    // removed, and its picker is still wanted.
                    if (node.nodeType === 1 && !node.isConnected) {
                        releaseWithin(node);
                    }
                });

                record.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        upgradeWithin(node);
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    function start() {
        // After every other DOMContentLoaded handler, so a page that builds its
        // own picker on a date field does so first and this does not build one
        // only for it to be thrown away.
        setTimeout(function () {
            upgradeWithin(document);
            watchDocument();
        }, 0);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start);
    } else {
        start();
    }

    global.appDatePicker = {
        upgrade: upgrade,
        upgradeWithin: upgradeWithin,
    };
})(window);
