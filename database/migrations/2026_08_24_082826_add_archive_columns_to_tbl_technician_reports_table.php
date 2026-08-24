<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving a technician report.
 *
 * A soft archive, exactly as accounts, projects and enquiries already do it: a
 * flag, a timestamp and the account responsible. Nothing is deleted - the
 * report keeps its project, its technician, its submitter, its images and the
 * date it was filed - it simply stops appearing on the active lists.
 *
 * `archived_by` is nullable and nullOnDelete for the same reason `submitted_by`
 * is: the administrator who filed a report away may themselves be removed
 * later, and a row archived before this column existed has no answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_technician_reports', function (Blueprint $table): void {
            $table->boolean('is_archived')->default(false)->after('report_type');
            $table->timestamp('archived_at')->nullable()->after('is_archived');
            $table->foreignId('archived_by')
                ->nullable()
                ->after('archived_at')
                ->constrained('users')
                ->nullOnDelete();

            // Every listing now asks "archived or not" first and then orders by
            // date, which is exactly this index.
            $table->index(['is_archived', 'report_date'], 'technician_reports_archived_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_technician_reports', function (Blueprint $table): void {
            $table->dropIndex('technician_reports_archived_date_index');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['is_archived', 'archived_at']);
        });
    }
};
