@props([
    'task',
    // Technicians the task may be reassigned to. Empty when the viewer is not
    // allowed to reassign it.
    'technicians' => collect(),
    'activeTaskCounts' => collect(),
    'scheduleRanges' => collect(),
    // The route the edit form posts to. Null means view only, which is also
    // what a completed task gets however it is opened.
    'updateAction' => null,
    'updateMethod' => 'PUT',
])

@php
    $isCompleted = $task->isCompleted();
    $isEditable = $updateAction !== null && ! $isCompleted;

@endphp

{{--
    One task, viewed or edited, shared by the Super Admin portal and the
    technician portal so a task reads the same wherever it is opened.

    A completed task is always view only, and shows the notes and photos the
    technician submitted when they closed it.

    The form IS the modal content rather than a wrapper inside it: a wrapper
    breaks the flex chain modal-dialog-scrollable relies on, and the body then
    silently clips everything past the fold instead of scrolling. The
    completion photos sit right at the bottom, so they were the first thing to
    disappear.
--}}
<div class="modal fade" id="taskModal{{ $task->task_id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content"
            @if ($isEditable) action="{{ $updateAction }}" method="POST" @else action="javascript:void(0);" @endif>
            @csrf
            @if ($isEditable)
                @method($updateMethod)
            @endif

            <div class="modal-header {{ $isCompleted ? 'bg-success' : 'bg-primary' }} text-white">
                <h5 class="modal-title">
                    <i class="bi bi-list-task me-2" aria-hidden="true"></i>
                    {{ $isEditable ? 'Edit Task' : 'View Task' }}
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <div class="mb-3">
                    <label class="form-label fw-semibold">Task Name</label>
                    <input type="text" class="form-control" name="task_title"
                        value="{{ $task->task_title }}" @readonly(! $isEditable)>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea class="form-control" rows="4" name="task_description"
                        @readonly(! $isEditable)>{{ $task->task_description }}</textarea>
                </div>

                <div class="row" data-task-date-row data-schedule-ranges='@json($scheduleRanges)'>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Start Date</label>
                        <input type="text" class="form-control" name="start_date"
                            value="{{ $task->start_date }}" data-task-start
                            placeholder="Select start date" @readonly(! $isEditable)>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Due Date</label>
                        <input type="text" class="form-control" name="due_date"
                            value="{{ $task->due_date }}" data-task-due placeholder="Select due date"
                            @readonly(! $isEditable)>
                    </div>
                </div>

                <hr>

                <label class="form-label fw-bold mb-3">Assigned Technician</label>

                @if ($isEditable)
                    <div class="task-assign-row">
                        @forelse ($technicians as $technician)
                            @php
                                $activeCount = $activeTaskCounts[$technician->technician_id] ?? 0;
                                $holdsThisTask = $task->technician_id == $technician->technician_id;
                                // An account that cannot sign in cannot open the
                                // project, close the task, or read the notice
                                // saying it has one - so it is not somebody work
                                // can be moved TO. Whoever already holds the task
                                // stays selectable, because saving an edit
                                // re-submits the owner and the handover off them
                                // is the very thing this dialog is for.
                                $cannotReceiveWork = ! $technician->isAssignable() && ! $holdsThisTask;
                            @endphp
                            <label>
                                <input type="radio" class="btn-check" name="technician_id"
                                    value="{{ $technician->technician_id }}"
                                    @checked($holdsThisTask) @disabled($cannotReceiveWork)>

                                <div class="task-assign-card">
                                    <x-user-avatar :user="$technician->account" size="lg"
                                        class="task-assign-avatar" />
                                    <div class="task-assign-name">{{ $technician->name }}</div>
                                    <div class="task-assign-count">
                                        {{ $activeCount }}
                                        Active Task{{ $activeCount == 1 ? '' : 's' }}
                                    </div>
                                    @if (optional($technician->account)->role === 'lead_technician')
                                        <span class="badge bg-primary task-assign-lead">Lead</span>
                                    @endif
                                    @unless ($technician->isAssignable())
                                        <span class="badge bg-warning text-dark task-assign-inactive">
                                            Account inactive
                                        </span>
                                    @endunless
                                </div>
                            </label>
                        @empty
                            <span class="text-muted small">
                                This project has no assigned technicians.
                            </span>
                        @endforelse
                    </div>
                @else
                    {{-- View only, so only the technician who actually holds the
                         task is shown. A greyed-out row of everyone else reads
                         like a choice that is merely disabled. --}}
                    @if ($task->technician)
                        <div class="task-assign-static">
                            <x-user-avatar :user="$task->technician->account" size="lg"
                                class="task-assign-avatar" />
                            <div>
                                <div class="task-assign-name">{{ $task->technician->name }}</div>
                                @if (optional($task->technician->account)->role === 'lead_technician')
                                    <span class="badge bg-primary">Lead Technician</span>
                                @else
                                    <span class="text-muted small">Technician</span>
                                @endif
                            </div>
                        </div>
                    @else
                        <p class="text-muted mb-0">
                            This task is not assigned to anyone.
                        </p>
                    @endif
                @endif

                {{-- What the technician submitted when they closed the task.
                     Tinted green so it reads as the outcome of the task rather
                     than another field of it. --}}
                @if ($isCompleted)
                    <hr>

                    @php
                        $closedOnBehalf = $task->wasClosedOnBehalf();
                        $closer = $task->completedBy;
                    @endphp

                    <div class="task-completion">
                        <h6 class="task-completion-heading">
                            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                            Completion Details
                        </h6>

                        {{-- Closed by somebody other than the technician holding it,
                             so say so plainly rather than leaving a reader to wonder
                             why the record is thin. --}}
                        @if ($closedOnBehalf)
                            <div class="task-completion-notice">
                                <i class="bi bi-person-check-fill" aria-hidden="true"></i>
                                <div>
                                    Marked complete by <strong>{{ $closer->fullName() }}</strong>
                                    ({{ $closer->roleLabel() }}) on behalf of
                                    <strong>{{ $task->technician?->name ?? 'the assigned technician' }}</strong>.
                                    @unless (filled($task->completion_notes) && $task->images->isNotEmpty())
                                        The assigned technician did not submit completion details, so
                                        they are not required here.
                                    @endunless
                                </div>
                            </div>
                        @endif

                        <div class="task-completion-field">
                            <span class="task-completion-label">Completion Description</span>
                            @if (filled($task->completion_notes))
                                <p class="mb-0">{{ $task->completion_notes }}</p>
                            @elseif ($closedOnBehalf)
                                <p class="text-muted mb-0">
                                    None submitted &mdash; the task was closed on the technician's behalf.
                                </p>
                            @else
                                <p class="text-muted mb-0">
                                    No completion description was recorded for this task.
                                </p>
                            @endif
                        </div>

                        <div class="task-completion-field">
                            <span class="task-completion-label">Completed On</span>
                            @if ($task->completed_at)
                                <p class="mb-0">
                                    {{ \App\Support\BusinessTime::format($task->completed_at) }}
                                    @if ($closer && ! $closedOnBehalf)
                                        <span class="text-muted">&middot; by {{ $closer->fullName() }}</span>
                                    @endif
                                </p>
                            @else
                                {{-- Work closed before this system recorded when and by whom.
                                     Said plainly rather than left as an absent row: a missing
                                     field reads as a page that forgot to draw something,
                                     where "not recorded" is a fact about the record. --}}
                                <p class="text-muted mb-0">
                                    Not recorded &mdash; this task was completed before the system
                                    kept a completion date.
                                </p>
                            @endif
                        </div>

                        <span class="task-completion-label">
                            <i class="bi bi-images me-1" aria-hidden="true"></i>
                            Completion Images
                        </span>

                        @if ($task->images->isNotEmpty())
                            <div class="row g-3 mt-0">
                                @foreach ($task->images as $image)
                                    <div class="col-lg-4 col-md-6">
                                        <a href="{{ $image->url() }}" target="_blank"
                                            rel="noopener noreferrer">
                                            <img src="{{ $image->url() }}"
                                                class="img-fluid rounded shadow-sm task-completion-image"
                                                alt="Completion photo">
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        @elseif ($closedOnBehalf)
                            <p class="text-muted mb-0">
                                None submitted &mdash; the task was closed on the technician's behalf.
                            </p>
                        @else
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-circle me-2" aria-hidden="true"></i>
                                <strong>No image available.</strong>
                                This task was marked as completed without any uploaded completion images.
                            </div>
                        @endif
                    </div>
                @endif

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>

                @if ($isEditable)
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1" aria-hidden="true"></i>
                        Save Changes
                    </button>
                @endif
            </div>
        </form>
    </div>
</div>
