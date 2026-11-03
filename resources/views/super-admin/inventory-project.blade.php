@extends('layouts.superadminNav')

@section('title', $project->reference_no)

@push('styles')
    <link href="/css/materials.css" rel="stylesheet">
    <link href="/css/inventory.css" rel="stylesheet">
@endpush

@section('content')
    {{-- A project as the inventory side reads it: what it is, and its
         Materials tab. Nothing about the project itself changes here. --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">{{ $project->name }}</h4>
            <p class="text-secondary small mb-0">{{ $project->reference_no }}</p>
        </div>
        <a href="{{ route('inventory.projects') }}" class="btn btn-sm btn-outline-secondary">Back to Projects</a>
    </div>

    <div class="card shadow-sm border-0 rounded-2 mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="row g-3 small">
                <div class="col-sm-4">
                    <div class="text-secondary">Client</div>
                    <div class="fw-semibold">{{ $project->client?->company_name ?: $project->client?->fullname }}</div>
                </div>
                <div class="col-sm-4">
                    <div class="text-secondary">Status</div>
                    <div class="fw-semibold">{{ $project->statusLabel() }}</div>
                </div>
                <div class="col-sm-4">
                    <div class="text-secondary">Address</div>
                    <div class="fw-semibold">{{ $project->address }}</div>
                </div>
            </div>
        </div>
    </div>

    <div id="project-materials">
        <x-project-materials :project="$project" :summary="$materialSummary"
            :abilities="$materialAbilities" portal="inventory"
            :quick-add="$materialQuickAdd" />
    </div>
@endsection

@push('scripts')
    <script src="/js/materialPicker.js"></script>
    <script src="/js/projectMaterials.js"></script>
@endpush
