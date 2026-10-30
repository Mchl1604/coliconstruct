{{--
    Stock In, Stock Out and Adjust Count: one dialog for the three, filled by
    stock.js from the row's button. Fields that belong to only some of them
    carry data-stock-for with the modes they show in. Sent as JSON, so the
    fields carry data attributes rather than relying on a form submit.

    Expects $projects (from InventoryController::index()).
--}}
<div class="modal fade" id="stockModal" tabindex="-1" aria-hidden="true" aria-labelledby="stockModalTitle"
    data-stock-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form novalidate data-stock-form>
                <div class="modal-header">
                    <h5 class="modal-title" id="stockModalTitle" data-stock-modal-title>Stock In</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="stock-modal-material mb-3">
                        <div class="fw-semibold" data-stock-modal-material></div>
                        <div class="small text-secondary">On hand: <span data-stock-modal-on-hand></span></div>
                    </div>

                    <div class="alert alert-danger small py-2 d-none" role="alert" data-stock-modal-error></div>

                    <div class="row g-2">
                        <div class="col-12" data-stock-for="in">
                            <label class="form-label small fw-semibold mb-1" for="stockMovementType">Received As</label>
                            <select class="form-select" id="stockMovementType" data-stock-field="movement_type">
                                <option value="{{ \App\Models\StockMovement::TYPE_STOCK_IN }}">Delivery</option>
                                <option value="{{ \App\Models\StockMovement::TYPE_RETURNED }}">Return from site</option>
                            </select>
                        </div>

                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold mb-1" for="stockQuantity" data-stock-quantity-label>Quantity</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="stockQuantity" min="0" step="0.01" required
                                    data-stock-field="quantity">
                                <span class="input-group-text" data-stock-modal-unit></span>
                            </div>
                        </div>

                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold mb-1" for="stockDate">Date</label>
                            <input type="date" class="form-control" id="stockDate" required
                                max="{{ \App\Support\BusinessTime::today()->toDateString() }}"
                                data-stock-field="movement_date">
                        </div>

                        <div class="col-12" data-stock-for="in-delivery">
                            <label class="form-label small fw-semibold mb-1" for="stockSupplier">Supplier</label>
                            <input type="text" class="form-control" id="stockSupplier" maxlength="150"
                                placeholder="Optional" data-stock-field="supplier">
                        </div>

                        <div class="col-12" data-stock-for="in-return out">
                            <label class="form-label small fw-semibold mb-1" for="stockProject">Project</label>
                            <select class="form-select" id="stockProject" data-stock-field="project_id">
                                <option value="">None</option>
                                @foreach ($projects as $project)
                                    <option value="{{ $project['project_id'] }}" data-open="{{ $project['is_open'] ? '1' : '0' }}">
                                        {{ $project['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-12" data-stock-for="in out">
                            <label class="form-label small fw-semibold mb-1" for="stockReference">Reference No.</label>
                            <input type="text" class="form-control" id="stockReference" maxlength="100"
                                placeholder="DR, PO or invoice" data-stock-field="reference_no">
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold mb-1" for="stockNotes" data-stock-notes-label>Notes</label>
                            <input type="text" class="form-control" id="stockNotes" maxlength="255"
                                placeholder="Optional" data-stock-field="notes">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-stock-save>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-stock-save-spinner></span>
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
