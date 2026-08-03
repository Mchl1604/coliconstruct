<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the audit trail from User Management to the whole system.
 *
 * The existing columns are untouched, so every row already recorded still
 * reads. What is added is the context the Activity Logs page filters and sorts
 * on - which module an action belongs to, what role the actor held at the
 * time, and where they were working from - plus a loose pointer at whatever
 * record the action was about, since not every subject is a user account.
 *
 * Roles are snapshots for the same reason the names are: an entry has to keep
 * reading correctly after somebody is promoted or their account is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_activity_logs', function (Blueprint $table): void {
            $table->string('module', 50)->nullable()->after('action');
            $table->string('actor_role', 30)->nullable()->after('actor_name');
            $table->string('subject_role', 30)->nullable()->after('subject_name');

            // What the action was about when it was not a user account: a
            // project, a task, a report. Deliberately not a foreign key - an
            // audit row must never fail to write, nor vanish when the record
            // it describes is deleted.
            $table->string('record_type', 60)->nullable()->after('subject_role');
            $table->unsignedBigInteger('record_id')->nullable()->after('record_type');

            $table->string('browser', 60)->nullable()->after('ip_address');
            $table->string('operating_system', 60)->nullable()->after('browser');
            $table->text('user_agent')->nullable()->after('operating_system');
        });

        Schema::table('tbl_activity_logs', function (Blueprint $table): void {
            // The page reads newest-first and narrows by actor, role or
            // module; each index leads into created_at so the sort is covered
            // as well as the filter.
            $table->index(['actor_id', 'created_at'], 'activity_logs_actor_created_index');
            $table->index(['actor_role', 'created_at'], 'activity_logs_actor_role_created_index');
            $table->index(['module', 'created_at'], 'activity_logs_module_created_index');
            $table->index(['record_type', 'record_id'], 'activity_logs_record_index');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_activity_logs', function (Blueprint $table): void {
            $table->dropIndex('activity_logs_actor_created_index');
            $table->dropIndex('activity_logs_actor_role_created_index');
            $table->dropIndex('activity_logs_module_created_index');
            $table->dropIndex('activity_logs_record_index');

            $table->dropColumn([
                'module',
                'actor_role',
                'subject_role',
                'record_type',
                'record_id',
                'browser',
                'operating_system',
                'user_agent',
            ]);
        });
    }
};
