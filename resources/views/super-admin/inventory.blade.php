@extends('layouts.superadminNav')

@section('title', 'Inventory')

@push('styles')
    <link href="/css/materials.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Inventory</h4>
            <p class="text-secondary small mb-0">Materials and their types.</p>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-4">
            @include('super-admin.partials.materials-panel')
        </div>
    </div>

    {{-- Add / Edit Material and View Type, outside the card like every other
         page's dialogs. --}}
    @include('super-admin.partials.material-modal')

    <x-confirm-dialog />
@endsection

@push('scripts')
    <script>
        window.inventoryRoutes = {
            materials: @json(route('inventory.materials.index')),
            materialTypes: @json(route('inventory.materials.types.index')),
        };
    </script>
    <script src="/js/confirmDialog.js"></script>
    <script src="/js/super-admin/materials.js"></script>
@endpush
