{{-- The photos filed with a report, on its printable page. Expects $photos. --}}
@if ($photos->isNotEmpty())
    <div class="report-form-section is-purple">
        <h6 class="report-form-section-title">
            <i class="bi bi-camera" aria-hidden="true"></i>
            Photos
        </h6>
        <div class="d-flex flex-wrap gap-2">
            @foreach ($photos as $image)
                <a href="{{ $image->url() }}" target="_blank" rel="noopener noreferrer">
                    <img src="{{ $image->url() }}" class="rounded shadow-sm"
                        style="width: 9rem; height: 9rem; object-fit: cover" alt="Report photo">
                </a>
            @endforeach
        </div>
    </div>
@endif
