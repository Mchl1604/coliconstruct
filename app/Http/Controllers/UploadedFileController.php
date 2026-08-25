<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectCompletionPhoto;
use App\Models\TaskImage;
use App\Models\TechnicianReportImage;
use App\Models\User;
use App\Services\ClientProjects;
use App\Services\SystemContentService;
use App\Support\UploadStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every uploaded file is read through here.
 *
 * Nothing a person uploads is served by the web server any more. It used to
 * be: files sat under public/ or behind the storage symlink, so the URL alone
 * was the whole of the access control - permanent, unauthenticated, and as
 * shareable as any other link. A client's contract was one forwarded message,
 * one entry in a browser's history or one Referer header away from anybody.
 *
 * Now the bytes come out of a private disk and every route below asks who is
 * requesting them first. The cost is that the application serves the file
 * rather than the web server; the gain is that "who may see this contract" has
 * the same answer as "who may see this project", in one place, rather than
 * being a property of whether a URL got out.
 *
 * Content-Disposition is always inline and the type is always taken from the
 * stored file rather than from anything a request said, so a browser renders
 * what is there and never negotiates its way into something else.
 */
class UploadedFileController extends Controller
{
    public function __construct(private readonly ClientProjects $clientProjects) {}

    /**
     * A profile picture.
     *
     * Visible to any signed-in account, because avatars appear beside names
     * throughout the interface - team listings, task cards, activity. It is
     * the one upload whose audience is "everybody inside", so it is the one
     * that does not ask about a project.
     */
    public function profilePhoto(User $user): StreamedResponse
    {
        abort_unless(filled($user->profile_photo_path), 404);

        return $this->stream($user->profile_photo_path);
    }

    /**
     * A project document: a quotation, a contract, an assessment.
     */
    public function document(Request $request, Document $document): StreamedResponse
    {
        $this->authorizeProject($request, $document->project);

        return $this->stream($document->document_path, $document->document_name);
    }

    public function completionPhoto(Request $request, ProjectCompletionPhoto $photo): StreamedResponse
    {
        $this->authorizeProject($request, $photo->project);

        return $this->stream($photo->photo_path);
    }

    /**
     * A photograph filed against a task on completion.
     *
     * The project's audience, narrowed by the task's own: a plain technician
     * reads their own work and nothing else, so the photograph a colleague
     * filed is out of reach here as well as on the page. Without this, a
     * technician who could no longer see a colleague's task could still fetch
     * the picture from it by asking for the id - which is exactly the door
     * this controller exists to close.
     */
    public function taskImage(Request $request, TaskImage $image): StreamedResponse
    {
        $task = $image->task;

        $this->authorizeProject($request, $task?->project);

        // Only the technician roles are narrowed further; the office reads the
        // whole board and a client never reaches a task photograph at all.
        if ($task !== null && $request->user()?->needsTechnicianRecord()) {
            abort_unless(Gate::forUser($request->user())->allows('view', $task), 403);
        }

        return $this->stream($image->image_path);
    }

    public function reportImage(Request $request, TechnicianReportImage $image): StreamedResponse
    {
        $this->authorizeProject($request, $image->report?->project);

        return $this->stream($image->image_path);
    }

    /**
     * A logo or an illustration on the public website.
     *
     * Deliberately open: this is the one kind of upload whose whole purpose is
     * to be seen by people who are not signed in. It is also why SVG is not an
     * accepted format for it - see SystemContentService::ALLOWED_IMAGE_
     * EXTENSIONS - because an SVG is a document that can carry script, and
     * this route serves from the application's own origin.
     */
    public function systemContent(string $key): StreamedResponse
    {
        $path = app(SystemContentService::class)->imagePath($key);

        abort_unless(filled($path), 404);

        return $this->stream((string) $path);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Whether the person asking may see this project at all.
     *
     * The same three ways in that the rest of the application recognises: the
     * office sees everything, a technician sees the work they are on, and a
     * client sees their own. A file inherits the project's audience rather
     * than having one of its own, which is the point - there is no way for the
     * two to disagree.
     */
    private function authorizeProject(Request $request, ?Project $project): void
    {
        abort_unless($project !== null, 404);

        $user = $request->user();

        abort_unless($user !== null && $user->canLogin(), 403);

        if ($user->isEmployee() && ! $user->needsTechnicianRecord()) {
            return;
        }

        if ($user->needsTechnicianRecord() && Gate::forUser($user)->allows('viewAssigned', $project)) {
            return;
        }

        if ($this->clientProjects->findForUser($user, (int) $project->project_id) !== null) {
            return;
        }

        abort(403);
    }

    /**
     * Hand the file back, or 404 if the disk no longer has it.
     *
     * A missing file is a 404 rather than a 500: rows outlive their files -
     * anything uploaded before this moved to durable storage is simply gone -
     * and a broken image is the honest answer, not a server error.
     */
    private function stream(string $path, ?string $downloadName = null): StreamedResponse
    {
        $disk = UploadStore::disk();

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $downloadName, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            // Nothing here is public, so no shared cache may keep a copy.
            'Cache-Control' => 'private, max-age=300',
            // The browser renders it; it never runs it as this origin.
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
