<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The address a user has asked to move to but has not yet proved they own.
 *
 * A change of email is not applied when the form is submitted: the new address
 * is parked here until a code sent to it comes back verified, so a typo - or
 * somebody else's address - can never take an account over.
 *
 * Every account that already exists is marked verified. They were created
 * before this workflow existed, and an account that has been signing in for
 * months must not be locked out by a column that was null the whole time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pending_email')->nullable()->after('email');
        });

        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('pending_email');
        });
    }
};
