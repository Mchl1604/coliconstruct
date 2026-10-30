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
            <p class="text-secondary small mb-0">Stock, movements and materials.</p>
        </div>
    </div>

    {{-- The figures. Low and Out open the Stock tab narrowed to them; every
         stock change answers with fresh ones - see stock.js. --}}
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

    <ul class="nav nav-tabs technician-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="stockTab" data-bs-toggle="tab" data-bs-target="#stockPane"
                type="button" role="tab" aria-controls="stockPane" aria-selected="true">
                <i class="bi bi-boxes me-1" aria-hidden="true"></i>
                Stock
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="movementsTab" data-bs-toggle="tab" data-bs-target="#movementsPane"
                type="button" role="tab" aria-controls="movementsPane" aria-selected="false">
                <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>
                Movements
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="materialsTab" data-bs-toggle="tab" data-bs-target="#materialsTabPane"
                type="button" role="tab" aria-controls="materialsTabPane" aria-selected="false">
                <i class="bi bi-box-seam me-1" aria-hidden="true"></i>
                Materials
            </button>
        </li>
    </ul>

    <div class="tab-content">

        {{-- ==================== STOCK ==================== --}}
        <div class="tab-pane fade show active" id="stockPane" role="tabpanel" aria-labelledby="stockTab">
            <div class="card shadow-sm border-0 rounded-2">
                <div class="card-body p-4">
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
        </div>

        {{-- ==================== MOVEMENTS ==================== --}}
        <div class="tab-pane fade" id="movementsPane" role="tabpanel" aria-labelledby="movementsTab">
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
                        {{-- Shown when History on a stock row narrowed the
                             list to one material. --}}
                        <div class="col-sm-6 col-md-8 d-none" data-movement-material-chip>
                            <span class="stock-filter-chip">
                                <span data-movement-material-label></span>
                                <button type="button" class="btn-close ms-1" aria-label="Show all materials"
                                    data-movement-material-clear></button>
                            </span>
                        </div>
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
        </div>

        {{-- ==================== MATERIALS ==================== --}}
        <div class="tab-pane fade" id="materialsTabPane" role="tabpanel" aria-labelledby="materialsTab">
            <div class="card shadow-sm border-0 rounded-2">
                <div class="card-body p-4">
                    @include('super-admin.partials.materials-panel')
                </div>
            </div>
        </div>
    </div>

    {{-- The dialogs, outside the cards like every other page's. --}}
    @include('super-admin.partials.material-modal')
    @include('super-admin.partials.stock-modal', ['projects' => $projects])

    <x-confirm-dialog />
@endsection

@push('scripts')
    <script>
        window.inventoryRoutes = {
            materials: @json(route('inventory.materials.index')),
            materialTypes: @json(route('inventory.materials.types.index')),
            brands: @json(route('inventory.materials.brands.index')),
            stock: @json(route('inventory.stock.index')),
            movements: @json(route('inventory.stock.movements')),
            receive: @json(route('inventory.stock.receive')),
            issue: @json(route('inventory.stock.issue')),
            adjust: @json(route('inventory.stock.adjust')),
            movementBase: @json(url('inventory/stock/movements')),
        };
        window.inventoryOptions = {
            today: @json(\App\Support\BusinessTime::today()->toDateString()),
            // The Stock tab's Sub-type filter is refilled from these when its
            // Type filter changes.
            types: @json($types),
        };
    </script>
    <script src="/js/confirmDialog.js"></script>
    <script src="/js/super-admin/materials.js"></script>
    <script src="/js/super-admin/stock.js"></script>
@endpush
