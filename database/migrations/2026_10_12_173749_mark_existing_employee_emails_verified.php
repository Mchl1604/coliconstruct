<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Employee accounts now prove their address on their first sign-in - see
 * User::requiresEmailVerification().
 *
 * Every employee account that already exists is marked verified. Until now an
 * employee was never asked, so a null here is not an address nobody has
 * proved - it is an account seeded without the column, the Super Admin's
 * included, and it must not be stopped at the sign-in screen by a check that
 * did not exist when it was made.
 *
 * Clients are left alone: an unverified client is one whose registration was
 * never finished, and that is still true.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->where('role', '!=', 'client')
            ->update(['email_verified_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    /**
     * Nothing to undo: which of these rows were null before cannot be told
     * apart from the ones verified since, and un-verifying them would stop
     * every one of them at the sign-in screen.
     */
    public function down(): void {}
};
