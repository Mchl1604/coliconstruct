@props([
    'project',
    // ProjectMaterialLedger::summary() - one row per material with its figures.
    'summary',
    // ProjectMaterialRules::abilities(), asked again by every endpoint.
    'abilities' => ['add' => false, 'use' => false, 'return' => false, 'request' => false, 'return_needs_approval' => false],
    // Which portal's routes the forms post to: 'super-admin', 'inventory' or
    // 'technician'.
    'portal' => 'super-admin',
    // ProjectMaterialController::quickAddOptions(), for Add to List.
    'quickAdd' => ['types' => [], 'brands' => [], 'units' => []],
])

{{--
    The Materials tab: what was planned when the project was created, what was
    added, used and returned since, and what remains. Readable from the moment
    the project exists, and open to whoever has access, set up or not.

    Every figure comes from ProjectMaterialLedger, summed from the entries
    listed in the history below - nothing on this tab is a stored total. When
    the project closed, whatever was still on site was recorded as Unused, and
    that column appears only once there is something in it.

    The dialogs are ordinary forms. On the administrators' page
    projectWorkspace.js sends them and redraws the tab in place; elsewhere they
    post and reload onto #project-materials. A lead's Add Material dialog is
    Request Material: the same form, asking for it rather than taking it.
--}}
@php
    $routePrefix = $portal.'.projects.materials.';
    // Quantities only: prices stay on the quotation.
    $quotedMaterials = app(\App\Services\ProjectQuotation::class)->quotedMaterials($project);
    $plannedIds = $summary->map(fn (array $row): int => (int) $row['material']->material_id)->all();
    $quotedNotPlanned = array_diff_key($quotedMaterials, array_flip($plannedIds));
    $showsUnused = $summary->contains(fn (array $row): bool => $row['unused'] > 0);
    $today = \App\Support\BusinessTime::today()->toDateString();
    $hasOnSite = $summary->contains(fn (array $row): bool => $row['on_site'] > 0);
    $canRecord = $abilities['use'] || $abilities['return'];
    // A lead's return waits for the inventory side to approve it.
    $returnNeedsApproval = $abilities['return_needs_approval'] ?? false;
    $returnLabel = $returnNeedsApproval ? 'Return Material' : 'Record Return';
    $canAskForMore = $abilities['add'] || $abilities['request'];
    $hasActions = $canRecord || $canAskForMore;
    $moreLabel = $abilities['add'] ? 'Add Material' : 'Request Material';
    $moreAction = $abilities['add']
        ? route($routePrefix.'add', $project->project_id)
        : ($abilities['request'] ? route($routePrefix.'requests.store', $project->project_id) : '');
    $materialRequests = $project->materialRequests()->with(['material', 'requester'])->latest('material_request_id')->limit(50)->get();
@endphp

<div class="card shadow-sm border-0 rounded-2 mb-4" data-project-materials
    data-suggest-url="{{ $canAskForMore ? route($routePrefix.'suggest', $project->project_id) : '' }}"
    data-quick-add-url="{{ $abilities['add'] ? route($routePrefix.'quick-add', $project->project_id) : '' }}">
    <div class="card-body p-3 p-md-4">

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h4 class="fw-bold mb-1">Materials</h4>
                <p class="text-secondary small mb-0">Initial, used and returned.</p>
            </div>

            @if ($hasActions)
                <div class="d-flex flex-wrap gap-2">
                    @if ($abilities['use'])
                        <button type="button" class="btn btn-outline-success" data-bs-toggle="modal"
                            data-bs-target="#recordMaterialModal" data-material-entry-type="used"
                            @disabled(! $hasOnSite)>
                            <i class="bi bi-tools me-1" aria-hidden="true"></i>
                            Record Use
                        </button>
                    @endif
                    @if ($abilities['return'])
                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal"
                            data-bs-target="#recordMaterialModal" data-material-entry-type="returned"
                            @disabled(! $hasOnSite)>
                            <i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>
                            {{ $returnLabel }}
                        </button>
                    @endif
                    @if ($canAskForMore)
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                            data-bs-target="#addMaterialModal">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                            {{ $moreLabel }}
                        </button>
                    @endif
                </div>
            @endif
        </div>

        @if ($quotedNotPlanned !== [])
            <div class="alert alert-warning small py-2" role="alert">
                <span class="fw-semibold">Quoted, not planned:</span>
                @foreach ($quotedNotPlanned as $quoted)
                    {{ $quoted['label'] }} ({{ rtrim(rtrim(number_format($quoted['quantity'], 2, '.', ''), '0'), '.') }} {{ $quoted['unit'] }})@unless ($loop->last), @endunless
                @endforeach
            </div>
        @endif

        @if ($summary->isEmpty())
            <div class="material-plan-empty">
                No materials listed.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Material</th>
                            <th>Type</th>
                            <th>Unit</th>
                            @if ($quotedMaterials !== [])
                                <th class="text-end">Quoted</th>
                            @endif
                            <th class="text-end">Added</th>
                            <th class="text-end">Used</th>
                            <th class="text-end">Returned</th>
                            @if ($showsUnused)
                                <th class="text-end">Unused</th>
                            @endif
                            <th class="text-end">Remaining</th>
                            @if ($hasActions)
                                <th class="text-center">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($summary as $row)
                            @php($unit = $row['material']->unit)
                            <tr>
                                <td>
                                    <span class="material-name">{{ $row['material']->name }}</span>
                                    @if ($row['material']->brandName())
                                        <span class="material-brand">{{ $row['material']->brandName() }}</span>
                                    @endif
                                    @if ($row['material']->description)
                                        <div class="material-meta">{{ $row['material']->description }}</div>
                                    @endif
                                    @if ($row['line']->notes)
                                        <div class="material-meta fst-italic">{{ $row['line']->notes }}</div>
                                    @endif
                                </td>
                                <td class="small">{{ $row['material']->type?->type_name }}</td>
                                <td><span class="material-unit-chip" title="{{ $unit->label() }}">{{ $unit->value }}</span></td>
                                {{-- Added counts the setup plan too, so the columns
                                     still add up to Remaining. --}}
                                @if ($quotedMaterials !== [])
                                    @php($quotedLine = $quotedMaterials[(int) $row['material']->material_id] ?? null)
                                    <td class="text-end material-qty {{ $quotedLine ? '' : 'is-zero' }}">
                                        {{ $unit->format($quotedLine['quantity'] ?? 0) }}
                                    </td>
                                @endif
                                @php($added = $row['needed'] + $row['added'])
                                <td class="text-end material-qty {{ $added == 0 ? 'is-zero' : '' }}">
                                    {{ $unit->format($added) }}
                                </td>
                                @foreach (['used', 'returned'] as $figure)
                                    <td class="text-end material-qty {{ $row[$figure] == 0 ? 'is-zero' : '' }}">
                                        {{ $unit->format($row[$figure]) }}
                                    </td>
                                @endforeach
                                @if ($showsUnused)
                                    <td class="text-end material-qty {{ $row['unused'] > 0 ? 'material-unused' : 'is-zero' }}">
                                        {{ $unit->format($row['unused']) }}
                                    </td>
                                @endif
                                <td class="text-end material-qty {{ $row['on_site'] > 0 ? 'material-on-site' : 'is-zero' }}">
                                    {{ $unit->format($row['on_site']) }}
                                </td>
                                @if ($hasActions)
                                    <td class="text-center">
                                        <div class="material-row-actions">
                                            @if ($abilities['use'])
                                                <button type="button" class="btn btn-sm btn-outline-success py-1 px-2"
                                                    data-bs-toggle="modal" data-bs-target="#recordMaterialModal"
                                                    data-material-entry-type="used"
                                                    data-material-line="{{ $row['line']->project_material_id }}"
                                                    title="Record use" @disabled($row['on_site'] <= 0)>
                                                    <i class="bi bi-tools" aria-hidden="true"></i>
                                                </button>
                                            @endif
                                            @if ($abilities['return'])
                                                <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2"
                                                    data-bs-toggle="modal" data-bs-target="#recordMaterialModal"
                                                    data-material-entry-type="returned"
                                                    data-material-line="{{ $row['line']->project_material_id }}"
                                                    title="{{ $returnNeedsApproval ? 'Return material' : 'Record return' }}" @disabled($row['on_site'] <= 0)>
                                                    <i class="bi bi-arrow-return-left" aria-hidden="true"></i>
                                                </button>
                                            @endif
                                            @if ($canAskForMore)
                                                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2"
                                                    data-bs-toggle="modal" data-bs-target="#addMaterialModal"
                                                    data-material-add='@json($row['material']->toOption() + ['available' => $row['available']])'
                                                    title="{{ $abilities['add'] ? 'Add more' : 'Request more' }}">
                                                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if ($materialRequests->isNotEmpty())
    <div class="card shadow-sm border-0 rounded-2 mb-4" data-material-requests-panel>
        <div class="card-body p-3 p-md-4">
            <h5 class="fw-bold mb-3">Material Requests</h5>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-info">
                        <tr>
                            <th>Date</th>
                            <th>Material</th>
                            <th class="text-end">Qty</th>
                            <th>By</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($materialRequests as $materialRequest)
                            <tr>
                                <td class="text-nowrap">{{ \App\Support\BusinessTime::at($materialRequest->created_at)?->format(\App\Support\BusinessTime::DATE) }}</td>
                                <td>
                                    <span class="material-name">{{ $materialRequest->material?->displayName() }}</span>
                                    @if ($materialRequest->isReturn())
                                        <span class="badge text-bg-info ms-1">Return</span>
                                    @endif
                                </td>
                                <td class="text-end material-qty">
                                    {{ $materialRequest->material?->unit->format($materialRequest->quantity) }}
                                    {{ $materialRequest->material?->unit->value }}
                                </td>
                                <td class="small">{{ $materialRequest->requester?->fullName() }}</td>
                                <td>
                                    <span class="material-request-status is-{{ $materialRequest->status }}">{{ $materialRequest->statusLabel() }}</span>
                                    @if ($materialRequest->rejection_reason)
                                        <div class="material-meta">{{ $materialRequest->rejection_reason }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

<div class="card shadow-sm border-0 rounded-2">
    <div class="card-body p-3 p-md-4">
        <h5 class="fw-bold mb-3">Material History</h5>

        <div class="table-responsive">
            <table id="materialEntriesTable" class="table table-hover align-middle mb-0"
                data-server-table-url="{{ route($routePrefix.'table', $project->project_id) }}">
                <thead class="table-info">
                    <tr>
                        <th>Date</th>
                        <th>Material</th>
                        <th>Entry</th>
                        <th class="text-end">Qty</th>
                        <th>By</th>
                        <th>Notes</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                {{-- Filled a page at a time by serverTable.js from
                     ProjectMaterialController::table(). --}}
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

@if ($canRecord)
    {{-- Record Use / Record Return. One dialog for both, switched by the
         toggle at the top; the button that opened it chooses which. A Purchasing
         Admin has the one choice. --}}
    <div class="modal fade" id="recordMaterialModal" tabindex="-1" aria-hidden="true"
        aria-labelledby="recordMaterialTitle" data-record-material-modal
        data-return-title="{{ $returnLabel }}">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route($routePrefix.'record', $project->project_id) }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="recordMaterialTitle" data-record-material-title>{{ $abilities['use'] ? 'Record Use' : $returnLabel }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="btn-group w-100 mb-3" role="group" aria-label="Entry">
                            @if ($abilities['use'])
                                <input type="radio" class="btn-check" name="entry_type" id="recordMaterialUsed"
                                    value="used" checked data-record-material-type>
                                <label class="btn btn-outline-success" for="recordMaterialUsed">Used</label>
                            @endif
                            @if ($abilities['return'])
                                <input type="radio" class="btn-check" name="entry_type" id="recordMaterialReturned"
                                    value="returned" @checked(! $abilities['use']) data-record-material-type>
                                <label class="btn btn-outline-primary" for="recordMaterialReturned">Returned</label>
                            @endif
                        </div>

                        @if ($returnNeedsApproval)
                            <div class="alert alert-info small py-2 d-none" role="note" data-record-material-approval>
                                <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>
                                Inventory approves returns first.
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="recordMaterialLine">Material</label>
                            <select class="form-select" id="recordMaterialLine" name="project_material_id" required
                                data-record-material-line>
                                @foreach ($summary as $row)
                                    <option value="{{ $row['line']->project_material_id }}"
                                        data-on-site="{{ $row['on_site'] }}"
                                        data-unit="{{ $row['material']->unit->value }}"
                                        data-fractional="{{ $row['material']->unit->allowsFractions() ? '1' : '0' }}"
                                        @disabled($row['on_site'] <= 0)>
                                        {{ $row['material']->displayName() }}
                                        &mdash; {{ $row['material']->unit->format($row['on_site']) }}
                                        {{ $row['material']->unit->value }} remaining
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold" for="recordMaterialQuantity">Quantity</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="recordMaterialQuantity"
                                        name="quantity" min="0.01" step="0.01" required data-record-material-qty>
                                    <span class="input-group-text" data-record-material-unit></span>
                                </div>
                                <div class="form-text" data-record-material-hint></div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold" for="recordMaterialDate">Date</label>
                                <input type="date" class="form-control" id="recordMaterialDate" name="entry_date"
                                    value="{{ $today }}" max="{{ $today }}" required>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label fw-semibold" for="recordMaterialNotes">Notes</label>
                            <input type="text" class="form-control" id="recordMaterialNotes" name="notes"
                                maxlength="255" placeholder="Optional">
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" data-record-material-submit>
                            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

@if ($canAskForMore)
    {{-- Add Material: more of one already here, or one the plan did not
         have. The picker fills material_id; Add to List opens beneath it for
         a material the list does not have yet. A lead sees Request Material,
         which may ask for more than is available: Purchasing can bring it in. --}}
    <div class="modal fade" id="addMaterialModal" tabindex="-1" aria-hidden="true"
        aria-labelledby="addMaterialTitle" data-add-material-modal
        data-add-material-requests="{{ $abilities['add'] ? '0' : '1' }}">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="POST" action="{{ $moreAction }}">
                    @csrf
                    <input type="hidden" name="material_id" value="" data-add-material-id>

                    <div class="modal-header">
                        <h5 class="modal-title" id="addMaterialTitle">{{ $moreLabel }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3" data-add-material-search-wrap>
                            <label class="form-label fw-semibold" for="addMaterialSearch">Material</label>
                            <div>
                                <input type="search" class="form-control" id="addMaterialSearch"
                                    placeholder="Search name, brand or type" data-add-material-search>
                            </div>
                        </div>

                        <div class="alert alert-light border d-none d-flex justify-content-between align-items-center gap-2"
                            data-add-material-chosen>
                            <div>
                                <div class="material-name" data-add-material-label></div>
                                <div class="material-meta" data-add-material-meta></div>
                                <div class="small fw-semibold mt-1" data-add-material-available></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-add-material-change>
                                Change
                            </button>
                        </div>

                        @if ($abilities['add'])
                            <div class="border rounded-3 p-3 mb-3 d-none" data-add-material-new>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold mb-0">Add to Material List</h6>
                                    <button type="button" class="btn btn-sm btn-link" data-add-material-new-cancel>
                                        Cancel
                                    </button>
                                </div>

                                @include('projects.partials.material-quick-add-fields', [
                                    'types' => $quickAdd['types'],
                                    'brands' => $quickAdd['brands'] ?? [],
                                    'units' => $quickAdd['units'],
                                    'idPrefix' => 'projectQuickAdd',
                                ])

                                <div class="text-end mt-2">
                                    <button type="button" class="btn btn-sm btn-primary" data-quick-add-save>
                                        Add to List
                                    </button>
                                </div>
                            </div>
                        @endif

                        {{-- Added, it comes off the warehouse shelf today, and is
                             refused past what is available. --}}
                        <div>
                            <label class="form-label fw-semibold" for="addMaterialQuantity">Quantity</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="addMaterialQuantity"
                                    name="quantity" min="0.01" step="0.01" required data-add-material-qty>
                                <span class="input-group-text" data-add-material-unit>&ndash;</span>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" data-add-material-submit disabled>
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                            {{ $moreLabel }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endif

@if ($hasActions)
    {{-- Void Entry. The form's action is set by projectMaterials.js from the
         history row that opened it. --}}
    <div class="modal fade" id="voidMaterialEntryModal" tabindex="-1" aria-hidden="true"
        aria-labelledby="voidMaterialEntryTitle" data-void-material-modal>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="" data-void-material-form>
                    @csrf
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="voidMaterialEntryTitle">Void Entry</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Void this entry?</p>
                        <p class="small text-secondary mb-0" data-void-material-summary></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Go Back</button>
                        <button type="submit" class="btn btn-danger">Void Entry</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
