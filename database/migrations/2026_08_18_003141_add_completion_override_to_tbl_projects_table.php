<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a project was closed out over the completion rules, and by whom.
 *
 * An administrator may complete a project a lead technician could not - work
 * finished on site with a task nobody ticked off, a job closed from the office
 * that was never scheduled - and that is deliberate. What it must not be is
 * silent: "the rules said no and somebody said yes anyway" is exactly the fact
 * an auditor comes looking for, and inferring it afterwards from the state of
 * the tasks is guesswork.
 *
 * Kept on the project rather than only in the activity trail because it
 * belongs to the completion report: the project page shows what was overridden
 * beside the summary, so anybody reading the record later sees it without
 * going to the logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            // The reason typed at the time. Null on every project completed
            // normally, which is what distinguishes the two.
            $table->text('completion_override_reason')->nullable()->after('completion_method');

            // What the rules objected to, as the sentences the dialog showed.
            // Stored rather than re-derived: the tasks it names may since have
            // been completed or deleted, and the record has to keep saying
            // what was true when the decision was made.
            $table->json('completion_override_blockers')->nullable()->after('completion_override_reason');

            $table->unsignedBigInteger('completion_overridden_by')->nullable()->after('completion_override_blockers');

            $table->foreign('completion_overridden_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->dropForeign(['completion_overridden_by']);
            $table->dropColumn([
                'completion_override_reason',
                'completion_override_blockers',
                'completion_overridden_by',
            ]);
        });
    }
};
