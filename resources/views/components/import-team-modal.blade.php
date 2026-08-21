@props(['id' => 'importTeamModal'])

{{--
    Copying a technician team from another project, shared by the project
    wizard and the assigned-team editor so the dialog reads the same in both.

    Everything inside is filled in by /js/importTeam.js from
    super-admin.projects.importable-teams. The markup here is only the shape.
--}}
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}Label"
    aria-hidden="true" data-import-team-modal>

    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header align-items-start">
                <div>
                    <span class="import-team-eyebrow">Import Team</span>
                    <h5 class="modal-title mb-0" id="{{ $id }}Label">
                        Copy a technician team from another project
                    </h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                {{-- The list, the search over it, and everything it reports --}}
                <div data-import-browser>
                    <p class="text-secondary small">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                        Only technicians are copied. You can add or remove people afterwards.
                    </p>

                    <div class="input-group mb-3">
                        <span class="input-group-text bg-white">
                            <i class="bi bi-search" aria-hidden="true"></i>
                        </span>
                        <input type="search" class="form-control border-start-0" data-import-search
                            placeholder="Search by project name, client or ID"
                            aria-label="Search projects to import a team from">
                    </div>

                    <div data-import-loading class="text-secondary small py-3">
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Checking technician availability&hellip;
                    </div>

                    <div class="alert alert-danger d-none" role="alert" data-import-error></div>

                    <div data-import-sections></div>

                    <div class="import-team-empty d-none" data-import-empty>
                        No other project has a technician team to copy.
                    </div>

                    <div class="import-team-empty d-none" data-import-no-matches>
                        No project matches that search.
                    </div>
                </div>

                {{-- Shown in place of the list when the imported team brings a
                     lead and this project already has one. Kept inline rather
                     than stacked in a third dialog. --}}
                <div class="import-lead-choice d-none" data-import-lead-choice>
                    <h6 class="fw-bold mb-1">This project already has a lead technician</h6>
                    <p class="text-secondary small mb-0" data-import-lead-summary></p>

                    <div class="import-lead-options">
                        <button type="button" class="import-lead-option" data-import-keep-lead>
                            <span>
                                <span class="import-team-option-title import-lead-option-title">
                                    Keep <span data-import-current-lead></span>
                                </span>
                                <span class="import-lead-option-detail">
                                    The rest of the imported team is added; the lead does not change.
                                </span>
                            </span>
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </button>

                        <button type="button" class="import-lead-option" data-import-use-lead>
                            <span>
                                <span class="import-lead-option-title">
                                    Use <span data-import-imported-lead></span>
                                </span>
                                <span class="import-lead-option-detail">
                                    The imported lead takes over, and the current lead comes off the team.
                                </span>
                            </span>
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </button>
                    </div>

                    <button type="button" class="btn btn-link btn-sm px-0 mt-2" data-import-lead-cancel>
                        Back to the project list
                    </button>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>
