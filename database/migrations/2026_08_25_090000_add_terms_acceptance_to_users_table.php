<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Terms and Conditions each account has agreed to, and when.
 *
 * A boolean would not do. "This client accepted the terms" says nothing about
 * WHICH terms, so the moment the Super Admin rewrites them the flag is a
 * record of an agreement to a document that no longer exists - and there is no
 * way left to tell the clients who have read the new wording from the ones who
 * have not. The two columns below are the smallest thing that can answer the
 * three questions the feature actually asks: what is current, what did this
 * client accept, and when did they accept it.
 *
 * The version is a fingerprint of the terms themselves rather than a number
 * somebody has to remember to increment - see SystemContentService::
 * termsVersion(). The terms live in tbl_system_contents as one editable field,
 * with no revision of their own, so deriving the version from the text is what
 * makes "the current version" impossible to get out of step with what the
 * client is actually shown. Saving the editor with nothing changed leaves the
 * fingerprint alone, and nobody is asked to agree to the same words twice.
 *
 * Columns on `users` rather than a table of their own: one acceptance per
 * account is the whole of the requirement, the acceptance belongs to exactly
 * one account, and every other fact of this kind about an account -
 * email_verified_at, must_change_password, last_login_at - already lives here.
 *
 * Every existing account is left null, which reads as "has accepted nothing".
 * That is the correct starting point: no client in a deployed database has
 * ever been shown a version to agree to, so all of them are asked once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // A sha256 in hex - 64 characters. Nullable because an account
            // that has never accepted anything has no version to name.
            $table->string('terms_accepted_version', 64)->nullable()->after('email_verified_at');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_accepted_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_version', 'terms_accepted_at']);
        });
    }
};
