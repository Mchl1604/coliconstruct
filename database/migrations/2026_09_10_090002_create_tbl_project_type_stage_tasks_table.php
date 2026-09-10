<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The default tasks a project type contributes to one stage.
 *
 * Keyed by (type_id, stage_id) rather than by a row in tbl_project_type_stages,
 * because the two facts are separable and the pair reads better at every call
 * site: "what does Aircon do during Site Preparation" is one question with one
 * answer, and it does not need a join through a pivot's surrogate key to ask.
 *
 * This is where the per-type difference actually lives. Two types sharing a
 * stage share the heading and nothing else - Aircon's Site Preparation means
 * marking unit positions, Electrical's means checking panel capacity, and a
 * project that is both gets both under one heading.
 *
 * Neither a technician nor a date is stored here. A template says what the work
 * IS, not who does it or when - those belong to a particular project, and are
 * filled in on the phase setup screen where a real team and a real schedule
 * exist to choose from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_project_type_stage_tasks', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('stage_id');

            // The order this type lists its own tasks in. Unlike stage order
            // this one IS per type and causes no conflict: tasks from two types
            // are concatenated under a shared stage rather than interleaved,
            // so each type's list keeps its own internal order.
            $table->unsignedSmallInteger('sequence');

            $table->string('title', 255);
            $table->text('description');

            $table->timestamps();

            $table->foreign('type_id')
                ->references('type_id')
                ->on('tbl_project_types')
                ->cascadeOnDelete();

            $table->foreign('stage_id')
                ->references('stage_id')
                ->on('tbl_phase_stages')
                ->cascadeOnDelete();

            $table->index(['type_id', 'stage_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_project_type_stage_tasks');
    }
};
