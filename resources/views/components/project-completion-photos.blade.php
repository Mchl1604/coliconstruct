{{--
    Project Details -> Completion Photos: added here rather than on the Service
    Report form. Shown to whoever may complete the project, while it is live.
--}}
@props([
    'project',
    'portal' => 'super-admin',
])

@can('manageCompletionPhotos', $project)
    <section class="mt-3" id="completion-photos">
        <div class="fw-semibold mb-2">Completion Photos</div>

        @if ($project->completionPhotos->isNotEmpty())
            <div class="d-flex flex-wrap gap-2 mb-2">
                @foreach ($project->completionPhotos as $photo)
                    <div class="position-relative">
                        <a href="{{ $photo->url() }}" target="_blank" rel="noopener noreferrer">
                            <img src="{{ $photo->url() }}" class="rounded border" alt="Completion photo"
                                style="width: 6rem; height: 6rem; object-fit: cover">
                        </a>
                        <form method="POST" class="position-absolute top-0 end-0 m-1" data-workspace-native
                            action="{{ route($portal.'.projects.completion-photos.destroy', [$project->project_id, $photo->completion_photo_id]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger py-0 px-1" title="Remove photo" aria-label="Remove photo">
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif

        <form method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2" data-workspace-native
            action="{{ route($portal.'.projects.completion-photos.store', $project->project_id) }}">
            @csrf
            <input type="file" class="form-control form-control-sm w-auto" name="completion_photos[]"
                accept=".jpg,.jpeg,.png" multiple required aria-label="Completion photos">
            <button type="submit" class="btn btn-sm btn-outline-primary">Upload</button>
        </form>
    </section>
@endcan
