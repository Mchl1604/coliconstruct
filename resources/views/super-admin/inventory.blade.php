@extends('layouts.superadminNav')

@section('title', 'Inventory')

@push('styles')
    <link href="/css/materials.css" rel="stylesheet">
    <link href="/css/inventory.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Inventory</h4>
            <p class="text-secondary small mb-0">What is held now.</p>
        </div>
    </div>

    {{-- The figures. Low and Out narrow the table to them; every stock change
         answers with fresh ones - see stock.js. --}}
    <div class="inventory-stats" data-inventory-stats>
        <div class="inventory-stat">
            <span class="inventory-stat-label">Materials</span>
            <span class="inventory-stat-value" data-stat="materials">{{ $summary['materials'] }}</span>
        </div>
        <button type="button" class="inventory-stat tone-low" data-stat-filter="{{ \App\Models\Material::STOCK_LOW }}">
            <span class="inventory-stat-label">Low Stock</span>
            <span class="inventory-stat-value" data-stat="low">{{ $summary['low'] }}</span>
        </button>
        <button type="button" class="inventory-stat tone-out" data-stat-filter="{{ \App\Models\Material::STOCK_OUT }}">
            <span class="inventory-stat-label">Out of Stock</span>
            <span class="inventory-stat-value" data-stat="out">{{ $summary['out'] }}</span>
        </button>
    </div>

    <div class="alert alert-danger d-none" role="alert" data-stock-error></div>
    <div class="alert alert-success d-none" role="alert" data-stock-success></div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-4">
            <div class="d-flex justify-content-end flex-wrap gap-2 mb-3">
                <button type="button" class="btn btn-success" data-stock-bulk="in">
                    <i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>
                    Stock In
                </button>
                <button type="button" class="btn btn-warning" data-stock-bulk="out">
                    <i class="bi bi-box-arrow-up me-1" aria-hidden="true"></i>
                    Stock Out
                </button>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small fw-semibold mb-1" for="stockStatusFilter">Status</label>
                    <select class="form-select form-select-sm" id="stockStatusFilter" data-stock-status-filter>
                        <option value="">All</option>
                        @foreach ($stockStatuses as $value => $label)
                            <option value="{{ $value }}" @selected($stockFilter === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small fw-semibold mb-1" for="stockTypeFilter">Type</label>
                    <select class="form-select form-select-sm" id="stockTypeFilter" data-stock-type-filter>
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type['material_type_id'] }}">{{ $type['type_name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small fw-semibold mb-1" for="stockSubtypeFilter">Sub-type</label>
                    <select class="form-select form-select-sm" id="stockSubtypeFilter" data-stock-subtype-filter disabled>
                        <option value="">All sub-types</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="stockTable">
                    <thead class="table-info">
                        <tr>
                            <th>Material</th>
                            <th>Type</th>
                            <th>On Hand</th>
                            <th title="Planned by live projects, not yet released">Reserved</th>
                            <th title="On hand less reserved">Available</th>
                            <th>Reorder At</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    {{-- Filled a page at a time by serverTable.js from
                         StockController::index(). --}}
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- The dialog, outside the card like every other page's. --}}
    @include('super-admin.partials.stock-modal', ['projects' => $projects])
@endsection

@push('scripts')
    <script>
        window.inventoryRoutes = {
            stock: @json(route('inventory.stock.index')),
            stockOptions: @json(route('inventory.stock.options')),
            receiveMany: @json(route('inventory.stock.receive-many')),
            issueMany: @json(route('inventory.stock.issue-many')),
        };
        window.inventoryOptions = {
            today: @json(\App\Support\BusinessTime::today()->toDateString()),
            // The Sub-type filter is refilled from these when the Type filter
            // changes.
            types: @json($types),
        };
    </script>
    <script src="/js/materialPicker.js"></script>
    <script src="/js/super-admin/stock.js"></script>
@endpush
