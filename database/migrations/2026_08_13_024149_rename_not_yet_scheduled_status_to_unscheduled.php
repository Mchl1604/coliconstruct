<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A project with no dates is now Unscheduled rather than Not Yet
     * Scheduled. The old wording only ever described a project that had not
     * been scheduled *yet*, which stopped being the whole story once a date
     * could be removed from a schedule: a project can now arrive back in this
     * state having been scheduled and then emptied.
     *
     * The status is a plain string column with no constraint on it, so the
     * rename is the value and nothing else. Every reader was updated in the
     * same change.
     */
    public function up(): void
    {
        DB::table('tbl_projects')
            ->where('status', 'not_yet_scheduled')
            ->update(['status' => 'unscheduled']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tbl_projects')
            ->where('status', 'unscheduled')
            ->update(['status' => 'not_yet_scheduled']);
    }
};
