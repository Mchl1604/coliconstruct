<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a project's phase structure has been settled, and by whom.
 *
 * A flag of its own rather than "does this project have any phases?", because
 * those are two different questions and the difference is the whole feature:
 *
 *   - A project part-way through setup HAS phases and is not finalized. Its
 *     structure is still being typed and nothing may be booked against it.
 *   - A finalized project's structure is locked. Admin and Lead Technician
 *     can no longer add, remove or reorder, and "2/4 Phases" therefore means
 *     the same thing tomorrow as it does today.
 *
 * Counting rows would conflate the two, and a monitoring figure whose
 * denominator can move is not a monitoring figure.
 *
 * `phase_count` is the denominator, written once at finalization. It is
 * deliberately stored rather than counted on read: it is what the structure
 * was agreed to be, and if it ever disagrees with the number of rows that is a
 * fact worth being able to see rather than one to paper over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            // 'pending' until somebody finalizes the structure, 'finalized'
            // afterwards. A Super Admin override puts it back to 'pending' -
            // the same screen, the same finalize action, one code path.
            $table->string('phase_setup_status', 20)->default('pending')->after('pre_archive_status');

            // The agreed number of phases. Null while setup is pending.
            $table->unsignedSmallInteger('phase_count')->nullable()->after('phase_setup_status');

            $table->timestamp('phase_setup_finalized_at')->nullable()->after('phase_count');
            $table->unsignedBigInteger('phase_setup_finalized_by')->nullable()->after('phase_setup_finalized_at');

            // The last time a Super Admin unlocked a finalized structure, and
            // the reason they gave. Kept after the structure is finalized
            // again, because "this project's phases were changed mid-flight"
            // is exactly the caveat somebody reading its progress needs.
            $table->timestamp('phase_structure_overridden_at')->nullable()->after('phase_setup_finalized_by');
            $table->unsignedBigInteger('phase_structure_overridden_by')->nullable()->after('phase_structure_overridden_at');
            $table->text('phase_structure_override_reason')->nullable()->after('phase_structure_overridden_by');

            $table->foreign('phase_setup_finalized_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('phase_structure_overridden_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->dropForeign(['phase_setup_finalized_by']);
            $table->dropForeign(['phase_structure_overridden_by']);

            $table->dropColumn([
                'phase_setup_status',
                'phase_count',
                'phase_setup_finalized_at',
                'phase_setup_finalized_by',
                'phase_structure_overridden_at',
                'phase_structure_overridden_by',
                'phase_structure_override_reason',
            ]);
        });
    }
};
