<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use App\Services\SystemContentService;
use App\Support\PortalHome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading the Terms and Conditions, and agreeing to them.
 *
 * Two endpoints and nothing else. The terms themselves are one editable field
 * in System Settings and are read through SystemContentService like every
 * other piece of the site, so there is no document store here and no editor -
 * that is the Super Admin's, in Configuration, and it stays there.
 *
 * There is deliberately no Terms and Conditions PAGE. Every place the document
 * is shown - the registration form, the website footer, the dialog a client
 * meets after signing in - is a modal, because in all three the reader is in
 * the middle of something they should not be sent away from. `show` exists for
 * anything that wants the current text and version without rendering a page.
 */
class TermsController extends Controller
{
    public function __construct(
        private readonly SystemContentService $content,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * The current terms and the fingerprint identifying them.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'terms' => $this->content->terms(),
            'version' => $this->content->termsVersion(),
            // What the reader's own position is, so a caller does not have to
            // work it out from the version itself.
            'acceptance_required' => (bool) $user?->requiresTermsAcceptance(),
            'accepted_at' => $user?->terms_accepted_at?->toIso8601String(),
        ]);
    }

    /**
     * Record that the signed-in account agrees to the terms as they stand.
     *
     * The version is read from the settings rather than from the request, and
     * the account is the authenticated one rather than an id in the body -
     * see User::acceptCurrentTerms(). Between them, there is nothing in this
     * request a client could change to accept a document they were not shown,
     * and nothing they could change to accept one on somebody else's behalf.
     *
     * Open to any signed-in account rather than to clients alone. Only a
     * client is ever HELD by the requirement, but an employee who agrees is
     * recording a true fact about themselves, and an endpoint that refuses it
     * would be refusing the one thing it exists to write down.
     */
    public function accept(Request $request)
    {
        $user = $request->user();

        // Nothing to record and nothing to say: agreeing twice to the same
        // words is not an error, it is a page that was open in two tabs.
        if (! $user->hasAcceptedCurrentTerms()) {
            $user->acceptCurrentTerms();

            $this->activityLogger->record(
                ActivityLog::TERMS_ACCEPTED,
                $user,
                sprintf(
                    '%s accepted the Terms and Conditions (version %s).',
                    $user->fullName(),
                    substr($user->terms_accepted_version, 0, 12)
                )
            );
        }

        $message = 'Thank you - your acceptance has been recorded.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'version' => $user->terms_accepted_version,
                'accepted_at' => $user->terms_accepted_at?->toIso8601String(),
            ]);
        }

        // Back to whichever portal this account calls home - the public
        // website for a client, and their own for anybody else. Read from
        // PortalHome so the way through the dialog lands in the same place
        // signing in does.
        return redirect(PortalHome::url($user))->with('success', $message);
    }
}
