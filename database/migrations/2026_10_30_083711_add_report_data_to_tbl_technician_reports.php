<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A progress report is generated now rather than written: the system gathers
 * the day - the phase, the team, the task updates, the materials, the
 * incidents - and the lead adds remarks. What was gathered is kept here as it
 * was at submission, so a later edit to a task or a voided material entry
 * never changes a report already filed.
 *
 * Null for the reports written before this, which keep reading from their
 * description alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_technician_reports', function (Blueprint $table): void {
            $table->json('report_data')->nullable()->after('report_description');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_technician_reports', function (Blueprint $table): void {
            $table->dropColumn('report_data');
        });
    }
};
