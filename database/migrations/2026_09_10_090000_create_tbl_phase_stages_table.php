<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's vocabulary of project stages.
 *
 * The one thing in this feature that IS a shared catalogue, and it is shared
 * for a reason that only shows up when a project has more than one type.
 *
 * A project can be Aircon Installation and Electrical Works at once. Both of
 * those types have a "Site Preparation" and both have their own default tasks
 * for it, and what the person setting the project up wants to see is one Site
 * Preparation phase carrying both lists - not two phases with the same name.
 * Matching them by title would make that merge depend on two Super Admins
 * having typed the same string months apart; matching them by stage_id makes
 * it exact.
 *
 * The second thing it settles is order. Two types will disagree about whether
 * Testing comes before Final Inspection, and their two opinions are not
 * comparable - each numbers its own stages from one. `sort_order` here is the
 * single answer, so a merged structure comes out in a defensible order no
 * matter which types went into it. A type that genuinely needs a different
 * order needs a stage of its own rather than a sequence of its own.
 *
 * Note what this table is NOT. It is not where a project's phases live - those
 * are still per-project rows in tbl_project_phases, copied from here and freely
 * editable afterwards. Nothing points from a project back to a stage in a way
 * that would let an edit here reach a project that has already been set up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_phase_stages', function (Blueprint $table): void {
            $table->id('stage_id');

            $table->string('name', 150);

            // What the phase description is prefilled with. Lives on the stage
            // rather than on each type's use of it, so a stage two types both
            // contribute to has exactly one description and there is nothing
            // to reconcile at merge time.
            $table->string('default_description', 500);

            // Where this stage falls in a project's natural order. Written in
            // tens so a stage can be slotted between two others without
            // renumbering the list.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Two stages cannot share a name: the whole point of the
            // vocabulary is that "Installation" means one thing.
            $table->unique('name');

            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_phase_stages');
    }
};
