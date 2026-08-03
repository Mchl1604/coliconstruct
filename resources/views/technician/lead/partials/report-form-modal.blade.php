@php
    $projects = $projects ?? collect();
    $reportTypes = $reportTypes ?? \App\Models\TechnicianReport::TYPES;
@endphp

{{--
    Submit a technician report. Identical fields to the Super Admin report
    form; the project select is hidden when the dialog is opened from inside a
    project, where there is nothing to choose.
--}}
<div class="modal fade" id="reportFormModal" tabindex="-1" aria-hidden="true" data-report-form-modal>
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" data-report-form enctype="multipart/form-data">
            @csrf

            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i>
                    Submit Technician Report
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3" data-report-form-project-wrap>
                    <label class="form-label fw-semibold" for="reportFormProject">Project</label>
                    <select class="form-select" id="reportFormProject" data-report-form-project required>
                        <option value="" selected disabled>Select a project&hellip;</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->project_id }}">
                                {{ $project->reference_no }} &mdash; {{ $project->name }}
                            </option>
                        @endforeach
                    </select>
                    @if ($projects->isEmpty())
                        <div class="form-text text-danger">
                            None of your projects can receive a report right now.
                        </div>
                    @endif
                </div>

                <p class="text-secondary small mb-3 d-none" data-report-form-fixed-project></p>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="reportFormType">Report Type</label>
                    <select class="form-select" id="reportFormType" name="report_type" required>
                        <option value="" selected disabled>Select report type&hellip;</option>
                        @foreach ($reportTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="reportFormTitle">Report Title</label>
                    <input type="text" class="form-control" id="reportFormTitle" name="report_title"
                        placeholder="Enter report title" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="reportFormDescription">Description</label>
                    <textarea class="form-control" id="reportFormDescription" name="report_description" rows="5"
                        placeholder="Describe the work completed or the incident..." required></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="reportFormImages">Attachment</label>
                    <input type="file" class="form-control" id="reportFormImages" name="images[]"
                        accept=".jpg,.jpeg,.png" multiple data-report-form-images>
                    <div class="form-text">JPG, JPEG or PNG, up to 5 MB each. Optional.</div>
                </div>

                <div class="row g-2" data-report-form-preview></div>

                <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-report-form-error></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" data-report-form-submit>
                    <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"
                        data-spinner></span>
                    <i class="bi bi-send me-1" aria-hidden="true"></i>
                    Submit Report
                </button>
            </div>
        </form>
    </div>
</div>
