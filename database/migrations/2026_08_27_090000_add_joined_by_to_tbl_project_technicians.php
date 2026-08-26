<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who put a technician on a project, to sit beside who took them off.
 *
 * The membership timeline arrived with removed_by but no counterpart for the
 * other end of the span, which left the team history able to say "Juan was
 * removed by Michael" and only "Juan was added" - the one half of the record
 * anybody asks a history for is who did it.
 *
 * Null for every existing row and for anything the system does on its own -
 * a reopen restoring a team, a console command. Nothing recorded it before,
 * so the honest value is "not known" rather than a guess at the likeliest
 * administrator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_project_technicians', function (Blueprint $table) {
            $table->unsignedBigInteger('joined_by')->nullable()->after('joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_project_technicians', function (Blueprint $table) {
            $table->dropColumn('joined_by');
        });
    }
};
