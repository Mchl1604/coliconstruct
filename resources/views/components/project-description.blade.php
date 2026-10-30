{{--
    A project's description as every portal shows it: a one-line summary of
    each type's detail form, the details themselves folded away until asked
    for, and the free-text Notes.

    Folded rather than laid out in full, because a job with a dozen units
    would otherwise push the rest of the page out of sight. Read as saved -
    ProjectDetailForms::summary() - so a field renamed or archived since still
    shows under its old label.

    $editTarget: the id of the dialog that edits it, or null where the reader
    may not.
--}}
@props([
    'project',
    'editTarget' => null,
])

@php
    $summary = app(\App\Services\ProjectDetailForms::class)->summary($project);
    $collapseId = 'projectDescriptionDetails'.$project->project_id;
    $notes = trim((string) $project->description);
@endphp

<div class="mt-3 project-description">
    <div class="d-flex align-items-center gap-2 mb-1">
        <span class="fw-bold">Project Description:</span>

        @if ($editTarget)
            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" data-bs-toggle="modal"
                data-bs-target="#{{ $editTarget }}" title="Edit description">
                <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>
        @endif
    </div>

    @if ($summary->isNotEmpty())
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            @foreach ($summary as $entry)
                <span class="badge rounded-pill text-bg-light border fw-normal">
                    {{ $entry['type']->type_name }}
                    @if ($entry['units'])
                        &middot; {{ count($entry['units']) }} {{ \Illuminate\Support\Str::plural('unit', count($entry['units'])) }}
                    @endif
                </span>
            @endforeach

            <button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="collapse"
                data-bs-target="#{{ $collapseId }}" aria-expanded="false" aria-controls="{{ $collapseId }}">
                Show details
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
        </div>

        <div class="collapse" id="{{ $collapseId }}">
            <div class="border rounded-3 p-3 mt-2">
                @foreach ($summary as $entry)
                    <div class="{{ $loop->last ? '' : 'mb-3 pb-3 border-bottom' }}">
                        <div class="fw-semibold mb-2">{{ $entry['type']->type_name }}</div>

                        @if ($entry['project'])
                            <div class="row g-2 small mb-2">
                                @foreach ($entry['project'] as $item)
                                    <div class="{{ mb_strlen($item['value']) > 60 ? 'col-12' : 'col-sm-6 col-lg-4' }}">
                                        <div class="text-secondary">{{ $item['label'] }}</div>
                                        <div class="fw-semibold text-break">{!! nl2br(e($item['value'])) !!}</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @foreach ($entry['units'] as $unit)
                            <div class="bg-light rounded-3 p-2 mb-2">
                                <div class="fw-semibold small mb-1">Unit {{ $loop->iteration }}</div>
                                <div class="row g-2 small">
                                    @foreach ($unit as $item)
                                        <div class="col-sm-6 col-lg-4">
                                            <span class="text-secondary">{{ $item['label'] }}:</span>
                                            <span class="fw-semibold text-break">{{ $item['value'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($notes !== '')
        <div class="mt-2">
            @if ($summary->isNotEmpty())
                <div class="small text-secondary">Notes</div>
            @endif
            <p class="mb-0">{!! nl2br(e($notes)) !!}</p>
        </div>
    @elseif ($summary->isEmpty())
        <p class="mb-0">N/A</p>
    @endif
</div>
