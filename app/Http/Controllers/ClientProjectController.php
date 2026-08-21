<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Services\ActivityLogger;
use App\Services\ClientProjects;
use App\Services\NotificationService;
use App\Services\ProjectCompletion;
use App\Services\ProjectEmails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one thing a client does rather than reads.
 *
 * A client has no portal: they sign in to the public website and follow their
 * work on My Projects, which until now was entirely read-only. Confirming a
 * completed project is the single action they take, so it lives here rather
 * than being bolted onto PublicSiteController, which renders pages and nothing
 * else.
 *
 * Ownership is the whole of the permission model and it is not re-derived
 * here: ClientProjects::findForUser() matches the project's contact address
 * against the signed-in account exactly as every other client-facing query
 * does, and hands back nothing for somebody else's project. A client guessing
 * an id gets a 404, indistinguishable from a project that does not exist.
 */
class ClientProjectController extends Controller
{
    public function __construct(
        private readonly ClientProjects $clientProjects,
        private readonly ProjectCompletion $completion,
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
        private readonly ProjectEmails $clientEmails
    ) {}

    /**
     * Confirm that a completed project really is finished.
     *
     * Closes the project for good. Nothing about the schedule changes - the
     * dates were settled when completion was requested - and nothing about the
     * completion report changes either; this records that the client agreed
     * with it, and when.
     */
    public function confirm(Request $request, int $project)
    {
        $user = $request->user();

        abort_unless($user && $user->isClient(), 403);

        // The ownership check. A project belonging to another client is not
        // found, rather than found and refused.
        $record = $this->clientProjects->findForUser($user, $project);

        abort_unless($record, 404);

        $back = redirect()->route('public.projects.show', $project);

        // Re-read rather than trusted from the page: seven days is long enough
        // for the project to have been auto-completed or reopened in another
        // tab, and confirming either of those would be wrong.
        if (! $record->isAwaitingClientConfirmation()) {
            return $back->with('error', $record->isCompleted()
                ? 'This project is already complete.'
                : 'This project is not waiting for your confirmation.');
        }

        try {
            DB::transaction(function () use ($record, $user): void {
                $this->completion->confirm($record, $user, Project::METHOD_CLIENT_CONFIRMED);
            });
        } catch (Throwable $e) {
            report($e);

            return $back->with('error', 'Unable to save your confirmation. Please try again.');
        }

        $this->activityLogger->record(
            ActivityLog::PROJECT_COMPLETION_CONFIRMED,
            null,
            sprintf(
                "The client confirmed completion of project '%s' "
                    .'(Awaiting Client Confirmation -> Completed, confirmed by the client).',
                $record->reference_no ?? $record->name
            ),
            $record
        );

        $this->notifications->clientConfirmedCompletion($record);
        $this->clientEmails->completionConfirmed($record->refresh());

        return $back->with('success', 'Thank you - this project is now complete.');
    }
}
