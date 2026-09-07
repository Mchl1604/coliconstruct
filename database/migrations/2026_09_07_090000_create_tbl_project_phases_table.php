<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stages a project is monitored through.
 *
 * A project's phases are decided once, before any work is booked against them,
 * and then left alone - see the phase_setup_status column added to
 * tbl_projects alongside this table. That is the whole point of them: "2/4
 * Phases" is only a useful thing to read if the 4 cannot move, and until now
 * nothing in this system could tell a reader how far through a job was without
 * counting tasks, which change constantly.
 *
 * Deliberately NOT a catalogue shared between projects. Two jobs that both
 * have an "Installation" phase have two different installations, finished on
 * two different days by two different people, so the row belongs to the
 * project rather than being pointed at from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_project_phases', function (Blueprint $table): void {
            $table->id('phase_id');

            $table->unsignedBigInteger('project_id');

            // Phase 1, Phase 2, Phase 3 - the number a person reads, and the
            // order the monitoring interface draws them in. Stored rather than
            // derived from the row order because reordering during setup has
            // to survive a page reload, and because "Phase 3" is what a task
            // was assigned to.
            $table->unsignedSmallInteger('sequence');

            $table->string('title', 150);

            // Short by design: the setup screen asks for one sentence, and the
            // monitoring cards have one line to print it on.
            $table->string('description', 500);

            // When the phase was closed out, and by whom. Null on every phase
            // still to come - which is also how the current phase is found,
            // rather than by a status column that could drift out of step with
            // this one. See ProjectPhase::isCompleted().
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();

            // A Super Admin closed a phase with tasks still open, and said why.
            // The same shape the project-level completion override already
            // uses, for the same reason: the fact that a rule was waived is
            // worth more than the fact that a phase is closed.
            $table->text('completion_override_reason')->nullable();
            $table->unsignedBigInteger('completion_overridden_by')->nullable();

            $table->timestamps();

            $table->foreign('project_id')
                ->references('project_id')
                ->on('tbl_projects')
                ->cascadeOnDelete();

            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('completion_overridden_by')->references('id')->on('users')->nullOnDelete();

            // Two phases cannot both be Phase 2 of the same project. The
            // reorder in the setup screen rewrites every sequence in one
            // transaction, so this holds throughout.
            $table->unique(['project_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_project_phases');
    }
};
