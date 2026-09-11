@props([
    'project',
    'fileEvents',
])

{{--
    What a project's quotation has been.

    Two records, drawn separately because they are different things:

      - every change to the AMOUNT, with who made it and when, and whether the
        quotation file was replaced in the same save;
      - every change to the quotation FILES - each one uploaded, replaced or
        removed. A replaced file is kept rather than deleted and still opens,
        because it is what the client was quoted at the time.

    Read-only. Nothing here changes the project: there is no restore and no
    delete. The current amount and files are the ones on the page itself.
--}}
@php
    $amountChanges = $project->quotationHistory;
@endphp

<div class="modal fade" id="quotationHistoryModal" tabindex="-1" aria-labelledby="quotationHistoryModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="quotationHistoryModalLabel">
                    <i class="bi bi-clock-history me-2" aria-hidden="true"></i>
                    Quotation History &mdash; {{ $project->reference_no ?? $project->name }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <div class="quotation-history-current">
                    <span class="project-history-eyebrow">Current quotation</span>
                    <span class="text-success fw-semibold fs-5">
                        {{ \App\Services\QuotationChange::peso($project->quotation) }}
                    </span>
                </div>

                <h6 class="project-history-heading">
                    <i class="bi bi-cash-coin" aria-hidden="true"></i>
                    Amount changes
                </h6>

                @if ($amountChanges->isEmpty())
                    <p class="text-muted small">The quotation amount has not been changed since the project was created.</p>
                @else
                    <div class="table-responsive mb-4">
                        <table class="table table-sm align-middle quotation-history-table mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">When</th>
                                    <th scope="col">Changed by</th>
                                    <th scope="col">Amount</th>
                                    <th scope="col">Quotation file</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($amountChanges as $change)
                                    <tr data-quotation-history-row>
                                        <td class="text-nowrap">
                                            {{ \App\Support\BusinessTime::format($change->created_at, \App\Support\BusinessTime::DATE_TIME) }}
                                        </td>
                                        <td>
                                            {{ $change->actor_name }}
                                            @if ($change->actor_role)
                                                <span class="d-block small text-muted">
                                                    {{ \App\Models\User::ROLES[$change->actor_role] ?? ucwords(str_replace('_', ' ', $change->actor_role)) }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-nowrap">
                                            <span class="text-muted">{{ \App\Services\QuotationChange::peso($change->previous_amount) }}</span>
                                            <i class="bi bi-arrow-right mx-1" aria-hidden="true"></i>
                                            <span class="visually-hidden">to</span>
                                            <span class="fw-semibold">{{ \App\Services\QuotationChange::peso($change->new_amount) }}</span>
                                        </td>
                                        <td>
                                            @if ($change->file_replaced)
                                                <span class="badge text-bg-primary">Replaced in the same save</span>
                                            @else
                                                <span class="badge text-bg-light border">Kept</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <h6 class="project-history-heading">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    File changes
                </h6>

                <x-document-history-events :events="$fileEvents" label="Quotation" />
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
