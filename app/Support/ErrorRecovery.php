<?php

namespace App\Support;

use App\Models\User;
use Throwable;

/**
 * The way out of an error page.
 *
 * An error page is the one screen that must never fail itself, so nothing
 * here may throw: a 500 can be the database being down, and a 503 is rendered
 * before any session exists. Whatever cannot be worked out falls back to the
 * public home page, which every visitor is allowed to see.
 *
 * The destination is PortalHome's, so an error page never offers a link the
 * reader's role would be turned away from.
 */
class ErrorRecovery
{
    /**
     * The button label for each role's home, keyed like PortalHome's routes.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'super_admin' => 'Go to Dashboard',
        'admin' => 'Go to Dashboard',
        'lead_technician' => 'Go to My Schedule',
        'technician' => 'Go to My Schedule',
    ];

    /**
     * Where this reader should be sent to start again.
     *
     * @return array{label: string, url: string}
     */
    public static function homeAction(): array
    {
        try {
            $user = self::viewer();

            return [
                'label' => self::LABELS[$user?->role] ?? 'Back to Home',
                'url' => $user && isset(self::LABELS[$user->role])
                    ? PortalHome::url($user)
                    : route('landing.home'),
            ];
        } catch (Throwable) {
            return ['label' => 'Back to Home', 'url' => url('/')];
        }
    }

    /**
     * Whether the reader is signed in, for the pages that offer Sign In to a
     * guest instead.
     */
    public static function isGuest(): bool
    {
        try {
            return self::viewer() === null;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The signed-in user, asked for only when this request has a session.
     *
     * A request that failed before the web middleware ran - maintenance mode,
     * or a fault in the kernel itself - has no session to read, and asking the
     * guard anyway would reach for the database for nothing.
     */
    private static function viewer(): ?User
    {
        $request = request();

        if (! $request->hasSession() || ! $request->session()->isStarted()) {
            return null;
        }

        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
