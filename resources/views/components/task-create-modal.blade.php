@props([
    // Projects that can actually take a new task.
    'projects',
    // Both carry an __ID__ placeholder the script swaps for the chosen project.
    'formDataUrl',
    'storeUrl',
])

{{--
    Create a task, project first.

    One dialog per page rather than one per project card: the technician list
    and the selectable dates both depend on the project, so they are fetched
    once a project is picked. Shared by both portals - see taskCreate.js.
--}}
<div class="modal fade" id="addTaskModal" tabindex="-1" aria-hidden="true" data-task-create-modal
    data-form-data-url="{{ $formDataUrl }}" data-store-url="{{ $storeUrl }}">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content" method="POST" action="" data-task-create-form>
            @csrf

            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-list-task me-2" aria-hidden="true"></i>
                    Create Task
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <div class="mb-4">
                    <label class="form-label fw-semibold" for="createTaskProject">Project</label>
                    <select class="form-select" id="createTaskProject" data-task-create-project required>
                        <option value="" selected disabled>Select a project&hellip;</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->project_id }}">
                                {{ $project->reference_no }} &mdash; {{ $project->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        Choose the project first.
                    </div>
                    <div class="text-danger small mt-1 d-none" data-task-create-error></div>
                </div>

                <div class="d-none" data-task-create-fields>
                    <hr>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="createTaskTitle">Task Name</label>
                        <input type="text" class="form-control" id="createTaskTitle" name="task_title"
                            placeholder="Enter task title" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="createTaskDescription">Description</label>
                        <textarea class="form-control" id="createTaskDescription" name="task_description" rows="4"
                            placeholder="Describe the task..." required></textarea>
                    </div>

                    <div class="row" data-task-date-row>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold" for="createTaskStartDate">Start Date</label>
                            <input type="text" class="form-control" id="createTaskStartDate" name="start_date"
                                placeholder="Select start date" data-task-start required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold" for="createTaskDueDate">Due Date</label>
                            <input type="text" class="form-control" id="createTaskDueDate" name="due_date"
                                placeholder="Select due date" data-task-due required>
                        </div>

                        <div class="col-12 mb-3">
                            <div class="form-text" data-task-create-ranges></div>
                        </div>
                    </div>

                    <hr>

                    <label class="form-label fw-bold mb-3">Assign To</label>

                    <div class="task-assign-row" data-task-create-technicians></div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" data-task-create-submit disabled>
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                    Create Task
                </button>
            </div>
        </form>
    </div>
</div>
