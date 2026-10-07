<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a project's details page was first opened by the office.
 *
 * Null means nobody has looked at it yet, and the projects list marks the row
 * NEW until somebody does. Every project that already exists has been seen,
 * so it is backfilled rather than left to light up the whole list at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->timestamp('first_viewed_at')->nullable()->after('target_end_date');
        });

        DB::table('tbl_projects')->update(['first_viewed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->dropColumn('first_viewed_at');
        });
    }
};
