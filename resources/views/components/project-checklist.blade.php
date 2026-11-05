{{--
    Project Activity -> Checklist: each unit's indoor and outdoor items. A lead
    on the project or the Super Admin ticks them, each saving on its own; anyone
    else who can see the project reads them. See projectChecklist.js.
--}}
@props(['project'])

@php
    $user = auth()->user();
    $canEdit = (bool) $user?->can('updateChecklist', $project);
    $saveUrl = $user?->isSuperAdmin()
        ? route('super-admin.projects.checklist.update', $project)
        : route('technician.projects.checklist.update', $project);
    $checklists = app(\App\Services\ServiceReport::class)->checklists($project);
    $total = count(\App\Support\ServiceReportForms::checklistItems());
@endphp

<div data-project-checklist data-save-url="{{ $saveUrl }}">
    <div class="alert alert-danger small py-2 d-none" role="alert" data-checklist-error></div>

    @if ($project->awaitingInitialSetup())
        <p class="text-secondary small mb-3">{{ \App\Models\Project::SET_UP_FIRST }}</p>
    @endif

    @foreach ($checklists as $checklist)
        <h6 class="fw-bold mb-2">{{ $checklist['type_name'] }}</h6>

        @forelse ($checklist['units'] as $unit)
            <div class="border rounded-3 p-3 mb-3" data-checklist-unit data-detail-id="{{ $unit['detail_id'] }}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold">{{ $unit['title'] }}</span>
                    <span class="small text-secondary"><span data-checklist-count>{{ count($unit['done']) }}</span> of {{ $total }} done</span>
                </div>

                <div class="row g-3">
                    @foreach (['Indoor Unit' => \App\Support\ServiceReportForms::INDOOR_CHECKLIST, 'Outdoor Unit' => \App\Support\ServiceReportForms::OUTDOOR_CHECKLIST] as $groupLabel => $items)
                        <div class="col-md-6">
                            <div class="small fw-semibold text-secondary mb-1">{{ $groupLabel }}</div>
                            @foreach ($items as $key => $label)
                                @php $inputId = 'checklist_'.$unit['detail_id'].'_'.$key; @endphp
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="{{ $inputId }}" value="{{ $key }}"
                                        data-checklist-item @checked(in_array($key, $unit['done'], true)) @disabled(! $canEdit)>
                                    <label class="form-check-label" for="{{ $inputId }}">{{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-secondary small mb-3">No units yet. Add them in View Project Details.</p>
        @endforelse
    @endforeach
</div>

@once
    <script src="/js/projectChecklist.js" defer></script>
@endonce
