<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which stages a project type uses.
 *
 * Deliberately just the pair. There is no `sequence` column, and leaving it
 * out is the decision that makes a multi-type project work at all: order comes
 * from tbl_phase_stages.sort_order, so an Aircon + Electrical project has one
 * ordering rather than two incomparable ones to reconcile.
 *
 * A type with no rows here contributes nothing to a project's suggested
 * structure, which is the correct behaviour for a type nobody has written a
 * template for yet - not an error, and not a reason to fall back to some other
 * type's stages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_project_type_stages', function (Blueprint $table): void {
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('stage_id');

            $table->primary(['type_id', 'stage_id']);

            $table->foreign('type_id')
                ->references('type_id')
                ->on('tbl_project_types')
                ->cascadeOnDelete();

            // Removing a stage from the vocabulary removes it from every
            // type's template. Safe in a way the same cascade would not be on a
            // project's phases, because nothing here is a record of work - it
            // is a suggestion for work not yet set up.
            $table->foreign('stage_id')
                ->references('stage_id')
                ->on('tbl_phase_stages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_project_type_stages');
    }
};
