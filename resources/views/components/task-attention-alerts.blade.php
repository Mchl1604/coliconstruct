@props([
    // The TaskAssignmentGaps::summarise() payload: total, counts, lines.
    'summary',
    'title' => 'Urgent Actions',
    // Shown under the chips when the viewer cannot fix any of it themselves,
    // so the alert still names the problem without implying they can act.
    'readOnlyNote' => null,
])

@php
    $total = (int) ($summary['total'] ?? 0);
    $counts = $summary['counts'] ?? [];
    $lines = $summary['lines'] ?? [];
@endphp

{{--
    Tasks that cannot proceed because they are incomplete, and the chips that
    narrow the board below to them.

    Shared by the Super Admin Tasks page and the lead technician Tasks page.
    The lead has no dashboard of their own and is not getting one for this, so
    this sits at the top of the page they already work from; the Super Admin
    page shows the same panel, reached from the dashboard's Urgent Actions.

    Nothing is drawn when there is nothing wrong. This is the whole of the
    "alert disappears when the problem is fixed" behaviour: the panel is the
    current state of the task rows read on each load, not a stored alert
    somebody has to go and dismiss. Assign a technician and the chip's count
    drops; fill in both and the task leaves the panel entirely.
--}}
@if ($total > 0)
    <div class="card shadow-sm border-0 rounded-2 mb-3 task-attention" data-task-attention>
        <div class="card-body p-3">

            <div class="d-flex align-items-start gap-2 flex-wrap">
                <span class="task-attention-icon" aria-hidden="true">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </span>

                <div class="flex-grow-1">
                    <h6 class="fw-bold mb-1">{{ $title }}</h6>
                    <p class="text-secondary small mb-0">
                        {{ \App\Services\TaskAssignmentGaps::headline($total) }}
                    </p>
                </div>

                {{-- Clears the filter and puts the whole board back. Only
                     offered once a chip is actually on, so it is never a
                     button that does nothing. --}}
                <button type="button" class="btn btn-sm btn-link text-decoration-none d-none"
                    data-task-gap-clear>
                    Show all tasks
                </button>
            </div>

            {{-- One chip per gap that has something in it, worst first. A gap
                 with nothing in it is absent rather than drawn as a zero. --}}
            <div class="task-attention-chips mt-2" role="group" aria-label="Filter tasks by what they are missing">
                <button type="button" class="task-attention-chip is-all" data-task-gap-chip="all"
                    aria-pressed="false">
                    <span class="task-attention-chip-count">{{ $total }}</span>
                    All affected
                </button>

                @foreach ($lines as $line)
                    <button type="button" class="task-attention-chip gap-{{ $line['gap'] }}"
                        data-task-gap-chip="{{ $line['gap'] }}" aria-pressed="false"
                        title="{{ $line['text'] }}">
                        <span class="task-attention-chip-count">{{ $line['count'] }}</span>
                        {{ $line['label'] }}
                    </button>
                @endforeach
            </div>

            {{-- The same figures as prose, for the reader who wants the
                 breakdown without hovering a chip. --}}
            <ul class="task-attention-lines small text-secondary mt-2 mb-0">
                @foreach ($lines as $line)
                    <li>{{ $line['text'] }}</li>
                @endforeach
            </ul>

            @if ($readOnlyNote)
                <p class="small text-secondary mt-2 mb-0">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                    {{ $readOnlyNote }}
                </p>
            @endif

            {{-- Read by taskBoard.js so the counts survive a chip being
                 pressed - the chips filter rows, they do not re-query. --}}
            <span class="d-none" data-task-attention-counts='@json($counts)'></span>
        </div>
    </div>
@endif
