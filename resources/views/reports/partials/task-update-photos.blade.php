{{--
    A task's update photos in a progress report, from the ids its snapshot
    froze and only those this viewer may open.

    Expects $imageIds and $viewablePhotoIds.
--}}
@php
    $visibleImageIds = array_values(array_intersect($imageIds, $viewablePhotoIds));
@endphp

@if ($visibleImageIds !== [])
    <div class="report-doc-photos">
        @foreach ($visibleImageIds as $imageId)
            <a href="{{ route('media.task-update-image', ['image' => $imageId]) }}" target="_blank" rel="noopener noreferrer">
                <img src="{{ route('media.task-update-image', ['image' => $imageId]) }}" alt="Task update photo">
            </a>
        @endforeach
    </div>
@endif
