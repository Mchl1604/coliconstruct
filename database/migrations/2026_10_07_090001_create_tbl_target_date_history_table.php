<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every value a project's target completion date has held.
 *
 * The date is edited in place on tbl_projects, so without this the date a
 * client was first promised is gone the moment somebody saves a new one. The
 * first row is the date the project was created with; every later row is a
 * change, and carries the reason it was made - which the client reads too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_target_date_history', function (Blueprint $table): void {
            $table->id('target_date_history_id');

            $table->foreignId('project_id')->constrained('tbl_projects', 'project_id')->cascadeOnDelete();

            // Null before means this is the date the project was created with.
            $table->date('previous_date')->nullable();
            $table->date('new_date');

            // Required for a change, absent for the creation entry.
            $table->string('reason', 255)->nullable();

            // Snapshotted beside the id, as the quotation history does, so the
            // entry keeps reading correctly after the account changes.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_role', 30)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_target_date_history');
    }
};
