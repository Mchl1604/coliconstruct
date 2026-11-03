/**
 * A project's address fields: Province, then City / Municipality, then
 * Barangay, each list fetched once the one before it is chosen.
 *
 * Shared by the create wizard and the Edit Details dialog. Listens on the
 * document, so it carries on working when projectWorkspace.js redraws the
 * dialog. The fields themselves are x-project-address-fields.
 */
(function (global) {
    'use strict';

    function reset(select, label) {
        select.length = 1;
        select.options[0].textContent = label;
        select.value = '';
    }

    function fill(select, names) {
        reset(select, select.dataset.readyLabel);

        names.forEach(function (name) {
            select.add(new Option(name, name));
        });
    }

    /**
     * Fetch the list for `target`. A slower answer to an earlier choice is
     * dropped, so it cannot overwrite the list for the current one.
     */
    function load(fields, target, query) {
        const request = (Number(fields.dataset.addressRequest) || 0) + 1;
        const error = fields.querySelector('[data-address-error]');
        const url = new URL(fields.dataset.optionsUrl, global.location.origin);

        fields.dataset.addressRequest = String(request);
        error.classList.add('d-none');

        Object.keys(query).forEach(function (key) {
            url.searchParams.set(key, query[key]);
        });

        fetch(url, { headers: { Accept: 'application/json' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Address options failed.');
                }

                return response.json();
            })
            .then(function (data) {
                if (Number(fields.dataset.addressRequest) === request) {
                    fill(target, data.options);
                }
            })
            .catch(function () {
                if (Number(fields.dataset.addressRequest) === request) {
                    error.classList.remove('d-none');
                }
            });
    }

    function announce(fields) {
        fields.dispatchEvent(new Event('input', { bubbles: true }));
    }

    document.addEventListener('change', function (event) {
        const fields = event.target.closest ? event.target.closest('[data-address-fields]') : null;

        if (!fields) {
            return;
        }

        const province = fields.querySelector('[data-address-province]');
        const municipality = fields.querySelector('[data-address-municipality]');
        const barangay = fields.querySelector('[data-address-barangay]');

        if (event.target === province) {
            reset(municipality, municipality.dataset.emptyLabel);
            reset(barangay, barangay.dataset.emptyLabel);
            announce(fields);

            if (province.value) {
                load(fields, municipality, { province: province.value });
            }
        }

        if (event.target === municipality) {
            reset(barangay, barangay.dataset.emptyLabel);
            announce(fields);

            if (municipality.value) {
                load(fields, barangay, { province: province.value, municipality: municipality.value });
            }
        }
    });

    /**
     * The one line ProjectAddress::compose() saves, for the wizard's Review.
     * Empty until a barangay is chosen.
     */
    function format(fields) {
        const value = function (selector) {
            return fields.querySelector(selector).value.trim();
        };
        const barangay = value('[data-address-barangay]');

        if (!barangay) {
            return '';
        }

        return [
            [value('[data-address-house-no]'), value('[data-address-street]')].filter(Boolean).join(' '),
            barangay.indexOf('Barangay ') === 0 ? barangay : 'Brgy. ' + barangay,
            value('[data-address-municipality]'),
            value('[data-address-province]'),
        ].filter(Boolean).join(', ');
    }

    global.AddressFields = { format: format };
})(window);
