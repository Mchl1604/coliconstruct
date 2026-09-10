<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks typed on the phase setup screen but not yet real.
 *
 * A separate table rather than early rows in tbl_tasks, because a project whose
 * phases are not finalized takes no tasks - TaskPhaseRules says so, the task
 * board would list them, and a technician would be notified about work on a
 * project that is still being drawn up. Writing them there early would mean
 * unpicking all three.
 *
 * It exists because Save Without Locking has to keep everything the person
 * typed, not just half of it. The phases have always been saved as real
 * unlocked rows; without somewhere for the tasks to go, a lead who saved at
 * five o'clock would come back to their phases and none of their work.
 *
 * The lifecycle is short and one-way: written by ProjectPhaseSetup::save(),
 * turned into real tasks by ProjectPhaseSetup::finalize(), and deleted in the
 * same transaction. Nothing reads them after a project is finalized, and a
 * phase deleted during setup takes its drafts with it - a draft is a note about
 * work, not a record of it.
 *
 * The technician and the two dates are nullable here for the same reason they
 * are nullable on a real task: the setup screen asks for them and does not
 * insist, so a task can be finalized as unassigned and picked up on the task
 * board later, where the Missing Technician & Date chips already point at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_project_phase_draft_tasks', function (Blueprint $table): void {
            $table->id('draft_task_id');

            $table->unsignedBigInteger('phase_id');

            // The order the tasks were listed in under their phase, kept so a
            // reopened setup screen looks like the one that was left.
            $table->unsignedSmallInteger('sequence');

            $table->string('title', 255);
            $table->text('description');

            $table->unsignedBigInteger('technician_id')->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();

            $table->timestamps();

            // Unlike a real task, a draft goes when its phase goes. Nothing has
            // been recorded against it and nobody has been told about it.
            $table->foreign('phase_id')
                ->references('phase_id')
                ->on('tbl_project_phases')
                ->cascadeOnDelete();

            // A technician removed from the system leaves the draft behind
            // unassigned, which is a state the screen already draws.
            $table->foreign('technician_id')
                ->references('technician_id')
                ->on('tbl_technicians')
                ->nullOnDelete();

            $table->index(['phase_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_project_phase_draft_tasks');
    }
};
