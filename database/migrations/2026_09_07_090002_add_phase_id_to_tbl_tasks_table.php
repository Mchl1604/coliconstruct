<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which phase a task belongs to.
 *
 * Required of every task created from now on - the create dialogs will not
 * submit without it, and a project whose phases are not finalized takes no new
 * tasks at all. Nullable here all the same, for two reasons:
 *
 *   - The column has to exist before the backfill that fills it can run.
 *   - `nullOnDelete` is the safety net under a rule the application enforces
 *     properly: a phase holding tasks cannot be removed at all, and a Super
 *     Admin removing one is made to say where its tasks go first. If a row
 *     ever does slip through, an orphaned task is a task somebody can see and
 *     reassign - a deleted one is gone.
 *
 * Never `cascadeOnDelete`. Deleting a phase must not delete the work recorded
 * against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('phase_id')->nullable()->after('project_id');

            $table->foreign('phase_id')
                ->references('phase_id')
                ->on('tbl_project_phases')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_tasks', function (Blueprint $table): void {
            $table->dropForeign(['phase_id']);
            $table->dropColumn('phase_id');
        });
    }
};
