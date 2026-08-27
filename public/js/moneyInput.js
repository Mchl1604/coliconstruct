/**
 * Thousands separators while an amount is being typed.
 *
 * A quotation is the one figure on these forms long enough to be misread:
 * 1500000 and 150000 are a digit apart and an order of magnitude apart, and
 * nobody counts zeros correctly at a glance. Grouping them as they are typed
 * is the whole of the fix.
 *
 * The field the user types into carries the commas and therefore cannot be
 * submitted - `numeric` validation refuses "1,500,000.00". So the pair works
 * like this:
 *
 *   [data-money-field]          the wrapper holding both
 *     [data-money-input]        visible, type="text", carries the commas
 *     [data-money-value]        hidden, carries the raw number, holds the name
 *
 * The server therefore receives exactly what it always received, and nothing
 * about the validation rules changes. `required` lives on the visible input,
 * because a hidden field cannot be reported by the browser's own validation.
 *
 * Used by the create-project wizard and the Project Details editor, so an
 * amount reads the same wherever it is typed.
 */
(function () {
    'use strict';

    /** At most one dot, digits only, at most two decimal places. */
    function clean(value) {
        var text = String(value).replace(/[^\d.]/g, '');
        var parts = text.split('.');

        if (parts.length === 1) {
            return parts[0];
        }

        // A second dot is dropped rather than the rest of the number with it.
        return parts[0] + '.' + parts.slice(1).join('').slice(0, 2);
    }

    /** "1500000.5" -> "1,500,000.5". The decimals are never grouped. */
    function group(raw) {
        if (raw === '') {
            return '';
        }

        var parts = raw.split('.');
        var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        return parts.length > 1 ? whole + '.' + parts[1] : whole;
    }

    /** How many digits sit to the left of the caret. */
    function digitsBefore(text, caret) {
        return (text.slice(0, caret).match(/[\d.]/g) || []).length;
    }

    /** Where the caret goes to sit after that many digits again. */
    function caretAfter(text, digits) {
        var seen = 0;

        for (var i = 0; i < text.length; i++) {
            if (/[\d.]/.test(text[i])) {
                seen += 1;
            }

            if (seen >= digits) {
                return i + 1;
            }
        }

        return text.length;
    }

    function setup(input) {
        var field = input.closest('[data-money-field]');
        var hidden = field ? field.querySelector('[data-money-value]') : null;

        if (!hidden) {
            return;
        }

        var sync = function (keepCaret) {
            var before = input.value;
            var caret = input.selectionStart;
            var raw = clean(before);
            var shown = group(raw);

            hidden.value = raw;

            if (shown === before) {
                return;
            }

            input.value = shown;

            // Retyping the whole value moves the caret to the end, which on a
            // mid-number edit throws the typist to the wrong place. Counting
            // digits rather than characters survives the commas shifting.
            if (keepCaret && caret !== null && input === document.activeElement) {
                var position = caretAfter(shown, digitsBefore(before, caret));

                try {
                    input.setSelectionRange(position, position);
                } catch (error) {
                    // Some browsers refuse on a field that is not focusable
                    // yet; the value is already correct either way.
                }
            }
        };

        input.addEventListener('input', function () {
            sync(true);
        });

        // Leaving the field settles it: a stray trailing dot goes, and the
        // hidden value is what the server will be given.
        input.addEventListener('blur', function () {
            var raw = clean(input.value).replace(/\.$/, '');

            hidden.value = raw;
            input.value = group(raw);
        });

        // The hidden field is the one the server fills back in after a refused
        // save, so it seeds the visible one rather than the other way round -
        // otherwise an empty box would blank the amount the person just typed.
        if (input.value.trim() === '' && hidden.value !== '') {
            input.value = hidden.value;
        }

        // A value already in the field - an edit form, or input the server
        // handed back after a failed save - is grouped on arrival.
        sync(false);
    }

    function init() {
        document.querySelectorAll('[data-money-input]').forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
