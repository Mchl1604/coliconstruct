<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tbl_technician_reports.technician_id was created pointing at users.id, but
 * every read and write path treats it as a tbl_technicians.technician_id:
 * TechnicianReport::technician() belongs to Technician, and both the Super
 * Admin report form and the technician portal store a technician id in it.
 *
 * The two id spaces only overlap by accident, so filing a report failed with
 * a foreign key violation whenever a technician's id was not also a user id.
 *
 * The create migration now names the right table, so a fresh database never
 * has the problem. This repairs the databases that were built before that
 * correction, and does nothing on the ones that were not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->pointsAtUsers()) {
            return;
        }

        Schema::table('tbl_technician_reports', function ($table): void {
            $table->dropForeign('tbl_technician_reports_technician_id_foreign');

            $table->foreign('technician_id')
                ->references('technician_id')
                ->on('tbl_technicians')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Deliberately not reversed: the old constraint pointed at the wrong
        // table, and putting it back would only break report creation again.
    }

    /**
     * Whether this database still carries the original, wrong constraint.
     *
     * Only MySQL is inspected: the constraint is read out of
     * information_schema, and the driver used elsewhere (SQLite, in tests)
     * builds its schema from the corrected create migration.
     */
    private function pointsAtUsers(): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.key_column_usage')
            ->whereRaw('table_schema = database()')
            ->where('table_name', 'tbl_technician_reports')
            ->where('column_name', 'technician_id')
            ->where('referenced_table_name', 'users')
            ->exists();
    }
};
