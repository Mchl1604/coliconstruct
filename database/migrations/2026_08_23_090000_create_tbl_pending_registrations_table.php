<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A registration that has been filled in but not yet proved.
 *
 * Registration used to write straight to `users` and leave the row unverified,
 * which meant an address typed in by mistake - or one nobody could read the
 * code at - took a real account, a user_code out of the sequence and a place
 * in Configuration's listings, permanently: nothing ever swept them up, and
 * the unique index on `users.email` then refused the address to the person who
 * actually owned it.
 *
 * The details wait here instead. Nothing in `users` exists until the code
 * comes back, and a registration nobody finishes expires on its own.
 *
 * `password` holds the same bcrypt hash the account will be created with, not
 * the password. A row here is worth no more to anybody reading the table than
 * the finished account would be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_pending_registrations', function (Blueprint $table): void {
            $table->id('pending_id');

            // One live registration per address. A second attempt at the same
            // address replaces the first - see UserAccountService::start
            // Registration() - which is what lets somebody who mistyped their
            // name simply fill the form in again.
            $table->string('email')->unique();

            $table->string('full_name');
            $table->string('contact_number');
            $table->date('birthdate');
            $table->string('password');

            // Read by the daily sweep. Set well past the code's own ten
            // minutes so that a person who steps away still comes back to a
            // registration they can resend a code against, rather than a form
            // to fill in twice.
            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index('expires_at', 'pending_registration_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_pending_registrations');
    }
};
