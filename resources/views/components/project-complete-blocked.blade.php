{{-- What stands between a project and Complete Project, each with the way to
     deal with it. Expects $blockers: ProjectPolicy::blockerDetailsFor(). --}}
@props(['blockers' => []])

@if ($blockers !== [])
    <div class="modal fade" id="completeBlockedModal" tabindex="-1" aria-hidden="true" aria-labelledby="completeBlockedTitle">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="completeBlockedTitle">Cannot Complete Yet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="mb-0 ps-3">
                        @foreach ($blockers as $blocker)
                            <li class="mb-1">
                                {{ $blocker['message'] }}
                                @if ($blocker['action'])
                                    <a href="{{ $blocker['action']['url'] }}" data-tab-link>{{ $blocker['action']['label'] }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endif
