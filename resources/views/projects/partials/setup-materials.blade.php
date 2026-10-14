{{--
    Step 2 of project setup: the materials the job needs.

    Inside the setup form, so Save Without Locking and Finalize send it with
    the phases - setupMaterials.js keeps the rows' inputs named materials[N][...]
    and ProjectPhaseController::submittedMaterials() reads them. The
    `materials_submitted` marker is what tells the controller this request
    carries a plan at all.

    Read-only once the project has been set up: a Super Admin who unlocks the
    phases later sees the plan as it stands, and changes it on the Materials
    tab instead.
--}}
@php
    $materialErrors = collect($errors->getMessages())
        ->filter(fn ($messages, $key) => str_starts_with($key, 'materials'))
        ->flatten()
        ->unique()
        ->values();
@endphp

<div class="material-plan-card" id="setupMaterials" @if ($materialErrors->isNotEmpty()) data-material-errors @endif>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h5 class="fw-bold mb-1">Materials Needed</h5>
            <p class="text-secondary small mb-0">
                @if ($materialPlanOpen)
                    List what this job needs.
                @else
                    Change these on the Materials tab.
                @endif
            </p>
        </div>
    </div>

    @if ($materialPlanOpen)
        <input type="hidden" name="materials_submitted" value="1">

        @if ($materialErrors->isNotEmpty())
            <div class="alert alert-danger small py-2">
                <p class="fw-semibold mb-1">Materials not saved:</p>
                <ul class="mb-0 ps-3">
                    @foreach ($materialErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="row g-2 align-items-end mb-3">
            <div class="col-md">
                <label class="form-label small fw-semibold mb-1" for="setupMaterialSearch">Add material</label>
                <div>
                    <input type="search" class="form-control" id="setupMaterialSearch"
                        placeholder="Search name, brand or type" data-setup-material-search>
                </div>
            </div>
        </div>

        @if ($suggestedMaterials->isNotEmpty())
            <div class="small text-secondary fw-semibold mb-1">Often used</div>
            <div class="material-suggestions" data-setup-material-suggestions>
                @foreach ($suggestedMaterials as $suggestion)
                    <button type="button" class="material-suggestion-chip"
                        data-setup-material-suggestion='@json($suggestion)'>
                        <i class="bi bi-plus" aria-hidden="true"></i>
                        {{ $suggestion['label'] }}
                    </button>
                @endforeach
            </div>
        @endif

        <div class="table-responsive">
            <table class="table align-middle mb-0 material-plan-table">
                <thead class="table-light">
                    <tr>
                        <th>Material</th>
                        <th>Type</th>
                        <th>Unit</th>
                        <th>Qty Needed</th>
                        <th>Notes</th>
                        <th class="text-center"><span class="visually-hidden">Remove</span></th>
                    </tr>
                </thead>
                <tbody data-setup-material-rows></tbody>
            </table>
        </div>

        <div class="material-plan-empty mt-2 d-none" data-setup-material-empty>
            No materials yet.
        </div>

        <p class="phase-error d-none" role="alert" data-setup-material-error></p>

        {{-- The starting rows, read once by setupMaterials.js. --}}
        <span class="d-none" data-setup-material-initial='@json($materialRows)'
            data-max-materials="{{ $maxMaterials }}"></span>
    @else
        @if ($materialSummary->isEmpty())
            <div class="material-plan-empty">No materials listed.</div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0 material-plan-table">
                    <thead class="table-light">
                        <tr>
                            <th>Material</th>
                            <th>Unit</th>
                            <th class="text-end">Needed</th>
                            <th class="text-end">On Site</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($materialSummary as $line)
                            <tr>
                                <td>
                                    <span class="material-name">{{ $line['material']->name }}</span>
                                    @if ($line['material']->brand)
                                        <span class="material-brand">{{ $line['material']->brand }}</span>
                                    @endif
                                </td>
                                <td><span class="material-unit-chip">{{ $line['material']->unit->value }}</span></td>
                                <td class="text-end material-qty">{{ $line['material']->unit->format($line['needed']) }}</td>
                                <td class="text-end material-qty">{{ $line['material']->unit->format($line['on_site']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
