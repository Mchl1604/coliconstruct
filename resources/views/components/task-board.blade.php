@props([
    'projects',
    // project_id => how many tasks the reader can see on it, for each card's
    // header.
    'taskCounts' => [],
    // project_id => (attention chip => how many of its tasks the chip leaves),
    // so a chip drops the cards it empties without asking every card first.
    // See App\Services\TaskBoard::gapCounts().
    'gapCounts' => [],
    // Route name for one page of one project's board, taking the project.
    'tableRoute',
    // Route name for a project's own page; the card's reference number
    // links to it. Null leaves it as plain text.
    'projectRoute' => null,
    // The dialogs of a task a notification links to (?task=), rendered up
    // front so it opens before its card has loaded. See TaskBoard::dialogs().
    'linkedTaskDialogs' => '',
    'emptyMessage' => 'There are no projects to show.',
])

{{--
    The task board: one card per project, each holding its own table.

    Shared by the Super Admin Tasks page and the lead technician Tasks page so
    the two read identically. Each card's table is paged on the server and
    asks for its first page when the card comes into view - see taskBoard.js
    and App\Services\TaskBoard - so the page itself carries no tasks at all,
    and nothing about a project nobody scrolls to is ever drawn.
--}}
<div data-task-board>
    @forelse ($projects as $project)
        @php
            $taskCount = (int) ($taskCounts[$project->project_id] ?? 0);
        @endphp

        <div class="card shadow-sm border-0 rounded-2 mb-3" data-project-card
            data-project-id="{{ $project->project_id }}"
            data-gap-counts='@json((object) ($gapCounts[$project->project_id] ?? []))'>
            <div class="card-body p-3">

                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <div class="technician-eyebrow">
                            @if ($projectRoute)
                                <a href="{{ route($projectRoute, $project->project_id) }}"
                                    class="text-primary text-decoration-none" title="Open project details">{{ $project->reference_no }}</a>
                            @else
                                {{ $project->reference_no }}
                            @endif
                        </div>
                        <h5 class="fw-bold mb-1">{{ $project->name }}</h5>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <x-project-status-badge :project="$project" />
                            <span class="text-muted small">
                                {{ $taskCount }}
                                {{ \Illuminate\Support\Str::plural('task', $taskCount) }}
                            </span>
                        </div>
                        {{-- Why this project is greyed out in the Add Task
                             dialog: new work is filed under a phase. --}}
                        @unless ($project->phasesAreFinalized())
                            <div class="text-secondary small mt-2">
                                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                                Finalize phases to add tasks.
                            </div>
                        @endunless
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle mb-0" data-project-tasks-table
                        data-server-table-url="{{ route($tableRoute, $project->project_id) }}">
                        <thead class="table-info">
                            <tr>
                                <th>Task Name</th>
                                <th>Phase</th>
                                <th>Assigned Technician</th>
                                <th>Start Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>

                        {{-- Filled a page at a time by serverTable.js. --}}
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- The dialogs behind this card's rows on screen, swapped in with
             every page. Outside the card's table: a <div> is not valid inside
             a <tbody>. --}}
        <div data-task-dialogs="{{ $project->project_id }}"></div>
    @empty
        <div class="card shadow-sm border-0 rounded-2">
            <div class="card-body">
                <div class="schedule-empty-state">{{ $emptyMessage }}</div>
            </div>
        </div>
    @endforelse

    {{-- Shown when the filter leaves nothing on screen. --}}
    <div class="card shadow-sm border-0 rounded-2 d-none" data-board-no-match>
        <div class="card-body">
            <div class="schedule-empty-state">No project matches that filter.</div>
        </div>
    </div>

    {{-- The task a notification links to, if any - see openTaskFromQuery.js. --}}
    <div data-linked-task-dialogs>{!! $linkedTaskDialogs !!}</div>
</div>
