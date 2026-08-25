<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The completion reports a project has already been through.
 *
 * A project's CURRENT completion report has always lived on the project row
 * itself - completed_at, completion_summary, completion_remarks and the
 * photographs filed against it - and it still does. Every page, email and
 * report that reads a completion report reads it from there, and none of that
 * changes.
 *
 * What did not exist until now is anywhere to put the previous one. Reopening
 * a project used to leave last time's report sitting in those columns, so a
 * project that was live again went on showing a completion report as though
 * it were still finished. Clearing the columns instead would have destroyed a
 * record of a visit that really happened.
 *
 * So a reopen moves the report here rather than either keeping or deleting it:
 * one row per completion cycle, marked superseded, with the photographs of
 * that cycle re-pointed at it. The project's own completion columns are then
 * empty and the normal completion section correctly shows nothing, while the
 * history stays readable through View Previous Completion Reports.
 *
 * `cycle` numbers them in the order they happened - 1 is the first time the
 * project was closed out - so a project completed, reopened and completed
 * three times reads as three separate cycles rather than three undated
 * snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_project_completion_reports', function (Blueprint $table): void {
            $table->id('completion_report_id');
            $table->unsignedBigInteger('project_id');

            // Which completion this was, counted from one.
            $table->unsignedInteger('cycle')->default(1);

            // Always 'superseded' today: a report only arrives here because a
            // reopen replaced it. Stored rather than implied so a historical
            // report says what it is on its own row, and so a future lifecycle
            // has somewhere to live.
            $table->string('status')->default('superseded');

            // The report itself, exactly as it stood on the project.
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_summary')->nullable();
            $table->text('completion_remarks')->nullable();
            $table->string('completion_method')->nullable();

            // Who closed it out and who signed it off.
            $table->timestamp('completion_requested_at')->nullable();
            $table->unsignedBigInteger('completion_requested_by')->nullable();
            $table->timestamp('client_confirmed_at')->nullable();
            $table->unsignedBigInteger('client_confirmed_by')->nullable();

            // An override travels with the report it belongs to, or the record
            // of who signed off over the rules would be lost at the next
            // reopen.
            $table->text('completion_override_reason')->nullable();
            $table->json('completion_override_blockers')->nullable();
            $table->unsignedBigInteger('completion_overridden_by')->nullable();

            // What the project was when this report was superseded - Completed,
            // or still Awaiting Client Confirmation. The two are different
            // facts about how finished the work was.
            $table->string('project_status')->nullable();

            // The reopen that ended this cycle.
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by')->nullable();
            $table->text('supersede_reason')->nullable();

            $table->foreign('project_id')
                ->references('project_id')
                ->on('tbl_projects')
                ->cascadeOnDelete();

            // The project's own history, newest first, is the only way this
            // table is ever read.
            $table->index(['project_id', 'cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_project_completion_reports');
    }
};
