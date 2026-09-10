<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which stage of the vocabulary a phase was suggested from, if any.
 *
 * A provenance stamp and nothing else. Every screen goes on reading the phase's
 * own `title` and `description`, which are copies the setup screen let somebody
 * edit freely - so renaming Phase 2 to something the stage no longer describes
 * changes what everybody reads and leaves this column alone.
 *
 * It exists because free text cannot be compared across projects. "How long
 * does Installation actually take on an Aircon job" is a question the reports
 * module should eventually be able to answer, and it cannot be answered by
 * grouping on a string that four different leads typed four different ways.
 *
 * Null on every phase somebody added by hand, and on every phase that existed
 * before templates did. Read for reporting; never for behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_project_phases', function (Blueprint $table): void {
            $table->unsignedBigInteger('stage_id')->nullable()->after('project_id');

            // Retiring a stage from the vocabulary must not take the record of
            // a phase with it, so the stamp is cleared and the phase stands.
            $table->foreign('stage_id')
                ->references('stage_id')
                ->on('tbl_phase_stages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_project_phases', function (Blueprint $table): void {
            $table->dropForeign(['stage_id']);
            $table->dropColumn('stage_id');
        });
    }
};
