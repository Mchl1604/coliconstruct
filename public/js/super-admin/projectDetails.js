document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('projectTypesContainer');
    const inputs = document.getElementById('projectTypesInputs');

    document.addEventListener('click', function (e) {

        // Remove
        if (e.target.classList.contains('remove-project-type')) {

            const id = e.target.dataset.typeId;

            container.querySelector(`[data-type-id="${id}"]`).remove();

            inputs.querySelector(`input[data-type-id="${id}"]`).remove();

        }

        // Add
        if (e.target.classList.contains('add-project-type')) {

            const id = e.target.dataset.typeId;
            const name = e.target.dataset.typeName;

            if (inputs.querySelector(`input[data-type-id="${id}"]`))
                return;

            container.insertAdjacentHTML(
                'beforeend',
                `
                <span class="badge bg-primary d-flex align-items-center px-3 py-2"
                      data-type-id="${id}">

                    ${name}

                    <button type="button"
                            class="btn-close btn-close-white ms-2 remove-project-type"
                            data-type-id="${id}">
                    </button>

                </span>
                `
            );

            inputs.insertAdjacentHTML(
                'beforeend',
                `
                <input type="hidden"
                       name="project_types[]"
                       value="${id}"
                       data-type-id="${id}">
                `
            );

            e.target.parentElement.remove();

        }

    });
document.getElementById('reportImages').addEventListener('change', function () {

    const preview = document.getElementById('imagePreview');
    preview.innerHTML = '';

    Array.from(this.files).forEach(file => {

        const reader = new FileReader();

        reader.onload = function(e){

            preview.innerHTML += `
                <div class="col-md-3">
                    <div class="card">
                        <img src="${e.target.result}"
                             class="card-img-top"
                             style="height:160px;object-fit:cover;">
                    </div>
                </div>
            `;
        };

        reader.readAsDataURL(file);

    });

});

let tasksTable;

$('button[data-bs-target="#tasks"]').on('shown.bs.tab', function () {

    if (!$.fn.DataTable.isDataTable('#tasksTable')) {

        tasksTable = $('#tasksTable').DataTable({
            responsive: true,
            autoWidth: false,
            pageLength: 5,
            lengthMenu: [5, 10, 25, 50],
            info: false,
            language: {
                search: "",
                searchPlaceholder: "Search tasks..."
            }
        });

    } else {

        tasksTable.columns.adjust().responsive.recalc();

    }

});

document.getElementById('taskStartDate').addEventListener('change', function () {

    document.getElementById('taskDueDate').min = this.value;

});

$(function () {

    const taskTable = $('#tasksTable').DataTable({

        responsive: true,
        autoWidth: false,
        pageLength: 5,
        lengthMenu: [5,10,25,50],
        info: false,

        language:{

            search:"",
            searchPlaceholder:"Search tasks..."

        }

    });

});

    const form = document.querySelector('[data-team-form]');

    if (!form) {
        return;
    }

    const teamData = Array.isArray(window.assignedTeamData) ? window.assignedTeamData : [];
    const initialState = window.assignedTeamState || { leadTechId: null, technicianIds: [] };
    const technicianLookup = new Map(teamData.map(function(technician) {
        return [String(technician.id), technician];
    }));

    const leadTechSelect = form.querySelector('[data-lead-tech-select]');
    const leadTechError = form.querySelector('[data-lead-tech-error]');
    const dropdownButton = form.querySelector('[data-technician-dropdown-button]');
    const dropdownMenu = form.querySelector('[data-technician-dropdown-menu]');
    const selectedList = form.querySelector('[data-technician-selected-list]');
    const hiddenInputsContainer = form.querySelector('[data-technician-hidden-inputs]');

    function selectedTechnicianIds() {
        return Array.from(hiddenInputsContainer.querySelectorAll('input[type="hidden"]')).map(function(input) {
            return input.value;
        });
    }

    function selectedTechnicians() {
        return selectedTechnicianIds()
            .map(function(technicianId) {
                return technicianLookup.get(String(technicianId));
            })
            .filter(Boolean);
    }

    function renderDropdown() {
        const selectedIds = selectedTechnicianIds();
        const leadId = leadTechSelect.value;

        const available = teamData.filter(function(technician) {
            return technician.role !== 'lead_technician'
                && !selectedIds.includes(String(technician.id))
                && String(technician.id) !== String(leadId);
        });

        dropdownMenu.innerHTML = available.length
            ? available.map(function(technician) {
                return '<li><button type="button" class="dropdown-item" ' +
                    'data-technician-option="' + technician.id + '">' +
                    technician.name + '</button></li>';
            }).join('')
            : '<li><span class="dropdown-item-text text-secondary">No technicians available.</span></li>';

        dropdownMenu.querySelectorAll('[data-technician-option]').forEach(function(button) {
            button.addEventListener('click', function() {
                addTechnician(button.dataset.technicianOption);
            });
        });
    }

    function renderChips() {
        const selected = selectedTechnicians();
        selectedList.innerHTML = '';

        if (!selected.length) {
            const emptyState = document.createElement('div');
            emptyState.className = 'technician-empty-state';
            emptyState.textContent = 'No technicians selected yet.';
            selectedList.appendChild(emptyState);
        } else {
            selected.forEach(function(technician) {
                const chip = document.createElement('span');
                chip.className = 'technician-chip';
                chip.textContent = technician.name;

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'technician-chip-remove';
                removeButton.setAttribute('aria-label', 'Remove ' + technician.name);
                removeButton.innerHTML = '<i class="bi bi-x" aria-hidden="true"></i>';
                removeButton.addEventListener('click', function() {
                    removeTechnician(String(technician.id));
                });

                chip.appendChild(removeButton);
                selectedList.appendChild(chip);
            });
        }

        dropdownButton.textContent = selected.length ? selected.length + ' selected' : 'Select technicians';
    }

    function addTechnician(technicianId) {
        if (selectedTechnicianIds().includes(String(technicianId))) {
            return;
        }

        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'technicians[]';
        hiddenInput.value = technicianId;
        hiddenInputsContainer.appendChild(hiddenInput);

        renderChips();
        renderDropdown();
    }

    function removeTechnician(technicianId) {
        const hiddenInputs = Array.from(hiddenInputsContainer.querySelectorAll('input[type="hidden"]'));
        const hiddenInput = hiddenInputs.find(function(input) {
            return input.value === technicianId;
        });

        if (hiddenInput) {
            hiddenInput.remove();
        }

        renderChips();
        renderDropdown();
    }

    function seedInitialTechnicians() {
        (initialState.technicianIds || []).forEach(function(technicianId) {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'technicians[]';
            hiddenInput.value = technicianId;
            hiddenInputsContainer.appendChild(hiddenInput);
        });
    }

    leadTechSelect.addEventListener('change', function() {
        leadTechError.classList.add('d-none');
        leadTechSelect.setCustomValidity('');
        renderDropdown();
    });

    form.addEventListener('submit', function(event) {
        if (!leadTechSelect.value) {
            event.preventDefault();
            leadTechError.classList.remove('d-none');
            leadTechSelect.setCustomValidity('A lead technician is required.');
            leadTechSelect.reportValidity();
        }
    });

    seedInitialTechnicians();
    renderChips();
    renderDropdown();



});