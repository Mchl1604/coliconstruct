{{-- One row of the inventory side's Projects page, rendered by
     InventoryController::projectsTable() for serverTable.js. --}}
<tr>
    <td class="text-nowrap">{{ $project->reference_no }}</td>
    <td>
        {{ $project->name }}
        @if ($project->pending_requests_count > 0)
            <span class="badge text-bg-warning ms-1">{{ $project->pending_requests_count }} pending</span>
        @endif
    </td>
    <td class="small">{{ $project->client?->company_name ?: $project->client?->fullname }}</td>
    <td>
        <x-project-status-badge :project="$project" />
    </td>
    <td class="text-center">
        <a class="btn btn-sm btn-primary py-1 px-2" href="{{ route('inventory.projects.show', $project->project_id) }}"
            title="View project">
            <i class="bi bi-eye" aria-hidden="true"></i>
        </a>
    </td>
</tr>
