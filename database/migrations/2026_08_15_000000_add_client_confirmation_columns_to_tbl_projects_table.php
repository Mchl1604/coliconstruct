<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Completing a project no longer closes it outright: the company says the
     * work is done, and the client is given seven days to confirm it.
     *
     * The columns that already describe the work itself are reused rather than
     * duplicated. `completed_at` still means "the day the work finished" - it
     * is what decides which scheduled dates are released - and
     * completion_summary / completion_remarks / the completion photos are
     * untouched. What is new is the workflow around them: who asked, when the
     * clock started, how it ended, and what happened if an administrator
     * reopened the project instead.
     *
     * `status` is a plain string column with no constraint on it, exactly as
     * the Unscheduled rename found, so admitting a new status value needs no
     * migration of its own.
     */
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            // The request: who pressed Complete Project, and when. The seven
            // day clock and the day five reminder are both measured from
            // completion_requested_at, never from completed_at - the work may
            // have finished days before anybody recorded it.
            $table->timestamp('completion_requested_at')->nullable()->after('completion_remarks');
            $table->unsignedBigInteger('completion_requested_by')->nullable()->after('completion_requested_at');

            // Stamped when the day five reminder goes out, so a scheduler that
            // runs twice - or a run that is retried by hand - cannot send it
            // twice. Deliberately not a boolean: knowing when it was sent is
            // what makes a support conversation answerable.
            $table->timestamp('completion_reminder_sent_at')->nullable()->after('completion_requested_by');

            // The outcome. client_confirmed_at is when it became official,
            // which is a different fact from when the work finished.
            $table->timestamp('client_confirmed_at')->nullable()->after('completion_reminder_sent_at');
            $table->unsignedBigInteger('client_confirmed_by')->nullable()->after('client_confirmed_at');

            // 'client_confirmed' or 'auto_completed'. Null while a project has
            // never reached Completed.
            $table->string('completion_method', 32)->nullable()->after('client_confirmed_by');

            // The other way out of Awaiting Client Confirmation: an
            // administrator reopening the project onto a new schedule.
            $table->timestamp('reopened_at')->nullable()->after('completion_method');
            $table->unsignedBigInteger('reopened_by')->nullable()->after('reopened_at');
            $table->text('reopen_reason')->nullable()->after('reopened_by');

            // Matches archived_by: the trail survives the account being
            // removed, because the activity log carries the name snapshot.
            $table->foreign('completion_requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('client_confirmed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reopened_by')->references('id')->on('users')->nullOnDelete();

            // The scheduled run asks one question daily: which projects are
            // awaiting confirmation, and how long have they been waiting.
            $table->index(['status', 'completion_requested_at'], 'tbl_projects_awaiting_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->dropForeign(['completion_requested_by']);
            $table->dropForeign(['client_confirmed_by']);
            $table->dropForeign(['reopened_by']);
            $table->dropIndex('tbl_projects_awaiting_index');

            $table->dropColumn([
                'completion_requested_at',
                'completion_requested_by',
                'completion_reminder_sent_at',
                'client_confirmed_at',
                'client_confirmed_by',
                'completion_method',
                'reopened_at',
                'reopened_by',
                'reopen_reason',
            ]);
        });
    }
};
