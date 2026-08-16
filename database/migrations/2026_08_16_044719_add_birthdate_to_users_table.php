<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account holder's date of birth, which every form that opens an account
 * now asks for so that nobody under 18 can be given one.
 *
 * Nullable, because the accounts that already exist were opened before the
 * question was asked and there is no honest value to invent for them. New
 * accounts always carry one - the rule lives in the validation, not the
 * schema, so an administrator can still fix an older record without being
 * blocked from saving the rest of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birthdate')->nullable()->after('contact_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('birthdate');
        });
    }
};
