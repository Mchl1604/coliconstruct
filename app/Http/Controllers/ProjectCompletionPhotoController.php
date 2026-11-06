<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectCompletionPhoto;
use App\Services\ProjectCompletion;
use App\Support\UploadStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Project Details -> Completion Photos: added ahead of completing, by whoever
 * may complete the project. Clients see them once it is reported finished.
 */
class ProjectCompletionPhotoController extends Controller
{
    public function store(Request $request, ProjectCompletion $completion, int $id): RedirectResponse
    {
        $project = Project::query()->findOrFail($id);

        $this->authorize('manageCompletionPhotos', $project);

        $validator = Validator::make($request->all(), $completion->photoRules(), $completion->messages());

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first());
        }

        $completion->storePhotos($project, $request->file('completion_photos'));

        return back()->with('success', 'Photos added.');
    }

    public function destroy(Request $request, int $id, int $photo): RedirectResponse
    {
        $project = Project::query()->findOrFail($id);

        $this->authorize('manageCompletionPhotos', $project);

        $record = ProjectCompletionPhoto::query()
            ->where('project_id', $project->project_id)
            ->findOrFail($photo);

        UploadStore::remove($record->photo_path);
        $record->delete();

        return back()->with('success', 'Photo removed.');
    }
}
