{{--
    A project's quotation breakdown, printable, with a simple letterhead. Office
    roles who create projects may edit it; a client reads and prints it. Old
    projects have no lines until somebody adds them, so they show the button to
    those who can.

    $canEdit: whether this reader may change the lines.
--}}
@props([
    'project',
    'canEdit' => false,
])

@php
    $project->loadMissing(['client', 'quotationItems']);

    $hasLines = $project->quotationItems->isNotEmpty();
    $viewId = 'quotationModal'.$project->project_id;
    $editId = 'quotationEditModal'.$project->project_id;
    $quotationErrors = $errors->getBag(\App\Http\Controllers\ProjectQuotationController::ERROR_BAG);
    $reopen = $quotationErrors->any();
    $groups = $project->quotationItems->groupBy('kind');
    $client = $project->client;
    $quotation = app(\App\Services\ProjectQuotation::class);
@endphp

@if ($hasLines || $canEdit)
    @once
        <link href="/css/serviceReport.css" rel="stylesheet">
        <script src="/js/serviceReport.js" defer></script>
    @endonce

    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
        data-bs-target="#{{ $hasLines ? $viewId : $editId }}">
        <i class="bi bi-receipt me-1" aria-hidden="true"></i>
        View Details
    </button>

    @if ($hasLines)
        <div class="modal fade" id="{{ $viewId }}" tabindex="-1" aria-hidden="true" aria-labelledby="{{ $viewId }}Title"
            data-service-report-modal>
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="{{ $viewId }}Title">Quotation</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body sr-modal-body">
                        <div class="sr-sheet qt-sheet" data-service-report-sheet>
                            <x-sheet-letterhead />

                            <div class="sr-title">QUOTATION</div>

                            <table class="sr-table sr-header">
                                <colgroup>
                                    <col style="width: 62%">
                                    <col style="width: 38%">
                                </colgroup>
                                <tr>
                                    <td><span class="sr-label">CLIENT NAME:</span> {{ $client?->primaryName() }}</td>
                                    <td><span class="sr-label">PROJECT REF NO:</span> {{ $project->reference_no }}</td>
                                </tr>
                                <tr>
                                    <td><span class="sr-label">ADDRESS:</span> {{ $project->address }}</td>
                                    <td><span class="sr-label">CONTACT:</span> {{ $client?->fullname }} {{ $client?->contact_number }}</td>
                                </tr>
                            </table>

                            <table class="sr-table qt-lines">
                                <colgroup>
                                    <col style="width: 46%">
                                    <col style="width: 10%">
                                    <col style="width: 10%">
                                    <col style="width: 16%">
                                    <col style="width: 18%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>DESCRIPTION</th>
                                        <th class="qt-number">QTY</th>
                                        <th>UNIT</th>
                                        <th class="qt-number">UNIT PRICE</th>
                                        <th class="qt-number">AMOUNT</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (\App\Models\QuotationItem::KINDS as $kind => $heading)
                                        @if ($groups->has($kind))
                                            <tr class="qt-group">
                                                <td colspan="5">{{ mb_strtoupper($heading) }}</td>
                                            </tr>
                                            @foreach ($groups->get($kind) as $item)
                                                <tr>
                                                    @if ($item->kind === \App\Models\QuotationItem::KIND_LABOR)
                                                        {{-- A cost, not a count: the amount alone. --}}
                                                        <td colspan="4">{{ $item->description }}</td>
                                                    @else
                                                        <td>{{ $item->description }}</td>
                                                        <td class="qt-number">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</td>
                                                        <td>{{ $item->unit }}</td>
                                                        <td class="qt-number">&#8369; {{ number_format((float) $item->unit_price, 2) }}</td>
                                                    @endif
                                                    <td class="qt-number">&#8369; {{ number_format($item->amount(), 2) }}</td>
                                                </tr>
                                            @endforeach
                                        @endif
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="qt-total">
                                        <td colspan="4">TOTAL</td>
                                        <td class="qt-number">&#8369; {{ number_format((float) $project->quotation, 2) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="modal-footer">
                        @if ($canEdit)
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#{{ $editId }}">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>
                                Edit
                            </button>
                        @endif
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" data-service-report-print>
                            <i class="bi bi-printer me-1" aria-hidden="true"></i>
                            Print
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($canEdit)
        <div class="modal fade" id="{{ $editId }}" tabindex="-1" aria-hidden="true" aria-labelledby="{{ $editId }}Title"
            data-service-report-modal @if ($reopen) data-reopen="1" @endif>
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="POST" action="{{ route('super-admin.projects.quotation.update', $project->project_id) }}"
                        class="d-flex flex-column overflow-hidden">
                        @csrf
                        @method('PUT')

                        <div class="modal-header">
                            <h5 class="modal-title" id="{{ $editId }}Title">Edit Quotation</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body">
                            @if ($reopen)
                                <div class="alert alert-danger small py-2" role="alert">
                                    <ul class="mb-0 ps-3">
                                        @foreach (collect($quotationErrors->all())->unique() as $message)
                                            <li>{{ $message }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <x-project-quotation-editor :material-url="route('super-admin.projects.material-options')"
                                :rows="$reopen ? $quotation->editorRows((array) old('quotation_items', [])) : $quotation->savedRows($project)" />
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        @once
            <script src="/js/materialPicker.js"></script>
            <script src="/js/quotationLines.js"></script>
        @endonce
    @endif
@endif
