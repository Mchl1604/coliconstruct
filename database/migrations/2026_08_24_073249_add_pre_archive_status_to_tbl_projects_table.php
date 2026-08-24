<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a project was before it was archived, so restoring can put it back.
 *
 * Archiving now keeps the project whole - its schedule, its team, its dates -
 * and only moves it out of the active list. The one thing it cannot keep in
 * place is the status column itself, because `archived` has to live there for
 * every guard and every availability query that already reads it.
 *
 * Nothing else on the row can stand in for it. `cancelled_at` and the
 * completion columns survive a reopen, `on_hold` is a separate flag, and
 * Pending / Ongoing / Unscheduled are derived from dates that may have moved
 * on by the time somebody restores. Guessing would mean a project quietly
 * coming back as something it never was, so the answer is recorded instead.
 *
 * Null on every existing row, and null is meaningful: those were archived by
 * the old flow, which deleted the schedule and the team on the way in. There
 * is no earlier state left to return them to, so they keep restoring as
 * Unscheduled exactly as they always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->string('pre_archive_status')->nullable()->after('archived_by');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->dropColumn('pre_archive_status');
        });
    }
};
