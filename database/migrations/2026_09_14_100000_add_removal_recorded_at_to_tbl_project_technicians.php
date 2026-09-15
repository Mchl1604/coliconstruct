<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a removal was DECIDED, kept apart from when it takes effect.
 *
 * removed_at used to be both at once: a technician was taken off the team the
 * moment somebody pressed save. A removal can now be scheduled - "John comes off
 * this project from Aug 21" - so removed_at is the first day they are no longer
 * on the team, which may be weeks after the decision. The team history still
 * has to say when the decision was made and by whom, and that is this column.
 *
 * Every removal that already exists took effect the moment it was recorded, so
 * the backfill is exact: the two were the same instant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_project_technicians', function (Blueprint $table) {
            $table->timestamp('removal_recorded_at')->nullable()->after('removed_by');
        });

        DB::table('tbl_project_technicians')
            ->whereNotNull('removed_at')
            ->update(['removal_recorded_at' => DB::raw('removed_at')]);
    }

    public function down(): void
    {
        Schema::table('tbl_project_technicians', function (Blueprint $table) {
            $table->dropColumn('removal_recorded_at');
        });
    }
};
