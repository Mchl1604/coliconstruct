<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who actually filed a report.
 *
 * technician_id answers "which technician is this report about", and cannot
 * hold an administrator - they have no technician record. So a report filed
 * from the Super Admin portal was being attributed to the project's lead,
 * which is somebody who had nothing to do with it.
 *
 * This column records the account that submitted it, whatever their role.
 * Nullable so the reports already on file keep working: they fall back to the
 * technician, which is who filed them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_technician_reports', function (Blueprint $table) {
            $table->foreignId('submitted_by')
                ->nullable()
                ->after('technician_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_technician_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
        });
    }
};
