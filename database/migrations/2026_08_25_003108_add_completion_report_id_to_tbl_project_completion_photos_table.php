<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which completion cycle a photograph belongs to.
 *
 * Null means the current one, which is what every existing row is and what
 * every newly uploaded photograph starts as. A reopen stamps the cycle's
 * report id onto the photographs that were filed under it, so the next
 * completion's photographs are not shown beside the previous one's - and the
 * previous one's are still there to be shown with the report they belong to.
 *
 * Nothing is deleted and no file is moved: only the row's owner changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_project_completion_photos', function (Blueprint $table): void {
            $table->unsignedBigInteger('completion_report_id')->nullable()->after('project_id');

            $table->foreign('completion_report_id')
                ->references('completion_report_id')
                ->on('tbl_project_completion_reports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_project_completion_photos', function (Blueprint $table): void {
            $table->dropForeign(['completion_report_id']);
            $table->dropColumn('completion_report_id');
        });
    }
};
