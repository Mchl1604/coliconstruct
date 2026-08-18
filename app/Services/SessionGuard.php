<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Throwable;

/**
 * Ending the sessions an account already has open.
 *
 * A password is changed for one of two reasons: the holder wants a new one, or
 * somebody else knows the old one. The second is the case that matters, and
 * until now it was not handled at all - the new password took effect, and
 * whoever was already signed in stayed signed in, because a session in this
 * application is a row in the `sessions` table and nothing about a password
 * change touched it.
 *
 * The code that did exist rotated `remember_token`, with a comment saying
 * every other session was invalidated. That is true of applications that issue
 * remember-me cookies; this one deliberately does not ("no keep me signed in"
 * - see AuthController), so the rotation invalidated nothing.
 *
 * Laravel's own AuthenticateSession middleware solves this by stamping the
 * password hash into the session and comparing it on each request. It is not
 * used here because it logs a user out of the session they are currently in as
 * well, which turns "change your own password" into "change your own password
 * and sign in again" - and because the sessions are in a table this can simply
 * read.
 */
class SessionGuard
{
    /**
     * Sign this account out everywhere except, optionally, where it is now.
     *
     * @param  bool  $exceptCurrent  keep the session making this request, which
     *                               is what somebody changing their own
     *                               password expects. False for a reset the
     *                               account holder is not driving.
     * @return int how many sessions were ended, so a caller can say so
     */
    public function logOutOtherSessions(User $user, bool $exceptCurrent = true): int
    {
        // Only the database driver keeps sessions somewhere they can be read
        // back. On any other driver this is a no-op rather than a failure: the
        // password change itself must not depend on it.
        if (Config::get('session.driver') !== 'database') {
            return 0;
        }

        try {
            $query = DB::table(Config::get('session.table', 'sessions'))
                ->where('user_id', $user->id);

            if ($exceptCurrent && Session::isStarted()) {
                $query->where('id', '!=', Session::getId());
            }

            return $query->delete();
        } catch (Throwable $exception) {
            // Worth knowing about, but not worth failing a password change
            // over - the new password is already saved by the time this runs.
            report($exception);

            return 0;
        }
    }
}
