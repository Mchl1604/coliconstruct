{{-- One row of the Archived Projects table, rendered by
     ProjectController::archivedTable() for serverTable.js. --}}
@php
    $client = $project->client;
    // The same rule the active Projects table uses:
    // a company is named by its company, a
    // homeowner by their name.
    $clientName =
        $client?->client_type === 'Commercial'
            ? $client->company_name ?: $client->fullname
            : $client?->fullname;
@endphp

<tr>
    <td>{{ $project->displayCode() }}</td>
    <td>{{ $project->reference_no ?? 'N/A' }}</td>
    <td>{{ $clientName ?: 'N/A' }}</td>
    <td>{{ $client?->client_type ?? 'N/A' }}</td>
    <td>
        @forelse ($project->projectTypes as $projectType)
            <span class="project-type-chip">{{ $projectType->type_name }}</span>
        @empty
            <span class="text-muted small">N/A</span>
        @endforelse
    </td>
    <td class="text-success fw-semibold">
        &#8369; {{ number_format((float) $project->quotation, 2) }}
    </td>
    <td>
        {{ \App\Support\BusinessTime::format($project->archived_at, \App\Support\BusinessTime::DATE, 'N/A') }}
    </td>
    <td>{{ $project->archivedByUser?->fullName() ?? '—' }}</td>
    <td class="text-center">
        <div class="projects-action-buttons">
            {{-- The same Project Details page every other
                 listing opens, and the same blue View button
                 the active Projects table draws. Reading an
                 archived project does not take it out of the
                 archive: nothing about the row changes, and
                 Restore below is still the only way back into
                 the active list. --}}
            <a class="btn btn-sm btn-primary py-1 px-2"
                href="{{ route('super-admin.projects.show', $project->project_id) }}"
                title="View Project Details">
                <i class="bi bi-eye me-1"></i>
                View
            </a>

            <button class="btn btn-sm btn-success py-1 px-2" data-bs-toggle="modal"
                data-bs-target="#restoreProjectModal{{ $project->project_id }}">
                <i class="bi bi-arrow-counterclockwise me-1"></i>
                Restore
            </button>
        </div>
    </td>
</tr>
