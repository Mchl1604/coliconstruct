@extends('layouts.superadminNav')

@section('title', 'Material Requests')

@push('styles')
    <link href="/css/materials.css" rel="stylesheet">
    <link href="/css/inventory.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1">Material Requests</h4>
            <p class="text-secondary small mb-0">What leads have asked for.</p>
        </div>
    </div>

    <div class="alert alert-danger d-none" role="alert" data-request-error></div>
    <div class="alert alert-success d-none" role="alert" data-request-success></div>

    <div class="card shadow-sm border-0 rounded-2">
        <div class="card-body p-4">
            <div class="row g-2 mb-2">
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small fw-semibold mb-1" for="requestStatusFilter">Status</label>
                    <select class="form-select form-select-sm" id="requestStatusFilter" data-request-status-filter>
                        <option value="">All</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($value === \App\Models\MaterialRequest::STATUS_PENDING)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="materialRequestsTable">
                    <thead class="table-info">
                        <tr>
                            <th>Date</th>
                            <th>Project</th>
                            <th>Material</th>
                            <th>Qty</th>
                            <th title="On hand less reserved">Available</th>
                            <th>Requested By</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    {{-- Filled a page at a time by serverTable.js from
                         MaterialRequestController::table(). --}}
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="rejectRequestModal" tabindex="-1" aria-hidden="true"
        aria-labelledby="rejectRequestTitle" data-reject-request-modal>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form novalidate data-reject-request-form>
                    <div class="modal-header">
                        <h5 class="modal-title" id="rejectRequestTitle">Reject Request</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger small py-2 d-none" role="alert" data-reject-request-error></div>
                        <p class="small text-secondary mb-2" data-reject-request-label></p>
                        <label class="form-label fw-semibold" for="rejectRequestReason">Reason</label>
                        <input type="text" class="form-control" id="rejectRequestReason" maxlength="255" required
                            data-reject-request-reason>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Go Back</button>
                        <button type="submit" class="btn btn-danger">Reject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <x-confirm-dialog />
@endsection

@push('scripts')
    <script>
        window.materialRequestRoutes = {
            table: @json(route('inventory.material-requests.table')),
            base: @json(url('inventory/material-requests')),
        };
    </script>
    <script src="/js/confirmDialog.js"></script>
    <script src="/js/super-admin/materialRequests.js"></script>
@endpush
