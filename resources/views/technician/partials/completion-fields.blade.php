@php
    // The two dialogs that close a project - one on My Projects, one on the
    // project page - collect the same report, so the fields live here. Same
    // set the Super Admin completion modal asks for, except the photographs
    // are required: a lead is on site, so they are the evidence.
    $suffix = $suffix ?? '';
@endphp

<div class="mb-3">
    <label class="form-label fw-semibold" for="completionDate{{ $suffix }}">Completion Date</label>
    <input type="date" class="form-control" id="completionDate{{ $suffix }}" name="completion_date"
        value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" required>
</div>

<div class="mb-3">
    <label class="form-label fw-semibold" for="completionSummary{{ $suffix }}">Completion Summary</label>
    <textarea class="form-control" id="completionSummary{{ $suffix }}" name="completion_summary" rows="3"
        placeholder="Summarize the work that was completed..." required></textarea>
</div>

<div class="mb-3">
    <label class="form-label fw-semibold" for="completionRemarks{{ $suffix }}">
        Completion Remarks
        <span class="text-muted fw-normal">(optional)</span>
    </label>
    <textarea class="form-control" id="completionRemarks{{ $suffix }}" name="completion_remarks" rows="2"
        placeholder="Any additional remarks"></textarea>
</div>

<div class="mb-1">
    <label class="form-label fw-semibold" for="completionPhotos{{ $suffix }}">
        Upload Completion Photos
    </label>
    <input type="file" class="form-control" id="completionPhotos{{ $suffix }}" name="completion_photos[]"
        accept=".jpg,.jpeg,.png" multiple required data-image-input
        data-image-preview-target="#completionPhotoPreview{{ $suffix }}">
    <div class="form-text">
        JPG, JPEG or PNG, up to 5 MB each. At least one photo is required.
    </div>
</div>

<div class="row g-2 mt-1" id="completionPhotoPreview{{ $suffix }}"></div>
