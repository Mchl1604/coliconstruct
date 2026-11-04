<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An incident's review is now Unresolved or Resolved, so one still under review
 * is unresolved. Not undone on the way down: which ones they were is not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tbl_incident_reports')->where('status', 'under_review')->update(['status' => 'open']);
    }

    public function down(): void {}
};
