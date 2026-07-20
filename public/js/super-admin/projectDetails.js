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


});