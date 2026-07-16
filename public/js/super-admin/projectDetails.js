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

});