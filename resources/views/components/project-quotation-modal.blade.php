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
    $kindOrder = array_flip(array_keys(\App\Models\QuotationItem::KINDS));
    $sheetLines = $project->quotationItems->sortBy(fn ($item) => [$kindOrder[$item->kind] ?? 99, $item->sort_order])->values();
    $client = $project->client;
    $quotation = app(\App\Services\ProjectQuotation::class);
    $company = \App\Support\CompanyBranding::letterhead();
    $figures = $quotation->savedBreakdown($project);
    // Dated by its last save; a quotation from before that was kept, by its lines.
    $quotedOn = $project->quoted_at ?? $project->quotationItems->max('created_at');
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
                            <div class="qt-head">
                                <div class="qt-company"><span>COLICO</span>NSTRUCT ENGINEERING SERVICES</div>
                                <div class="qt-title">Quotation</div>
                            </div>

                            <div class="qt-office">
                                <div class="qt-office-label">Office &amp; Warehouse Address:</div>
                                <div>
                                    @if (filled($company['address']))
                                        <div>{{ $company['address'] }}</div>
                                    @endif
                                    @if (filled($company['phone']))
                                        <div>Phone No.: {{ $company['phone'] }}</div>
                                    @endif
                                </div>
                            </div>

                            <div class="qt-date">
                                <span>Date:</span>
                                <span class="qt-field">{{ $quotedOn?->format('d M Y') }}</span>
                            </div>

                            <div class="qt-form">
                                <div class="qt-row">
                                    <span class="qt-label">Client:</span>
                                    <span class="qt-field">{{ $client?->primaryName() }}</span>
                                </div>
                                <div class="qt-row">
                                    <span class="qt-label">Address:</span>
                                    <span class="qt-field">{{ $project->address }}</span>
                                </div>
                                <div class="qt-row">
                                    <span class="qt-label">Contact Person:</span>
                                    <span class="qt-field">{{ $client?->fullname }}</span>
                                    <span class="qt-label qt-label-mid">Phone No.:</span>
                                    <span class="qt-field">{{ $client?->contact_number }}</span>
                                    <span class="qt-label qt-label-mid">Email Address:</span>
                                    <span class="qt-field qt-field-wide">{{ $client?->email_address }}</span>
                                </div>
                                <div class="qt-row">
                                    <span class="qt-label">Project Reference No.:</span>
                                    <span class="qt-field qt-field-ref">{{ $project->reference_no }}</span>
                                    <span class="qt-label qt-label-mid">Location:</span>
                                    <span class="qt-field qt-field-wide">{{ $project->address }}</span>
                                </div>
                            </div>

                            <table class="qt-lines">
                                <colgroup>
                                    <col style="width: 6%">
                                    <col style="width: 47%">
                                    <col style="width: 8%">
                                    <col style="width: 9%">
                                    <col style="width: 13%">
                                    <col style="width: 17%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Description</th>
                                        <th>Quantity</th>
                                        <th>Unit</th>
                                        <th>Unit Price</th>
                                        <th>Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($sheetLines as $item)
                                        <tr>
                                            <td class="qt-number">{{ $loop->iteration }}</td>
                                            @if ($item->kind === \App\Models\QuotationItem::KIND_LABOR)
                                                {{-- A cost, not a count: the amount alone. --}}
                                                <td colspan="4">{{ $item->description }}</td>
                                            @else
                                                <td>{{ $item->description }}</td>
                                                <td class="qt-number">{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</td>
                                                <td>{{ $item->unit }}</td>
                                                <td class="qt-number">{{ number_format((float) $item->unit_price, 2) }}</td>
                                            @endif
                                            <td class="qt-number">{{ number_format($item->amount(), 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                            <div class="qt-totals sr-keep">
                                <span>Amount</span>
                                <span class="qt-currency">PHP</span>
                                <span class="qt-field qt-number">{{ number_format($figures['subtotal'], 2) }}</span>

                                <span>Discount %</span>
                                <span></span>
                                <span class="qt-field qt-number">{{ rtrim(rtrim(number_format($figures['discount_percent'], 2), '0'), '.') }}%</span>

                                <span>Discount Amount</span>
                                <span></span>
                                <span class="qt-field qt-number">{{ number_format($figures['discount_amount'], 2) }}</span>

                                <span>Special Discount</span>
                                <span></span>
                                <span class="qt-field qt-number">{{ number_format($figures['special_discount'], 2) }}</span>

                                <strong>TOTAL AMOUNT</strong>
                                <strong class="qt-currency">PHP</strong>
                                <strong class="qt-field qt-number">{{ number_format((float) $project->quotation, 2) }}</strong>
                            </div>

                            <div class="qt-block sr-keep">
                                <div>Notes:</div>
                                <div class="qt-field qt-field-block">{{ $project->quotation_notes }}</div>
                                <div>INCLUSIONS:</div>
                                <div class="qt-field qt-field-block">{{ $project->quotation_inclusions }}</div>
                                <div>EXCLUSIONS:</div>
                                <div class="qt-field qt-field-block">{{ $project->quotation_exclusions }}</div>
                                <div>Terms of Payment</div>
                                <div class="qt-field qt-field-block">{{ $project->quotation_payment_terms }}</div>
                            </div>

                            <div class="qt-sign sr-keep">
                                <div>
                                    <div>PREPARED BY:</div>
                                    <div class="qt-field qt-field-sign">{{ $project->quotation_prepared_by }}</div>
                                </div>
                                <div>
                                    <div>approved by</div>
                                    <div class="qt-field qt-field-sign">{{ $project->quotation_approved_by }}</div>
                                </div>
                                <div class="qt-conforme">
                                    <div class="qt-conforme-title">CONFORME:</div>
                                    <div class="qt-conforme-line">SIGNATURE OVER PRINTED NAME / DATE</div>
                                </div>
                            </div>
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
                                :rows="$reopen ? $quotation->editorRows((array) old('quotation_items', [])) : $quotation->savedRows($project)"
                                :discount-percent="$reopen ? old('quotation_discount_percent') : ((float) $project->quotation_discount_percent ?: '')"
                                :special-discount="$reopen ? old('quotation_special_discount') : ((float) $project->quotation_special_discount ?: '')"
                                :terms="collect(\App\Services\ProjectQuotation::TERM_FIELDS)->map(fn ($max, $field) => $reopen ? old($field) : $project->{$field})->all()"
                                :quoted-at="$reopen ? old('quoted_at') : ($quotedOn ?? now())->format('Y-m-d')" />
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
