@extends('layouts.superadminNav')

@section('title', 'Stock Movements')

@push('styles')
    <link href="/css/materials.css" rel="stylesheet">
    <link href="/css/inventory.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Stock Movements</h4>
            <p class="text-secondary small mb-0">Every stock change.</p>
        </div>
    </div>

    <div class="alert alert-danger d-none" role="alert" data-movement-error></div>
    <div class="alert alert-success d-none" role="alert" data-movement-success></div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-4">
            <div class="row g-2 mb-2 align-items-end">
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small fw-semibold mb-1" for="movementTypeFilter">Type</label>
                    <select class="form-select form-select-sm" id="movementTypeFilter" data-movement-type-filter>
                        <option value="">All movements</option>
                        @foreach ($movementTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Shown when History on a stock row narrowed the list to one
                     material. --}}
                @if ($filteredMaterial)
                    <div class="col-sm-6 col-md-8" data-movement-material-chip
                        data-movement-material="{{ $filteredMaterial->material_id }}">
                        <span class="stock-filter-chip">
                            <span>{{ $filteredMaterial->displayName() }}</span>
                            <button type="button" class="btn-close ms-1" aria-label="Show all materials"
                                data-movement-material-clear></button>
                        </span>
                    </div>
                @endif
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="movementsTable">
                    <thead class="table-info">
                        <tr>
                            <th>Date</th>
                            <th>Material</th>
                            <th>Type</th>
                            <th>Quantity</th>
                            <th>Details</th>
                            <th>Recorded By</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    {{-- Filled a page at a time by serverTable.js from
                         StockController::movements(). --}}
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <x-confirm-dialog />
@endsection

@push('scripts')
    <script>
        window.inventoryRoutes = {
            movements: @json(route('inventory.stock.movements')),
            movementBase: @json(url('inventory/stock/movements')),
        };
    </script>
    <script src="/js/confirmDialog.js"></script>
    <script src="/js/super-admin/movements.js"></script>
@endpush
