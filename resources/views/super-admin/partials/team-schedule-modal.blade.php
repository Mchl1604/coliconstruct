{{--
    One technician's changes still to come on this project - days off, leaving,
    starting, returning, covering as lead - each with its own Cancel.

    @param Technician $technician
    @param bool $isLead
    @param array<int, array<string, mixed>> $items  see Project::scheduledChangesFor()
    @param bool $canCancel
--}}
@php
    $modalId = 'teamSchedule'.$technician->technician_id;
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Title" aria-hidden="true"
    data-team-schedule-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div class="d-flex align-items-center gap-3 min-w-0">
                    <x-user-avatar :user="$technician->account" size="md" />

                    <div class="min-w-0">
                        <h5 class="modal-title mb-1" id="{{ $modalId }}Title">{{ $technician->name }}</h5>

                        @if ($isLead)
                            <span class="badge project-lead-badge">Lead Technician</span>
                        @else
                            <span class="badge bg-secondary">Technician</span>
                        @endif
                    </div>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="small text-uppercase text-secondary fw-semibold mb-2">Schedule on this project</div>

                @forelse ($items as $item)
                    <div class="team-schedule-item">
                        <span class="team-schedule-icon" aria-hidden="true">
                            <i class="bi {{ $item['icon'] }}"></i>
                        </span>

                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold">{{ $item['title'] }}</div>
                            <div class="text-secondary small">{{ $item['when'] }}</div>
                        </div>

                        @if ($canCancel)
                            @include('super-admin.partials.cancel-scheduled-team-change', [
                                'membership' => $item['span'],
                                'label' => $item['cancel'],
                                'part' => $item['part'],
                            ])
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">
                        Nothing scheduled. {{ $technician->name }} stays on this project with no days off or end date.
                    </p>
                @endforelse
            </div>

        </div>
    </div>
</div>
