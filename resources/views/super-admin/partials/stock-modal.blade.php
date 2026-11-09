{{--
    Stock In and Stock Out for one material or several: one dialog for both,
    filled by stock.js. The buttons above the Stock table open it empty; a
    row's button opens it with that material listed. The search adds materials
    and each gets its own quantity; the fields below apply to all of them.

    Fields that belong to only some modes carry data-stock-for with the modes
    they show in. Sent as JSON, so the fields carry data attributes rather than
    relying on a form submit.

    Expects $projects (from InventoryController::index()).
--}}
<div class="modal fade" id="stockModal" tabindex="-1" aria-hidden="true" aria-labelledby="stockModalTitle"
    data-stock-modal>
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form novalidate data-stock-form>
                <div class="modal-header">
                    <h5 class="modal-title" id="stockModalTitle" data-stock-modal-title>Stock In</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-danger small py-2 d-none" role="alert" data-stock-modal-error></div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1" for="stockSearch">Add Material</label>
                        <div>
                            <input type="search" class="form-control" id="stockSearch"
                                placeholder="Search name, brand or type" data-stock-search>
                        </div>
                    </div>

                    <div class="mb-3" data-stock-lines></div>
                    <p class="small text-secondary mb-3" data-stock-lines-empty>No materials added.</p>

                    <div class="row g-2">
                        <div class="col-sm-6" data-stock-for="in">
                            <label class="form-label small fw-semibold mb-1" for="stockMovementType">Received As</label>
                            <select class="form-select" id="stockMovementType" data-stock-field="movement_type">
                                <option value="{{ \App\Models\StockMovement::TYPE_STOCK_IN }}">Delivery</option>
                                <option value="{{ \App\Models\StockMovement::TYPE_RETURNED }}">Return from site</option>
                            </select>
                        </div>

                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold mb-1" for="stockDate">Date</label>
                            <input type="date" class="form-control" id="stockDate" required
                                max="{{ \App\Support\BusinessTime::today()->toDateString() }}"
                                data-stock-field="movement_date">
                        </div>

                        {{-- Searched rather than scrolled. The select stays as what
                             is sent; the search and the chosen card drive it. --}}
                        <div class="col-12" data-stock-for="in-return out">
                            <label class="form-label small fw-semibold mb-1" for="stockProjectSearch">Project</label>
                            <div class="material-picker" data-stock-project-picker>
                                <input type="search" class="form-control" id="stockProjectSearch" autocomplete="off"
                                    placeholder="Search ref no. or name" data-stock-project-search
                                    role="combobox" aria-expanded="false" aria-controls="stockProjectMenu">
                                <div class="material-picker-menu d-none" id="stockProjectMenu" role="listbox"
                                    data-stock-project-menu></div>
                            </div>
                            <div class="stock-project-chosen d-none" data-stock-project-chosen>
                                <i class="bi bi-folder2-open text-primary" aria-hidden="true"></i>
                                <span class="fw-semibold flex-grow-1" data-stock-project-label></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-stock-project-clear>
                                    Change
                                </button>
                            </div>
                            <select class="d-none" id="stockProject" data-stock-field="project_id" aria-hidden="true" tabindex="-1">
                                <option value="">None</option>
                                @foreach ($projects as $project)
                                    <option value="{{ $project['project_id'] }}" data-open="{{ $project['is_open'] ? '1' : '0' }}">
                                        {{ $project['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold mb-1" for="stockNotes">Notes</label>
                            <input type="text" class="form-control" id="stockNotes" maxlength="255"
                                placeholder="Optional" data-stock-field="notes">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-stock-save disabled>
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                            data-stock-save-spinner></span>
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
