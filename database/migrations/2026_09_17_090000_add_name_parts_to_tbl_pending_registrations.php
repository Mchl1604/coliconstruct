<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registration asks for the name in parts - first name, middle initial, last
 * name - instead of one box that had to be guessed apart afterwards.
 *
 * Nullable so a registration already waiting on its code still completes;
 * UserAccountService falls back to splitting `full_name` for one of those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_pending_registrations', function (Blueprint $table): void {
            $table->string('first_name', 100)->nullable()->after('email');
            $table->string('middle_name', 1)->nullable()->after('first_name');
            $table->string('last_name', 100)->nullable()->after('middle_name');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_pending_registrations', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'middle_name', 'last_name']);
        });
    }
};
